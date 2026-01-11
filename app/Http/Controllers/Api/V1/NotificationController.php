<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Application\Services\NotificationService;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Get user notifications
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $notifications = $this->notificationService->getUserNotifications($userId, 20);

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Get unread notifications count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $count = $this->notificationService->getUnreadCount($userId);

        return response()->json([
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $id, Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $notification = Notification::where('id', $id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $this->notificationService->markAsRead($notification);

        return response()->json([
            'data' => new NotificationResource($notification->fresh()),
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $updated = $this->notificationService->markAllAsRead($userId);

        return response()->json([
            'message' => 'All notifications marked as read',
            'data' => [
                'updated_count' => $updated,
            ],
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $notification = Notification::where('id', $id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $this->notificationService->delete($notification);

        return response()->json([
            'message' => 'Notification deleted successfully',
        ]);
    }
}
