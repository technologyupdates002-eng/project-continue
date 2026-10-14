<?php

namespace App\Query;

use Illuminate\Database\Query\Builder;

/**
 * REST API-compatible query string parser.
 * Parses query parameters (?status=eq.paid,
 * ?select=..., ?order=..., ?limit=, ?offset=, ?count=exact, ?or=(...)) and
 * applies them to a Laravel query builder.
 */
class RestQueryBuilder
{
    public const MAX_LIMIT = 5000;
    public const DEFAULT_LIMIT = 1000;

    /** @var string[] */
    public array $columns = ['*'];

    /** @var array<int,array{sql:string,bindings:array}> */
    public array $wheres = [];

    /** @var array<int,array{column:string,values:array}> */
    public array $ins = [];

    /** @var array<int,array{column:string,null:bool}> */
    public array $nulls = [];

    /** @var array<int,array{column:string,direction:string}> */
    public array $orders = [];

    /** @var array<int,array{closure:callable}> */
    public array $orGroups = [];

    public int $limit = self::DEFAULT_LIMIT;
    public int $offset = 0;
    public bool $count = false;

    /**
     * @param array<string,mixed> $params Raw query parameters (GET/POST).
     */
    public function __construct(array $params = [])
    {
        $this->parse($params);
    }

    public static function fromArray(array $params): self
    {
        return new self($params);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function parse(array $params): self
    {
        foreach ($params as $key => $raw) {
            $value = is_array($raw) ? ($raw[0] ?? '') : $raw;
            $value = (string) $value;

            switch ($key) {
                case 'select':
                    $this->columns = $this->parseSelect($value);
                    break;
                case 'order':
                    $this->parseOrder($value);
                    break;
                case 'limit':
                    $this->limit = min((int) $value, self::MAX_LIMIT) ?: self::DEFAULT_LIMIT;
                    break;
                case 'offset':
                    $this->offset = max((int) $value, 0);
                    break;
                case 'count':
                    if ($value === 'exact') {
                        $this->count = true;
                    }
                    break;
                case 'or':
                    $this->parseOrGroup($value);
                    break;
                default:
                    $this->parseColumnFilter($key, $value);
            }
        }

        return $this;
    }

    protected function parseOrder(string $value): void
    {
        foreach (explode(',', $value) as $part) {
            $segments = explode('.', $part);
            $column = $segments[0];
            $direction = 'asc';
            if (isset($segments[1]) && in_array($segments[1], ['desc', 'asc'], true)) {
                $direction = $segments[1];
            }
            $this->orders[] = ['column' => $column, 'direction' => $direction];
        }
    }

    protected function parseOrGroup(string $value): void
    {
        $inner = trim($value, '()');
        $parts = str_getcsv($inner, ',', escape: '\\');
        $conditions = [];
        foreach ($parts as $part) {
            if (! str_contains($part, '.')) {
                continue;
            }
            [$column, $opVal] = explode('.', $part, 2);
            [$op, $val] = $this->splitOperatorValue($opVal);
            $conditions[] = [$column, $op, $val];
        }

        $this->orGroups[] = ['conditions' => $conditions];
    }

    protected function parseColumnFilter(string $column, string $value): void
    {
        if (! str_contains($value, '.')) {
            $this->addCondition($column, 'eq', $value);

            return;
        }
        [$op, $val] = $this->splitOperatorValue($value);
        $this->addCondition($column, $op, $val);
    }

    protected function splitOperatorValue(string $value): array
    {
        $pos = strpos($value, '.');
        $op = substr($value, 0, $pos);
        $val = substr($value, $pos + 1);

        return [$op, $val];
    }

    protected function addCondition(string $column, string $op, string $value): void
    {
        $op = strtolower($op);

        if ($op === 'is') {
            $this->nulls[] = ['column' => $column, 'null' => $value !== 'not.null'];

            return;
        }

        if ($op === 'in') {
            $value = trim($value, '()');
            $this->ins[] = ['column' => $column, 'values' => explode(',', $value)];

            return;
        }

        $normalizedOp = $op;
        $map = [
            'eq' => '=',
            'neq' => '<>',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'LIKE',
            'ilike' => 'LIKE',
            'not' => '<>',
        ];

        if (! isset($map[$normalizedOp])) {
            $normalizedOp = 'eq';
        }

        $bound = $value;
        if ($normalizedOp === 'like' || $normalizedOp === 'ilike') {
            $bound = str_replace('*', '%', $value);
        } elseif ($bound === 'true' || $bound === 'false') {
            // Convert boolean strings to integer for MySQL compatibility
            $bound = $bound === 'true' ? 1 : 0;
        } elseif ($bound === 'null') {
            // null as a string should be treated as SQL NULL (use IS NULL instead)
            $this->nulls[] = ['column' => $column, 'null' => true];
            return;
        } elseif (is_numeric($bound) && $op === 'eq') {
            // Attempt numeric conversion for cleaner queries
            $bound = (int) $bound;
        }

        $this->wheres[] = [
            'sql' => "{$column} {$map[$normalizedOp]} ?",
            'bindings' => [$bound],
        ];
    }

    /**
     * Apply parsed conditions to a Laravel query builder.
     */
    /**
     * Parse a Supabase-style select parameter, handling nested parentheses
     * for foreign-table selects like withdrawal_signatories(id,status,...).
     */
    protected function parseSelect(string $value): array
    {
        $columns = [];
        $depth = 0;
        $current = '';

        foreach (str_split($value) as $char) {
            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $column = trim($current);
                if ($column !== '' && !str_contains($column, '(') && !str_contains($column, ')')) {
                    $columns[] = $column;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $column = trim($current);
        if ($column !== '' && !str_contains($column, '(') && !str_contains($column, ')')) {
            $columns[] = $column;
        }

        return array_values($columns);
    }

    public function apply(Builder $query): Builder
    {
        foreach ($this->wheres as $where) {
            $query->whereRaw($where['sql'], $where['bindings']);
        }

        foreach ($this->ins as $in) {
            $query->whereIn($in['column'], $in['values']);
        }

        foreach ($this->nulls as $null) {
            if ($null['null']) {
                $query->whereNull($null['column']);
            } else {
                $query->whereNotNull($null['column']);
            }
        }

        foreach ($this->orGroups as $group) {
            $conditions = $group['conditions'];
            $query->where(function (Builder $q) use ($conditions): void {
                $first = true;
                foreach ($conditions as [$column, $op, $value]) {
                    $sql = "{$column} = ?";
                    $bind = [$value];
                    if ($op === 'like' || $op === 'ilike') {
                        $sql = "{$column} LIKE ?";
                        $bind = [str_replace('*', '%', $value)];
                    } elseif ($op === 'neq') {
                        $sql = "{$column} <> ?";
                    } elseif ($op === 'gt') {
                        $sql = "{$column} > ?";
                    } elseif ($op === 'lt') {
                        $sql = "{$column} < ?";
                    } elseif ($op === 'gte') {
                        $sql = "{$column} >= ?";
                    } elseif ($op === 'lte') {
                        $sql = "{$column} <= ?";
                    }
                    if ($first) {
                        $q->whereRaw($sql, $bind);
                        $first = false;
                    } else {
                        $q->orWhereRaw($sql, $bind);
                    }
                }
            });
        }

        foreach ($this->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        if ($this->offset > 0) {
            $query->offset($this->offset);
        }

        $query->limit($this->limit);

        return $query;
    }
}
