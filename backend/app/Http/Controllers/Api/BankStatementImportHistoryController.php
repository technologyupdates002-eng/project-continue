<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemberStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankStatementImportHistoryController extends Controller
{
    private const STATUSES = ['new_member', 'matched', 'unmatched', 'skipped', 'failed', 'pending'];

    public function __construct(private readonly MemberStatsService $memberStats) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('member_import_rows as r')
            ->join('member_import_batches as b', 'b.id', '=', 'r.batch_id')
            ->leftJoin('members as m', 'm.id', '=', 'r.member_id_ref')
            ->select([
                'r.id',
                'r.batch_id',
                'r.row_number',
                'r.trans_date',
                'r.reference_no',
                'r.credit',
                'r.debit',
                'r.member_id_ref',
                DB::raw('COALESCE(m.name, r.member_name, r.extracted_name) as member_name'),
                DB::raw('COALESCE(m.phone, r.phone, r.normalized_phone) as member_phone'),
                'm.status as member_status',
                'r.status',
                'r.match_status',
                'r.message',
                'r.contribution_amount',
                'r.contribution_month',
                'r.contribution_year',
                'r.contribution_status',
                'r.contribution_paid_date',
                'r.auth_user_created',
                'r.member_dashboard_created',
                'r.created_at as imported_at',
                'b.file_name',
                'b.source_name',
                'b.status as batch_status',
            ])
            ->when(isset($validated['status']), fn ($builder) => $builder->where('r.status', $validated['status']))
            ->when(isset($validated['q']), function ($builder) use ($validated) {
                $needle = '%'.addcslashes(trim($validated['q']), '\\%_').'%';
                $builder->where(function ($search) use ($needle) {
                    $search->where('m.name', 'like', $needle)
                        ->orWhere('r.member_name', 'like', $needle)
                        ->orWhere('r.extracted_name', 'like', $needle)
                        ->orWhere('m.phone', 'like', $needle)
                        ->orWhere('r.phone', 'like', $needle)
                        ->orWhere('r.normalized_phone', 'like', $needle)
                        ->orWhere('r.reference_no', 'like', $needle)
                        ->orWhere('b.file_name', 'like', $needle);
                });
            })
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.row_number');

        $page = $query->paginate(
            $validated['per_page'] ?? 50,
            ['*'],
            'page',
            $validated['page'] ?? null,
        );
        $memberSummaries = [];
        $rows = array_map(function ($row) use (&$memberSummaries) {
            $memberId = $row->member_id_ref;
            if ($memberId && ! array_key_exists($memberId, $memberSummaries)) {
                $memberSummaries[$memberId] = $this->memberStats->getStats($memberId);
            }

            $summary = $memberId ? ($memberSummaries[$memberId] ?? []) : [];
            $paid = (float) ($summary['total_contributions'] ?? 0);
            $outstanding = (float) ($summary['contribution_outstanding'] ?? 0);
            $row->dashboard_paid_total = $paid;
            $row->dashboard_outstanding = $outstanding;
            $row->dashboard_status = ! $memberId || $summary === []
                ? 'unavailable'
                : ($outstanding > 0 ? 'balance_due' : ($paid > 0 ? 'up_to_date' : 'no_contributions'));

            return $row;
        }, $page->items());

        return response()->json([
            'data' => [
                'rows' => $rows,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        ]);
    }
}
