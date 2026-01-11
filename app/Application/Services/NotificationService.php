<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Notification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class NotificationService
{
    /**
     * Get user notifications with pagination
     */
    public function getUserNotifications(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->update([
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Mark all user notifications as read
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);
    }

    /**
     * Delete notification
     */
    public function delete(Notification $notification): void
    {
        $notification->delete();
    }

    /**
     * Send notification to user
     */
    public function send(int $userId, string $type, string $title, string $body, ?array $data = null): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ? json_encode($data) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Send bulk notifications
     */
    public function sendBulk(array $userIds, string $type, string $title, string $body, ?array $data = null): int
    {
        $notifications = [];
        $now = now();
        
        foreach ($userIds as $userId) {
            $notifications[] = [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data ? json_encode($data) : null,
                'created_at' => $now,
            ];
        }
        
        DB::table('notifications')->insert($notifications);
        
        return count($notifications);
    }

    /**
     * Delete old read notifications
     */
    public function deleteOldReadNotifications(int $daysOld = 30): int
    {
        return Notification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}
