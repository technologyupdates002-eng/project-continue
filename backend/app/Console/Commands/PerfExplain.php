<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PerfExplain extends Command
{
    protected $signature = 'perf:explain {--file=storage/logs/request-profile.log} {--top=20}';
    protected $description = 'Run EXPLAIN on slow queries found in request_profile logs and suggest indexes';

    public function handle()
    {
        $path = base_path($this->option('file'));
        if (!file_exists($path)) {
            $this->error('Profile log not found: ' . $path);
            return 1;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $sqls = [];
        foreach ($lines as $line) {
            $p = strpos($line, '{');
            if ($p === false) continue;
            $json = substr($line, $p);
            $decoded = json_decode($json, true);
            if (!$decoded) continue;
            if (!empty($decoded['queries']) && is_array($decoded['queries'])) {
                foreach ($decoded['queries'] as $q) {
                    $sql = $q['sql'] ?? null;
                    $time = $q['time_ms'] ?? ($q['time'] ?? null);
                    if ($sql && is_string($sql)) {
                        $key = trim(preg_replace('/\s+/', ' ', $sql));
                        // only analyze SELECTs by default
                        if (preg_match('/^\s*(select|SELECT)\b/', $key)) {
                            $sqls[$key] = max($sqls[$key] ?? 0, $time ?: 0);
                        }
                    }
                }
            }
        }

        if (empty($sqls)) {
            $this->info('No SELECT SQLs found in profile logs to EXPLAIN.');
            return 0;
        }

        // pick top N by time
        arsort($sqls);
        $top = (int)$this->option('top');
        $selected = array_slice($sqls, 0, $top, true);

        $reports = [];
        foreach ($selected as $sql => $time) {
            $this->info('Explaining: ' . substr($sql, 0, 200));
            try {
                $explain = DB::select('EXPLAIN ' . $sql);
            } catch (\Throwable $e) {
                $reports[] = ['sql' => $sql, 'error' => $e->getMessage()];
                continue;
            }
            $suggestions = $this->suggestIndexesFromExplain($explain, $sql);
            $reports[] = ['sql' => $sql, 'avg_time_ms' => $time, 'explain' => $explain, 'suggestions' => $suggestions];
        }

        $outPath = storage_path('logs/perf-explain-' . time() . '.json');
        file_put_contents($outPath, json_encode(['generated_at' => now()->toDateTimeString(), 'reports' => $reports], JSON_PRETTY_PRINT));
        $this->info('Wrote explain report to ' . $outPath);
        return 0;
    }

    private function suggestIndexesFromExplain(array $explainRows, string $sql)
    {
        $suggest = [];
        // crude heuristic: if any row has type ALL or possible_keys null, recommend indexing columns in WHERE/ORDER BY
        $needIndex = false;
        foreach ($explainRows as $r) {
            $type = $r->type ?? ($r['type'] ?? null);
            $possible = $r->possible_keys ?? ($r['possible_keys'] ?? null);
            if ($type === 'ALL' || empty($possible)) {
                $needIndex = true;
                break;
            }
        }

        if ($needIndex) {
            // try to parse column names from simple WHERE / ORDER BY clauses
            $cols = [];
            if (preg_match('/WHERE\s+([^;]+)/i', $sql, $m)) {
                $where = $m[1];
                // find occurrences of `col` = or col = or col LIKE
                if (preg_match_all('/`?(\w+)`?\s*(?:=|LIKE|IN|>=|<=|>|<)/i', $where, $colsMatch)) {
                    foreach ($colsMatch[1] as $c) $cols[] = $c;
                }
            }
            if (preg_match('/ORDER\s+BY\s+([^;]+)/i', $sql, $m2)) {
                if (preg_match_all('/`?(\w+)`?/i', $m2[1], $oMatch)) {
                    foreach ($oMatch[1] as $c) $cols[] = $c;
                }
            }
            $cols = array_values(array_unique($cols));
            if ($cols) {
                $suggest[] = 'Consider adding an index on columns: ' . implode(', ', $cols);
            } else {
                $suggest[] = 'Query full scan detected; consider adding appropriate index(es) for WHERE/JOIN/ORDER BY columns.';
            }
        }

        return $suggest;
    }
}
