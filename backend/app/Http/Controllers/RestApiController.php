<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpException;
use App\Query\RestQueryBuilder;
use App\Support\ViewAsMemberContext;
use App\Services\Cache\CacheService;
use App\Services\PolicyService;
use App\Services\WithdrawalApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Support\Uuid;

class RestApiController extends Controller
    {
        /**
         * Member-scoped read columns. When view-as is active, the client's
         * member_id/user_id query parameters are overridden with the viewed
         * member's identity to prevent cross-member data access.
         */
        protected const MEMBER_SCOPED_COLUMNS = [
            'member_id',
            'user_id',
            'contributor_id',
            'owner_id',
            'created_by',
            'updated_by',
        ];

        /**
         * Virtual table map: route table name → actual database table name.
         * Tables not listed here are used as-is.
         */
        protected const VIRTUAL_TABLE_MAP = [
            'registrations' => 'member_registrations',
        ];

        protected const WALLET_TABLES = [
            'penalty_wallet',
            'donation_wallet',
            'operational_wallet',
        ];

        protected const CANONICAL_FINANCIAL_TABLES = [
            'payments',
            'contributions',
            'monthly_obligations',
            'contribution_allocations',
            'wallet_transactions',
            'penalties',
            'penalty_payment_records',
            'financial_ledger_entries',
        ];

        /**
         * Tables whose rows are owned by a member and must be re-scoped to the
         * viewed member when view-as is active.
         */
        protected const MEMBER_OWNED_TABLES = [
            'members',
            'contributions',
            'penalties',
            'payments',
            'beneficiaries',
            'beneficiary_requests',
            'wallet_transactions',
            'documents',
            'memo_recipients',
            'member_executive_roles',
            'member_access_logs',
        ];

        /**
         * Enforce view-as-member context on a REST request.
         *
         * - In READ mode: override any client-supplied member_id/user_id query
         *   parameters with the viewed member's identity.
         * - In WRITE mode: block the request with 403 VIEW_MODE_READ_ONLY.
         */
        protected function enforceViewAsMember(Request $request, string $actualTable, string $method): void
        {
            $ctx = ViewAsMemberContext::from($request);

            if (! $ctx->isActive()) {
                return;
            }

            if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                throw new HttpException(403, 'view_mode_read_only', 'View Member mode is read-only. Mutations are not permitted while viewing a member.');
            }

            if ($method !== 'GET') {
                return;
            }

            // Only re-scope member-owned tables.
            if (! in_array($actualTable, self::MEMBER_OWNED_TABLES, true)) {
                return;
            }

            $viewed = $ctx->viewedMember();
            if ($viewed === null) {
                return;
            }

            $query = $request->query;
            $forced = [];

            // Override member_id and user_id query parameters with the viewed
            // member's identity. This prevents a client from switching the
            // target member by changing query parameters.
            foreach (self::MEMBER_SCOPED_COLUMNS as $column) {
                if ($query->has($column)) {
                    $query->set($column, $viewed[$column] ?? $viewed['id']);
                    $forced[$column] = $viewed[$column] ?? $viewed['id'];
                }
            }

            // Also override the common eq.* filter form used by the frontend.
            if ($query->has('id') && $query->has('id')) {
                // leave id alone for direct PK lookups; only override member-scoped filters
            }

            // notifications are keyed by user_id; ensure the viewed member's
            // user_id is used when the client requests notifications.
            if ($actualTable === 'notifications' && $query->has('user_id')) {
                $query->set('user_id', $viewed['user_id']);
                $forced['user_id'] = $viewed['user_id'];
            }

            $request->attributes->set('view_as_member_forced_filters', $forced);
        }

    private static array $columnCache = [];

    private static function getAllowedColumns(string $table): array
    {
        if (isset(self::$columnCache[$table])) {
            return self::$columnCache[$table];
        }

        $cols = Schema::getColumnListing($table);
        self::$columnCache[$table] = $cols;

        return $cols;
    }

    protected function normalizeNumericStrings(array $rows): array
    {
        return array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }

            foreach ($row as $key => $value) {
                if (is_string($value) && is_numeric($value)) {
                    $row[$key] = $this->castNumericString($value);
                } elseif (is_array($value)) {
                    $row[$key] = $this->normalizeNumericStrings([$value])[0] ?? $value;
                }
            }

            return $row;
        }, $rows);
    }

    protected function castNumericString(string $value): int|float|string
    {
        if (str_contains($value, '.')) {
            return (float) $value;
        }

        return (int) $value;
    }

    public function publicSettings()
    {
        $rows = Cache::store('redis')->remember('welfare-settings', CacheService::TTL_LONG, function () {
            return DB::table('welfare_settings')->get()->map(fn ($row) => (array) $row)->all();
        });

        return response()->json($this->normalizeNumericStrings($rows));
    }

    public function handle(Request $request, string $table)
    {
        $table = str_replace('-', '_', $table);

        if (! PolicyService::tableExists($table)) {
            throw new HttpException(403, 'table_not_allowed', 'Table not allowed.');
        }

        // Virtual aggregated table 'withdrawals' — no single backing table.
        // GET aggregates across all withdrawal types; POST creates a
        // penalty withdrawal (used by the PenaltyWallet page).
        if ($table === 'withdrawals') {
            $method = strtoupper($request->method());

            return match ($method) {
                'GET'  => $this->readWithdrawals($request),
                'POST' => $this->createWithdrawal($request),
                default => throw new HttpException(405, 'method_not_allowed', 'Unsupported method ' . $method),
            };
        }

        // Resolve virtual table names to actual database table names.
        // 'registrations' → 'member_registrations' (same PolicyService matrix entry).
        $actualTable = self::VIRTUAL_TABLE_MAP[$table] ?? $table;

        // PolicyService::tableExists() already validates the route is allowed.
        // Allowed tables are defined in PolicyService::TABLES and are created
        // by migrations, so an extra Schema::hasTable() check on every request
        // only adds an information_schema query without meaningful safety.

        $identity = $request->attributes->get('identity');

        $method = strtoupper($request->method());

        $this->enforceViewAsMember($request, $actualTable, $method);

        if (in_array($actualTable, self::WALLET_TABLES) && $method !== 'GET') {
            throw new HttpException(403, 'wallet_immutable', 'Wallet tables cannot be modified via REST API. Use WalletService.');
        }
        if (in_array($actualTable, self::CANONICAL_FINANCIAL_TABLES, true) && $method !== 'GET') {
            throw new HttpException(403, 'financial_mutation_blocked', 'Financial records must be changed through the canonical financial services.');
        }
        $query = RestQueryBuilder::fromArray($request->query->all());
        $builder = DB::table($actualTable);

        // Older cached clients may still send filters for columns removed from
        // the active schema. Ignore those compatibility-only search terms
        // rather than allowing them to produce a database error.
        if ($actualTable === 'members' && $query->orGroups !== []) {
            $memberColumns = array_flip(self::getAllowedColumns('members'));
            $query->orGroups = array_values(array_filter(array_map(
                static function (array $group) use ($memberColumns): array {
                    $group['conditions'] = array_values(array_filter(
                        $group['conditions'],
                        static fn (array $condition): bool => isset($memberColumns[$condition[0]])
                    ));

                    return $group;
                },
                $query->orGroups
            ), static fn (array $group): bool => $group['conditions'] !== []));
        }

        if ($actualTable === 'user_roles' && $query->orders === []) {
            $builder->orderByRaw("FIELD(role, 'super_admin', 'admin', 'chairperson', 'vice_chairperson', 'secretary', 'vice_secretary', 'treasurer', 'patron', 'member')");
        }

        if ($method === 'GET' && $actualTable === 'contributions') {
            $builder->where('amount', '>', 0);
        }

        if ($query->columns !== ['*']) {
            $builder->select($query->columns);
        }

        $ctx = ViewAsMemberContext::from($request);

        if (! PolicyService::canReadAll($table, $identity['roles']) || $ctx->isActive()) {
            $scope = PolicyService::readScope($table, $identity);
            if ($scope !== null) {
                $builder->where($scope['column'], $scope['value']);
            } else {
                $builder->whereRaw('0 = 1');
            }
        }

        $builder = $query->apply($builder);

        return match ($method) {
            'GET' => $this->read($request, $builder, $query),
            'POST' => $this->create($request, $actualTable, $identity),
            'PATCH', 'PUT' => $this->update($request, $actualTable, $builder, $identity),
            'DELETE' => $this->delete($request, $actualTable, $builder, $identity),
            default => throw new HttpException(405, 'method_not_allowed', 'Unsupported method ' . $method),
        };
    }

    protected function read(Request $request, $builder, RestQueryBuilder $query)
    {
        if ($request->query('count') === 'exact') {
            return response()->json(['count' => (int) $builder->count()]);
        }

        $rows = $builder->get()->map(fn ($row) => (array) $row)->all();

        return response()->json($this->normalizeNumericStrings($rows));
    }

    protected function create(Request $request, string $table, array $identity)
    {
        if (in_array($table, [
            'withdrawal_signatories',
            'donation_withdrawal_signatories',
            'operational_withdrawal_signatories',
        ], true)) {
            throw new HttpException(409, 'canonical_approval_endpoint_required', 'Signatory rows are created with the withdrawal and cannot be inserted through the generic API.');
        }

        $payload = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();
        $rows = is_array($payload) && array_is_list($payload) ? $payload : [$payload];

        if ($rows === [] || ! is_array($rows[0])) {
            throw new HttpException(400, 'empty_payload', 'No rows to insert');
        }

        if (! PolicyService::publicInsert($table)) {
            foreach ($rows as $row) {
                PolicyService::assertWrite($table, $identity, $row);
            }
        }

        $out = [];
        $allowedCols = self::getAllowedColumns($table);

        foreach ($rows as $row) {
            // keep only actual table columns to avoid unknown column errors
            $row = array_intersect_key($row, array_flip($allowedCols));

            if ($row === []) continue;

            if (in_array($table, ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'], true)) {
                $row['status'] = 'pending';
                $row['approval_stage'] = 'secretary';
                $row['requested_by'] = $identity['user_id'] ?? null;
                foreach (['bank_transfer_id', 'b2c_reference', 'mpesa_receipt', 'receipt_url', 'submitted_at', 'completed_at', 'failed_at'] as $serverOwnedColumn) {
                    unset($row[$serverOwnedColumn]);
                }
            }

            if ($table === 'expenses') {
                $paymentMethod = strtolower($row['payment_method'] ?? '');
                $hasWithdrawal = !empty($row['withdrawal_id']) && !empty($row['withdrawal_table']);
                $status = strtolower($row['status'] ?? '');

                if (!$hasWithdrawal && in_array($status, ['completed', 'approved', 'paid'], true)) {
                    throw new HttpException(422, 'expense_requires_approval', 'Expenses must go through signatory approval. Create a linked withdrawal first.');
                }

                if ($status === '' || $status === null) {
                    $row['status'] = 'pending';
                }
            }

            // normalize ISO8601 datetimes to MySQL-friendly format
            foreach ($row as $col => $val) {
                if (! is_string($val)) continue;
                // common ISO8601 form with T and Z, or other parseable date-like strings
                if (str_contains($val, 'T') || str_contains($val, 'Z')) {
                    try {
                        $row[$col] = Carbon::parse($val)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        // leave as-is if parse fails
                    }
                }
            }

            // attempt insert; if id provided use it, otherwise try to ensure id exists when table expects a non-autoincrement id
            if (! isset($row['id']) || $row['id'] === '') {
                if (Schema::hasColumn($table, 'id')) {
                    // inspect id column type; if it's not integer-based, generate a UUID
                    $colInfo = DB::select("SHOW COLUMNS FROM `{$table}` LIKE 'id'");
                    if (! empty($colInfo) && isset($colInfo[0]->Type)) {
                        $type = strtolower($colInfo[0]->Type);
                        if (str_starts_with($type, 'char') || str_contains($type, 'varchar') || str_contains($type, 'binary')) {
                            $row['id'] = Uuid::v4();
                        }
                    }
                }
            }

            // support a simple upsert behavior when client passes ?upsert=true
            $upsert = in_array($request->query('upsert'), ['1', 'true', 't'], true);
            if ($upsert && isset($row['user_id']) && !in_array($table, [
                'penalty_withdrawals',
                'donation_withdrawals',
                'operational_withdrawals',
            ], true)) {
                $existing = DB::table($table)->where('user_id', $row['user_id'])->first();
                if ($existing) {
                    DB::table($table)->where('user_id', $row['user_id'])->update($row);
                    $stored = DB::table($table)->where('user_id', $row['user_id'])->first();
                    if (isset($stored)) { $out[] = $this->normalizeNumericStrings([(array) $stored])[0] ?? (array) $stored; continue; }
                }
            }

             try {
                 DB::table($table)->insert($row);
                 if (isset($row['id'])) {
                     $stored = DB::table($table)->where('id', $row['id'])->first();
                 } elseif (isset($row['user_id'])) {
                     $stored = DB::table($table)->where('user_id', $row['user_id'])->orderByDesc('created_at')->first();
                 } else {
                     $stored = DB::table($table)->where($row)->orderByDesc('created_at')->first();
                 }

             // Fire domain events for relevant tables
                  if ($table === 'news' && isset($stored)) {
                      $status = $stored->status ?? null;
                      $prevStatus = $row['status'] ?? null;
                      // Fire NewsPublished if status changed to published/active
                      if (in_array($status, ['published', 'active']) && $status !== $prevStatus) {
                          event(new \App\Events\NewsPublished(new \App\Models\News((array) $stored)));
                      }
                  }

                  // Auto-create signatory records for new withdrawal entries
                  $withdrawalTables = ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'];
                  $signatoryTableMap = [
                      'penalty_withdrawals' => 'withdrawal_signatories',
                      'donation_withdrawals' => 'donation_withdrawal_signatories',
                      'operational_withdrawals' => 'operational_withdrawal_signatories',
                  ];

                  if (isset($stored) && in_array($table, $withdrawalTables) && isset($signatoryTableMap[$table])) {
                      $signatoryTable = $signatoryTableMap[$table];
                      $withdrawalId = $stored->id ?? null;

                       if ($withdrawalId && true) {
                            $stages = ['secretary', 'chairperson'];
                          $existingSignatories = DB::table($signatoryTable)->where('withdrawal_id', $withdrawalId)->exists();

                          if (!$existingSignatories) {
                              foreach ($stages as $stage) {
                                  DB::table($signatoryTable)->insert([
                                      'id' => Uuid::v4(),
                                      'withdrawal_id' => $withdrawalId,
                                      'signatory_role' => $stage,
                                      'signatory_user_id' => null,
                                      'status' => 'pending',
                                      'created_at' => now(),
                                      'updated_at' => now(),
                                  ]);
                              }
                          }
                      }
                  }
              } catch (\Exception $e) {
                 // fallback: if id error, attempt to generate id and retry
                 if (str_contains($e->getMessage(), "Field 'id' doesn't have a default value") || str_contains($e->getMessage(), 'doesn\'t have a default value')) {
                     if (! isset($row['id'])) $row['id'] = Uuid::v4();
                     DB::table($table)->insert($row);
                     $stored = DB::table($table)->where('id', $row['id'])->first();
                 } else {
                     // generic fallback lookup
                     DB::table($table)->insert($row);
                     if (isset($row['user_id'])) {
                         $stored = DB::table($table)->where('user_id', $row['user_id'])->orderByDesc('created_at')->first();
                     } else {
                         $stored = DB::table($table)->where($row)->orderByDesc('created_at')->first();
                     }
                 }
             }

             if (isset($stored)) {
                 $this->notifyAdminsForCreatedRecord($table, $stored, $row, $identity);
             }

             if (isset($stored)) $out[] = $this->normalizeNumericStrings([(array) $stored])[0] ?? (array) $stored;
         }

        return response()->json($this->normalizeNumericStrings($out), 201);
    }

    private function notifyAdminsForCreatedRecord(string $table, object $stored, array $row, array $identity): void
    {
        $roles = $identity['roles'] ?? [];
        $memberId = (string) ($stored->member_id ?? $identity['member_id'] ?? '');
        if ($memberId === '') {
            return;
        }

        $memberName = DB::table('members')->where('id', $memberId)->value('name') ?: 'A member';
        $notifications = app(\App\Services\AdminNotificationService::class);

        if ($table === 'beneficiary_requests') {
            $beneficiaryName = trim((string) ($stored->beneficiary_name ?? $row['beneficiary_name'] ?? ''));
            $description = $beneficiaryName !== '' ? " for {$beneficiaryName}" : '';
            $notifications->notifyAdmins(
                'New beneficiary request',
                "{$memberName} submitted a beneficiary request{$description}.",
                'beneficiary_request',
                '/admin/beneficiary-requests',
                'beneficiary-request:' . (string) ($stored->id ?? '')
            );
            return;
        }

        if ($table === 'messages' && count(array_intersect($roles, PolicyService::STAFF_SET)) === 0) {
            $excerpt = trim((string) ($stored->content ?? $row['content'] ?? ''));
            if ($excerpt !== '') {
                $excerpt = \Illuminate\Support\Str::limit($excerpt, 140);
            }
            $conversationId = (string) ($stored->conversation_id ?? $row['conversation_id'] ?? '');
            $target = $conversationId !== '' ? 'chat:' . $conversationId : 'chat:';

            $notifications->notifyAdmins(
                'New member message',
                $excerpt !== '' ? "{$memberName}: {$excerpt}" : "{$memberName} sent a new message.",
                'member_message',
                $target,
                'member-message:' . (string) ($stored->id ?? '')
            );
        }
    }

    protected function update(Request $request, string $table, $builder, array $identity)
    {
        if (in_array($table, [
            'penalty_withdrawals',
            'donation_withdrawals',
            'operational_withdrawals',
        ], true)) {
            throw new HttpException(409, 'canonical_withdrawal_endpoint_required', 'Withdrawal records can only be changed through the approval and provider-status workflows.');
        }
        if (in_array($table, [
            'withdrawal_signatories',
            'donation_withdrawal_signatories',
            'operational_withdrawal_signatories',
        ], true)) {
            throw new HttpException(409, 'canonical_approval_endpoint_required', 'Approvals must use the authenticated withdrawal approval endpoint.');
        }

        $changes = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();

        if ($table === 'members') {
            $allowed = ['name', 'phone', 'email', 'member_id', 'gender', 'date_of_birth', 'address', 'department', 'facility'];
            $changes = array_intersect_key($changes, array_flip($allowed));

            if (empty($changes)) {
                throw new HttpException(400, 'empty_payload', 'Nothing to update');
            }

            $selected = $builder->get()->map(fn ($row) => (array) $row)->all();
            foreach ($selected as $row) {
                PolicyService::assertWrite($table, $identity, $row);
                DB::table($table)->where('id', $row['id'])->update($changes);

                try {
                    \App\Services\Cache\CacheService::invalidateMember($row['id']);
                } catch (\Throwable $e) {
                    Log::warning('RestApiController: member cache invalidation failed', [
                        'member_id' => $row['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json($this->normalizeNumericStrings($selected));
        }

        $allowedColumns = array_flip(self::getAllowedColumns($table));
        $immutableColumns = array_flip(['id', 'created_at']);
        $changes = array_intersect_key($changes, $allowedColumns);
        $changes = array_diff_key($changes, $immutableColumns);

        if ($changes === []) {
            throw new HttpException(400, 'empty_payload', 'Nothing to update');
        }
        if (isset($allowedColumns['updated_at']) && ! array_key_exists('updated_at', $changes)) {
            $changes['updated_at'] = now();
        }

        $selected = $builder->get()->map(fn ($row) => (array) $row)->all();
        foreach ($selected as $row) {
            PolicyService::assertWrite($table, $identity, $row);
            DB::table($table)->where('id', $row['id'])->update($changes);

            if ($table === 'news' && isset($changes['status'])) {
                $newStatus = $changes['status'];
                $oldStatus = $row['status'] ?? null;
                if (in_array($newStatus, ['published', 'active']) && $newStatus !== $oldStatus) {
                    $updated = DB::table('news')->where('id', $row['id'])->first();
                    if ($updated) {
                        event(new \App\Events\NewsPublished(new \App\Models\News((array) $updated)));
                    }
                }
            }
        }

        return response()->json($this->normalizeNumericStrings($selected));
    }

    protected function delete(Request $request, string $table, $builder, array $identity)
    {
        if (in_array($table, [
            'penalty_withdrawals',
            'donation_withdrawals',
            'operational_withdrawals',
            'withdrawal_signatories',
            'donation_withdrawal_signatories',
            'operational_withdrawal_signatories',
        ], true)) {
            throw new HttpException(409, 'financial_history_immutable', 'Withdrawal and approval records cannot be deleted through the generic API.');
        }

        $selected = $builder->get()->map(fn ($row) => (array) $row)->all();
        foreach ($selected as $row) {
            PolicyService::assertWrite($table, $identity, $row);
            DB::table($table)->where('id', $row['id'])->delete();
        }

        return response()->json($this->normalizeNumericStrings($selected));
    }

    /**
     * GET /api/withdrawals — aggregate records from all three withdrawal tables.
     * Each row is tagged with a `type` field ('penalty', 'donation', 'operational')
     * so the caller can distinguish them.
     */
    public function readWithdrawals(Request $request)
    {
        $identity = $request->attributes->get('identity');

        if (! PolicyService::canReadAll('withdrawals', $identity['roles'])) {
            $scope = PolicyService::readScope('withdrawals', $identity);
            if ($scope !== null) {
                $column = $scope['column'];
                $value = $scope['value'];
            } else {
                return response()->json([], 200);
            }
        } else {
            $column = null;
            $value = null;
        }

        $query = RestQueryBuilder::fromArray($request->query->all());
        $tables = ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'];
        $types = ['penalty', 'donation', 'operational'];
        $out = [];

        foreach ($tables as $i => $dbTable) {
            $builder = DB::table($dbTable);

            if ($query->columns !== ['*']) {
                $builder->select($query->columns);
            }

            if ($column !== null && $value !== null) {
                $builder->where($column, $value);
            }

            $builder = $query->apply($builder);

            if ($request->query('count') === 'exact') {
                $out[] = ['type' => $types[$i], 'count' => (int) $builder->count()];
                continue;
            }

            $rows = $builder->get()->map(fn ($row) => (array) $row)->all();
            foreach ($rows as $row) {
                $row['type'] = $types[$i];
                $out[] = $row;
            }
        }

        if ($request->query('count') === 'exact') {
            return response()->json($out);
        }

        return response()->json($this->normalizeNumericStrings($out));
    }

    /**
     * POST /api/withdrawals — create a new withdrawal request.
     *
     * The frontend (PenaltyWallet) POSTs to /withdrawals with amount/reason/status.
     * There is no single `withdrawals` table; this creates a record in
     * `penalty_withdrawals` (the wallet page context determines which table).
     */
    public function createWithdrawal(Request $request)
    {
        $identity = $request->attributes->get('identity');

        PolicyService::assertWrite('withdrawals', $identity);

        $table = 'penalty_withdrawals';

        if (! PolicyService::tableExists($table)) {
            throw new HttpException(404, 'not_found', "Table '{$table}' was not found");
        }

        $payload = $request->all();

         $row = [
            'id' => Uuid::v4(),
            'member_id' => $identity['member_id'],
            'amount' => (float) ($payload['amount'] ?? 0),
            'reason' => $payload['reason'] ?? '',
             'status' => 'pending',
             'approval_stage' => 'secretary',
            'requested_by' => $identity['user_id'],
            'phone_number' => $payload['phone_number'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (empty($row['member_id'])) {
            throw new HttpException(400, 'missing_member', 'Authenticated user must have a member record.');
        }

        if ($row['amount'] <= 0) {
            throw new HttpException(400, 'invalid_amount', 'Amount must be greater than zero.');
        }

        $allowedCols = self::getAllowedColumns($table);
        $row = array_intersect_key($row, array_flip($allowedCols));

        DB::transaction(function () use ($table, $row, $identity) {
            DB::table($table)->insert($row);

            $signatoryTable = 'withdrawal_signatories';
            if ($table === 'donation_withdrawals') {
                $signatoryTable = 'donation_withdrawal_signatories';
            } elseif ($table === 'operational_withdrawals') {
                $signatoryTable = 'operational_withdrawal_signatories';
            }

            if (true) {
                $stages = ['secretary', 'chairperson'];
                foreach ($stages as $stage) {
                    DB::table($signatoryTable)->insert([
                        'id' => Uuid::v4(),
                        'withdrawal_id' => $row['id'],
                        'signatory_role' => $stage,
                        'signatory_user_id' => null,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->logAudit(
                $identity,
                'withdrawal_created',
                $table,
                $row['id'],
                [
                    'amount' => $row['amount'],
                    'reason' => $row['reason'],
                    'wallet_type' => $table === 'penalty_withdrawals' ? 'penalty' : ($table === 'donation_withdrawals' ? 'donation' : 'operational'),
                ]
            );
        });

        $stored = DB::table($table)->where('id', $row['id'])->first();

        return response()->json($this->normalizeNumericStrings([(array) $stored])[0] ?? (array) $stored, 201);
    }

    /**
     * POST /api/{table}/{id}/approve — approve a registration.
     *
     * Routes to update the member_registrations record status to 'approved'.
     */
    public function approveRecord(Request $request, string $table, string $id)
    {
        $table = str_replace('-', '_', $table);
        $identity = $request->attributes->get('identity');

        // 'registrations' is a virtual table that maps to 'member_registrations'
        $actualTable = self::VIRTUAL_TABLE_MAP[$table] ?? $table;

        if (! PolicyService::tableExists($table)) {
            throw new HttpException(403, 'table_not_allowed', 'Table not allowed.');
        }

        PolicyService::assertWrite($table, $identity);

        $existing = DB::table($actualTable)->where('id', $id)->first();

        if (! $existing) {
            throw new HttpException(404, 'not_found', 'Record not found.');
        }

        $changes = ['status' => 'approved'];
        $notes = $request->input('notes');
        if ($notes !== null) {
            $changes['approval_notes'] = $notes;
        }

        $changes['approved_at'] = now();
        $changes['updated_at'] = now();

        $allowedCols = self::getAllowedColumns($actualTable);
        $changes = array_intersect_key($changes, array_flip($allowedCols));

        DB::table($actualTable)->where('id', $id)->update($changes);

        $updated = DB::table($actualTable)->where('id', $id)->first();

        return response()->json($this->normalizeNumericStrings([(array) $updated])[0] ?? (array) $updated, 200);
    }

    /**
     * POST /api/{table}/{id}/reject — reject a registration.
     *
     * Routes to update the member_registrations record status to 'rejected'.
     */
    public function rejectRecord(Request $request, string $table, string $id)
    {
        $table = str_replace('-', '_', $table);
        $identity = $request->attributes->get('identity');

        $actualTable = self::VIRTUAL_TABLE_MAP[$table] ?? $table;

        if (! PolicyService::tableExists($table)) {
            throw new HttpException(403, 'table_not_allowed', 'Table not allowed.');
        }

        PolicyService::assertWrite($table, $identity);

        $existing = DB::table($actualTable)->where('id', $id)->first();

        if (! $existing) {
            throw new HttpException(404, 'not_found', 'Record not found.');
        }

        $reason = $request->input('reason', $request->input('rejection_reason', ''));

        $changes = ['status' => 'rejected'];
        if ($reason !== '') {
            $changes['rejection_reason'] = $reason;
        }

        $changes['rejected_at'] = now();
        $changes['updated_at'] = now();

        $allowedCols = self::getAllowedColumns($actualTable);
        $changes = array_intersect_key($changes, array_flip($allowedCols));

        DB::table($actualTable)->where('id', $id)->update($changes);

        $updated = DB::table($actualTable)->where('id', $id)->first();

        return response()->json($this->normalizeNumericStrings([(array) $updated])[0] ?? (array) $updated, 200);
    }

    /**
     * POST /api/{table}/update — update a signatory record.
     *
     * Used by the withdrawal approval flow. Matches on withdrawal_id + signatory_role,
     * then applies the remaining fields to the signatory row.
     */
    public function handleCustomUpdate(Request $request, string $table)
    {
        $table = str_replace('-', '_', $table);
        $identity = $request->attributes->get('identity');

        if (! PolicyService::tableExists($table)) {
            throw new HttpException(403, 'table_not_allowed', 'Table not allowed.');
        }

        PolicyService::assertWrite($table, $identity);

        $signatoryTables = [
            'withdrawal_signatories',
            'donation_withdrawal_signatories',
            'operational_withdrawal_signatories',
        ];

        if (in_array($table, $signatoryTables)) {
            $payload = $request->all();
            $withdrawalId = $payload['withdrawal_id'] ?? null;
            $signatoryRole = $payload['signatory_role'] ?? null;
            $status = $payload['status'] ?? null;

            if ($withdrawalId === null || $signatoryRole === null) {
                throw new HttpException(400, 'missing_criteria', 'withdrawal_id and signatory_role are required.');
            }

            $approvalService = app(WithdrawalApprovalService::class);
            $approvalService->setIdentity($identity);

            if ($status === 'rejected') {
                $rejectionReason = $payload['rejection_reason'] ?? $payload['reason'] ?? '';
                $updated = $approvalService->recordRejection(
                    $table,
                    $withdrawalId,
                    $signatoryRole,
                    $rejectionReason,
                    $identity
                );
                return response()->json($this->normalizeNumericStrings([(array) $updated])[0] ?? (array) $updated, 200);
            }

            $changes = array_diff_key($payload, array_flip([
                'withdrawal_id', 'signatory_role', 'status',
            ]));

            foreach (['approved_at', 'rejected_at'] as $dateField) {
                if (isset($changes[$dateField]) && is_string($changes[$dateField])) {
                    try {
                        $changes[$dateField] = Carbon::parse($changes[$dateField])->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        unset($changes[$dateField]);
                    }
                }
            }

            if (isset($payload['signature_url'])) {
                $changes['signature_url'] = $payload['signature_url'];
            }
            if (isset($payload['signatory_user_id'])) {
                $changes['signatory_user_id'] = $payload['signatory_user_id'];
            }
            $changes['status'] = 'approved';

            $approvalService->recordApproval($table, $withdrawalId, $signatoryRole, $changes, $identity);

            $signatoryTable = $table;
            $updated = DB::table($signatoryTable)
                ->where('withdrawal_id', $withdrawalId)
                ->where('signatory_role', $signatoryRole)
                ->first();

            $withdrawal = $approvalService->getWithdrawalRecord($signatoryTable, $withdrawalId);

            return response()->json([
                'signatory' => $this->normalizeNumericStrings([(array) $updated])[0] ?? (array) $updated,
                'withdrawal' => $withdrawal,
            ], 200);
        }

        $payload = $request->all();
        $withdrawalId = $payload['withdrawal_id'] ?? null;

        if ($withdrawalId === null) {
            throw new HttpException(400, 'missing_criteria', 'withdrawal_id is required.');
        }

        $changes = array_diff_key($payload, array_flip(['withdrawal_id', 'signatory_role']));

        $builder = DB::table($table)
            ->where('withdrawal_id', $withdrawalId);

        if (! empty($payload['signatory_role'])) {
            $builder->where('signatory_role', $payload['signatory_role']);
        }

        if (! empty($payload['signatory_user_id'])) {
            $builder->where('signatory_user_id', $payload['signatory_user_id']);
        }

        $existing = $builder->first();

        if (! $existing) {
            throw new HttpException(404, 'not_found', 'Signatory record not found.');
        }

        $changes['updated_at'] = now();

        $allowedCols = self::getAllowedColumns($table);
        $changes = array_intersect_key($changes, array_flip($allowedCols));

        DB::table($table)
            ->where('id', $existing->id)
            ->update($changes);

        $updated = DB::table($table)->where('id', $existing->id)->first();

        return response()->json($this->normalizeNumericStrings([(array) $updated])[0] ?? (array) $updated, 200);
    }

    /**
     * POST /api/{table}/update-status — update a withdrawal's status.
     *
     * Used by the withdrawal approval flow after all signatories have approved/rejected.
     */
    public function handleCustomUpdateStatus(Request $request, string $table)
    {
        $table = str_replace('-', '_', $table);
        if (in_array($table, ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'], true)) {
            throw new HttpException(409, 'canonical_withdrawal_endpoint_required', 'Withdrawal status is controlled by the approval workflow and authoritative provider outcomes.');
        }

        throw new HttpException(404, 'not_found', 'Status update endpoint is not available for this resource.');
    }

    protected function logAudit(array $identity, string $action, string $table, string $recordId, array $details): void
    {
        try {
            $userId = $identity['user_id'] ?? null;
            $roles = $identity['roles'] ?? [];
            $superAdminId = in_array('super_admin', $roles, true) ? $userId : null;

            DB::table('audit_logs')->insert([
                'id' => Uuid::v4(),
                'super_admin_id' => $superAdminId ?? $userId ?? '00000000-0000-0000-0000-000000000000',
                'action' => $action,
                'target_user_id' => $userId,
                'details' => json_encode(array_merge($details, [
                    'table' => $table,
                    'record_id' => $recordId,
                ])),
                'ip_address' => request()->ip() ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging should never break the main flow
        }
    }
}
