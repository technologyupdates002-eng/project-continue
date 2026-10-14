<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Temporary proxy for Supabase-style REST API calls.
 * Maps /rest/v1/* paths to Laravel models and database queries.
 *
 * This allows the frontend (built for Supabase) to work with a local Laravel backend.
 * In production, replace with a real Supabase instance or full Laravel API layer.
 */
class RestApiProxyController extends Controller
{
    public function handle(Request $request, string $table): JsonResponse
    {
        // Only handle GET requests for now
        if ($request->method() !== 'GET') {
            return response()->json(['error' => 'Method not allowed'], 405);
        }

        $filters = [];
        $limit = 100;
        $offset = 0;
        $order = null;
        $select = null;

        foreach ($request->query() as $key => $value) {
            if ($key === 'limit') {
                $limit = min((int) $value, 1000);
            } elseif ($key === 'offset') {
                $offset = (int) $value;
            } elseif ($key === 'order') {
                $order = $value;
            } elseif ($key === 'select') {
                $select = explode(',', $value);
            } else {
                // Parse filter: field=eq.value or field=ilike.*pattern*
                $filters[$key] = $value;
            }
        }

        $query = DB::table($table);

        // Apply filters
        foreach ($filters as $field => $condition) {
            if (is_string($condition) && strpos($condition, '.') !== false) {
                [$op, $val] = explode('.', $condition, 2);
                match ($op) {
                    'eq' => $query->where($field, $val),
                    'gt' => $query->where($field, '>', $val),
                    'lt' => $query->where($field, '<', $val),
                    'gte' => $query->where($field, '>=', $val),
                    'lte' => $query->where($field, '<=', $val),
                    'neq' => $query->whereNot($field, $val),
                    'like', 'ilike' => $query->where($field, 'like', str_replace('*', '%', $val)),
                    default => null,
                };
            }
        }

        // Apply ordering
        if ($order) {
            foreach (explode(',', $order) as $orderClause) {
                $desc = str_ends_with($orderClause, '.desc');
                $field = str_replace(['.desc', '.asc'], '', $orderClause);
                $query->orderBy($field, $desc ? 'desc' : 'asc');
            }
        }

        // Count before limit
        $total = (clone $query)->count();

        // Apply pagination
        if ($offset > 0) {
            $query->offset($offset);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        // Select specific columns if requested
        if ($select && !empty($select)) {
            $query->select($select);
        }

        $rows = $query->get();

        // Return as array of objects
        return response()->json($rows->map(fn ($row) => (array) $row)->all());
    }
}
