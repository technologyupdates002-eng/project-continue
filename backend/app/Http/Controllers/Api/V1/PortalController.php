<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Support\Uuid;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for the v1 portal controllers (member, treasurer, admin,
 * officers, super admin). Every portal endpoint resolves identity from the
 * JWT middleware, checks roles server-side and returns plain JSON shapes the
 * React frontend consumes (src/api/*.ts).
 */
abstract class PortalController extends Controller
{
    protected const STAFF_ROLES = ['admin', 'super_admin', 'treasurer', 'chairperson', 'vice_chairperson', 'secretary', 'vice_secretary', 'office', 'patron'];

    protected function identity(Request $request): array
    {
        $identity = $request->attributes->get('identity');
        if (! is_array($identity) || empty($identity['user_id'])) {
            throw new HttpException(401, 'invalid_token', 'Authentication required.');
        }

        return $identity;
    }

    /** @param list<string> $roles */
    protected function requireAnyRole(Request $request, array $roles): array
    {
        $identity = $this->identity($request);
        $held = array_map('strval', (array) ($identity['roles'] ?? []));
        if (in_array('super_admin', $held, true) || array_intersect($roles, $held)) {
            return $identity;
        }
        throw new HttpException(403, 'forbidden', 'You do not have permission for this action.');
    }

    protected function memberId(Request $request): string
    {
        $identity = $this->identity($request);
        $memberId = $identity['member_id'] ?? null;
        if (! $memberId) {
            $memberId = DB::table('members')->where('user_id', $identity['user_id'])->value('id');
        }
        if (! $memberId) {
            throw new HttpException(404, 'member_not_found', 'No member profile is linked to this account.');
        }

        return (string) $memberId;
    }

    /** Laravel paginator -> frontend Paginated<T> shape. */
    protected function paginate(Builder $query, Request $request, int $perPage = 25): array
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', $perPage)));
        $page = max(1, (int) $request->query('page', 1));
        $p = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'per_page' => $p->perPage(),
            'total' => $p->total(),
        ];
    }

    protected function uuid(): string
    {
        return Uuid::v4();
    }

    /**
     * Short-lived dashboard cache. Keys embed a global version number that
     * observers bump on any financial write, so balances refresh instantly
     * after a payment while repeated dashboard loads stay fast.
     */
    protected function cached(string $key, \Closure $fn, int $seconds = 60): mixed
    {
        $version = (int) Cache::get('khwwc:data_version', 1);

        return Cache::remember("v1:{$version}:{$key}", $seconds, $fn);
    }

    protected function ok(string $message, array $extra = []): array
    {
        return ['message' => $message] + $extra;
    }

    protected function audit(Request $request, string $action, array $details = [], ?string $memberId = null): void
    {
        try {
            DB::table('audit_logs')->insert([
                'id' => $this->uuid(),
                'super_admin_id' => $this->identity($request)['user_id'],
                'action' => $action,
                'target_member_id' => $memberId,
                'details' => json_encode($details),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Auditing must never block the business action.
        }
    }

    protected function walletBalances(): array
    {
        $row = fn (string $t) => DB::table($t)->first();
        $p = $row('penalty_wallet');
        $d = $row('donation_wallet');
        $o = $row('operational_wallet');

        return [
            'penalty' => (float) ($p->total_balance ?? 0),
            'donation' => (float) ($d->total_balance ?? 0),
            'operational' => (float) ($o->total_balance ?? 0),
            'contributions' => (float) DB::table('contributions')->where('status', 'paid')->sum('amount'),
        ];
    }

    protected function monthlyTrend(int $months = 12): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1);
        $rows = DB::table('contributions')
            ->where('status', 'paid')
            ->whereRaw('(year * 100 + month) >= ?', [(int) $from->format('Y') * 100 + (int) $from->format('n')])
            ->selectRaw('year, month, SUM(amount) as total')
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn ($r) => $r->year.'-'.$r->month);

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $d = $from->copy()->addMonths($i);
            $k = $d->format('Y').'-'.$d->format('n');
            $out[] = ['month' => $d->format('M Y'), 'amount' => (float) ($rows[$k]->total ?? 0)];
        }

        return $out;
    }

    /** Stream rows as CSV (Excel-friendly) for report/statement downloads. */
    protected function csv(string $filename, array $header, iterable $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, array_values((array) $r));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
