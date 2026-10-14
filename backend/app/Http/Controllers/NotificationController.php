<?php

namespace App\Http\Controllers;

use App\Services\Cache\CacheService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications, private CacheService $cache) {}

    public function bulkSms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phones' => ['required', 'array', 'min:1'],
            'phones.*' => ['string', 'max:20'],
            'message' => ['required', 'string', 'max:1600'],
        ]);

        $result = $this->notifications->sendBulkSms($data['phones'], $data['message']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:1000'],
            'tokens' => ['required', 'array', 'min:1'],
            'tokens.*' => ['string'],
        ]);

        $result = $this->notifications->sendPush($data['title'], $data['body'], $data['tokens']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function unseenEventCount(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $lastSeenAt = $request->query('last_seen_at');
        
        $key = $lastSeenAt ? md5($lastSeenAt) : 'none';
        $userId = $identity['user_id'] ?? 'guest';
        
        $count = $this->cache->rememberUnseenEventCount($userId, $lastSeenAt, function () use ($lastSeenAt) {
            $query = DB::table('events');
            if ($lastSeenAt) {
                $query->where('created_at', '>', $lastSeenAt);
            }
            return $query->count();
        });
        
        return response()->json(['count' => (int) $count]);
    }

    public function unreadNotificationCount(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('identity')['user_id'] ?? null;
        if (!$userId) {
            return response()->json(['count' => 0]);
        }

        $count = $this->cache->rememberUnreadNotificationCount($userId, function () use ($userId) {
            return DB::table('notifications')
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->count();
        });

        return response()->json(['count' => (int) $count]);
    }

    public function unreadNewsCount(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('identity')['user_id'] ?? null;
        if (!$userId) {
            return response()->json(['count' => 0]);
        }

        $count = $this->cache->rememberUnreadNewsCount($userId, function () use ($userId) {
            $readNews = DB::table('news_read')
                ->where('user_id', $userId)
                ->pluck('news_id')
                ->all();

            return DB::table('news')
                ->when(!empty($readNews), function ($query) use ($readNews) {
                    $query->whereNotIn('id', $readNews);
                })
                ->count();
        });

        return response()->json(['count' => (int) $count]);
    }
}
