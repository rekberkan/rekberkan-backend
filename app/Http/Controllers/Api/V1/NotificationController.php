<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * List user's notifications.
     * 
     * NEW CONTROLLER: Expose NotificationService.
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $notifications = $this->notificationService->getUserNotifications(
                $userId,
                $page,
                $perPage
            );

            return response()->json([
                'success' => true,
                'data' => $notifications,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
            ], 500);
        }
    }

    /**
     * Get unread notification count.
     */
    public function unreadCount(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $count = $this->notificationService->getUnreadCount($userId);

            return response()->json([
                'success' => true,
                'data' => ['count' => $count],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch count',
            ], 500);
        }
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(Request $request, string $id)
    {
        try {
            $userId = $request->user()->id;
            $this->notificationService->markAsRead($id, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as read',
            ], 500);
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $this->notificationService->markAllAsRead($userId);

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all as read',
            ], 500);
        }
    }

    /**
     * Delete notification.
     */
    public function destroy(Request $request, string $id)
    {
        try {
            $userId = $request->user()->id;
            $this->notificationService->delete($id, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification',
            ], 500);
        }
    }
}
