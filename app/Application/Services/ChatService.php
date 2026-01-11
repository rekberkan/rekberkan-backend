<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Escrow;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChatService
{
    private const MAX_PER_PAGE = 100;
    private const MAX_MESSAGE_LENGTH = 1000;
    /**
     * Get user chats
     */
    public function getUserChats(int $userId): Collection
    {
        return Chat::where(function ($query) use ($userId) {
            $query->where('user1_id', $userId)
                ->orWhere('user2_id', $userId);
        })
        ->with(['user1', 'user2', 'lastMessage', 'escrow'])
        ->orderBy('last_message_at', 'desc')
        ->get();
    }

    /**
     * Get chat messages with pagination
     */
    public function getChatMessages(string $chatId, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = $this->clampPerPage($perPage);

        return ChatMessage::where('chat_id', $chatId)
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get messages for chat (backward compatible with getMessages)
     */
    public function getMessages(string $chatId, int $limit = 50): Collection
    {
        $limit = $this->clampPerPage($limit);

        return ChatMessage::where('chat_id', $chatId)
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Send message in chat
     */
    public function sendMessage(string $chatId, int $senderId, string $message, ?string $attachment = null): ChatMessage
    {
        return DB::transaction(function () use ($chatId, $senderId, $message, $attachment) {
            $chat = Chat::findOrFail($chatId);
            
            // Verify sender is part of the chat
            if ($chat->user1_id !== $senderId && $chat->user2_id !== $senderId) {
                throw new \Exception('User is not part of this chat');
            }

            $sanitizedMessage = trim(strip_tags($message));
            if ($sanitizedMessage === '') {
                throw new \Exception('Message cannot be empty');
            }

            if (mb_strlen($sanitizedMessage) > self::MAX_MESSAGE_LENGTH) {
                throw new \Exception('Message exceeds maximum length');
            }
            
            // Create message
            $chatMessage = ChatMessage::create([
                'chat_id' => $chatId,
                'sender_id' => $senderId,
                'message' => $sanitizedMessage,
                'attachment_url' => $attachment,
                'created_at' => now(),
            ]);
            
            // Update chat last message timestamp
            $chat->update([
                'last_message_id' => $chatMessage->id,
                'last_message_at' => now(),
            ]);
            
            // Increment unread count for recipient
            $recipientId = $chat->user1_id === $senderId ? $chat->user2_id : $chat->user1_id;
            
            DB::table('chat_participants')
                ->where('chat_id', $chatId)
                ->where('user_id', $recipientId)
                ->increment('unread_count');
            
            return $chatMessage;
        });
    }

    /**
     * Mark chat as read for user
     */
    public function markAsRead(string $chatId, int $userId): void
    {
        DB::transaction(function () use ($chatId, $userId) {
            // Reset unread count
            DB::table('chat_participants')
                ->where('chat_id', $chatId)
                ->where('user_id', $userId)
                ->update(['unread_count' => 0]);
            
            // Mark messages as read
            ChatMessage::where('chat_id', $chatId)
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        });
    }

    /**
     * Check if user can access chat
     */
    public function canAccessChat(string $chatId, int $userId): bool
    {
        $chat = Chat::find($chatId);
        
        if (!$chat) {
            return false;
        }
        
        return $chat->user1_id === $userId || $chat->user2_id === $userId;
    }

    /**
     * Create or get chat for escrow
     */
    public function getOrCreateChatForEscrow(string $escrowId): Chat
    {
        return DB::transaction(function () use ($escrowId) {
            $escrow = Escrow::findOrFail($escrowId);
            
            // Check if chat already exists
            $chat = Chat::where('escrow_id', $escrowId)->first();
            
            if ($chat) {
                return $chat;
            }
            
            // Create new chat
            $chat = Chat::create([
                'escrow_id' => $escrowId,
                'user1_id' => $escrow->buyer_id,
                'user2_id' => $escrow->seller_id,
                'created_at' => now(),
            ]);
            
            // Create participants
            DB::table('chat_participants')->insert([
                [
                    'chat_id' => $chat->id,
                    'user_id' => $escrow->buyer_id,
                    'unread_count' => 0,
                    'created_at' => now(),
                ],
                [
                    'chat_id' => $chat->id,
                    'user_id' => $escrow->seller_id,
                    'unread_count' => 0,
                    'created_at' => now(),
                ],
            ]);
            
            return $chat;
        });
    }

    /**
     * Get unread count for user
     */
    public function getUnreadCount(int $userId): int
    {
        return DB::table('chat_participants')
            ->where('user_id', $userId)
            ->sum('unread_count');
    }

    private function clampPerPage(int $perPage): int
    {
        if ($perPage <= 0) {
            return 50;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
