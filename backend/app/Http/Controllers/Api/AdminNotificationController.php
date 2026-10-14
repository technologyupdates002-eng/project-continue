<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $this->adminUserId($request);
        app(AdminNotificationService::class)->ensurePendingBeneficiaryRequestNotifications();
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $columns = ['id', 'title', 'message', 'type', 'is_read', 'created_at'];
        if (Schema::hasColumn('notifications', 'target_url')) {
            $columns[] = 'target_url';
        }

        $notifications = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage, $columns);

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => DB::table('notifications')
                    ->where('user_id', $userId)
                    ->where('is_read', false)
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $userId = $this->adminUserId($request);
        $updated = DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->update(['is_read' => 1]);

        if ($updated === 0 && ! DB::table('notifications')->where('id', $id)->where('user_id', $userId)->exists()) {
            throw new HttpException(404, 'notification_not_found', 'Notification not found.');
        }

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = $this->adminUserId($request);
        $updated = DB::table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => 1]);

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    private function adminUserId(Request $request): string
    {
        $identity = $request->attributes->get('identity', []);
        $roles = $identity['roles'] ?? [];
        if (! in_array('admin', $roles, true) && ! in_array('super_admin', $roles, true)) {
            throw new HttpException(403, 'forbidden', 'Admin only.');
        }

        $userId = (string) ($identity['user_id'] ?? '');
        if ($userId === '') {
            throw new HttpException(401, 'unauthenticated', 'Authentication required.');
        }

        return $userId;
    }
}
