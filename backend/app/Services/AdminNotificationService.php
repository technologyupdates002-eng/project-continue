<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminNotificationService
{
    public function ensurePendingBeneficiaryRequestNotifications(): void
    {
        if (! Schema::hasColumn('notifications', 'event_key')) {
            return;
        }

        try {
            $userIds = DB::table('user_roles')
                ->where('is_active', 1)
                ->whereIn('role', ['admin', 'super_admin'])
                ->distinct()
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->values();

            if ($userIds->isEmpty()) {
                return;
            }

            $requests = DB::table('beneficiary_requests')
                ->join('members', 'members.id', '=', 'beneficiary_requests.member_id')
                ->where('beneficiary_requests.status', 'pending')
                ->select([
                    'beneficiary_requests.id',
                    'beneficiary_requests.request_type',
                    'beneficiary_requests.beneficiary_name',
                    'members.name as member_name',
                ])
                ->get();

            if ($requests->isEmpty()) {
                return;
            }

            $hasTargetUrl = Schema::hasColumn('notifications', 'target_url');
            $createdAt = now();
            $rows = [];
            foreach ($requests as $request) {
                $beneficiary = trim((string) ($request->beneficiary_name ?? ''));
                $description = $beneficiary !== '' ? " for {$beneficiary}" : '';
                foreach ($userIds as $userId) {
                    $row = [
                        'id' => Uuid::v4(),
                        'user_id' => (string) $userId,
                        'title' => 'New beneficiary request',
                        'message' => "{$request->member_name} submitted a beneficiary request{$description}.",
                        'type' => 'beneficiary_request',
                        'is_read' => 0,
                        'event_key' => hash('sha256', 'beneficiary-request:' . $request->id),
                        'created_at' => $createdAt,
                    ];
                    if ($hasTargetUrl) {
                        $row['target_url'] = '/admin/beneficiary-requests';
                    }
                    $rows[] = $row;
                }
            }

            DB::table('notifications')->upsert($rows, ['user_id', 'event_key'], ['event_key']);
        } catch (QueryException $exception) {
            Log::error('Unable to synchronize pending beneficiary request notifications', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function notifyAdmins(
        string $title,
        string $message,
        string $type,
        ?string $targetUrl = null,
        ?string $eventKey = null
    ): void {
        try {
            $userIds = DB::table('user_roles')
                ->where('is_active', 1)
                ->whereIn('role', ['admin', 'super_admin'])
                ->distinct()
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->values();

            if ($userIds->isEmpty()) {
                return;
            }

            $hasTargetUrl = Schema::hasColumn('notifications', 'target_url');
            $hasEventKey = Schema::hasColumn('notifications', 'event_key');
            $createdAt = now();
            $rows = $userIds->map(function ($userId) use ($title, $message, $type, $targetUrl, $eventKey, $hasTargetUrl, $hasEventKey, $createdAt) {
                $row = [
                    'id' => Uuid::v4(),
                    'user_id' => (string) $userId,
                    'title' => $title,
                    'message' => $message,
                    'type' => $type,
                    'is_read' => 0,
                    'created_at' => $createdAt,
                ];
                if ($hasTargetUrl) {
                    $row['target_url'] = $targetUrl;
                }
                if ($hasEventKey && $eventKey !== null) {
                    $row['event_key'] = hash('sha256', $eventKey);
                }

                return $row;
            })->all();

            if ($hasEventKey && $eventKey !== null) {
                DB::table('notifications')->upsert($rows, ['user_id', 'event_key'], ['event_key']);

                return;
            }

            DB::table('notifications')->insert($rows);
        } catch (QueryException $exception) {
            Log::error('Unable to create admin event notifications', [
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
