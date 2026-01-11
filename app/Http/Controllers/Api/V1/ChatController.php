<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * List user's chat rooms.
     * 
     * NEW CONTROLLER: Expose ChatService untuk escrow messaging.
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $chats = $this->chatService->getUserChats($userId);

            return response()->json([
                'success' => true,
                'data' => $chats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch chats',
            ], 500);
        }
    }

    /**
     * Get messages from specific chat.
     */
    public function show(Request $request, string $chatId)
    {
        try {
            $userId = $request->user()->id;
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 50);

            $messages = $this->chatService->getChatMessages(
                $chatId,
                $userId,
                $page,
                $perPage
            );

            return response()->json([
                'success' => true,
                'data' => $messages,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages',
            ], 500);
        }
    }

    /**
     * Send message to chat.
     */
    public function sendMessage(Request $request, string $chatId)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'attachment_url' => 'nullable|url',
        ]);

        try {
            $userId = $request->user()->id;

            $message = $this->chatService->sendMessage(
                $chatId,
                $userId,
                $validated['message'],
                $validated['attachment_url'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $message,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to send message', [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message',
            ], 500);
        }
    }

    /**
     * Mark messages as read.
     */
    public function markAsRead(Request $request, string $chatId)
    {
        try {
            $userId = $request->user()->id;
            $this->chatService->markAsRead($chatId, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Messages marked as read',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as read',
            ], 500);
        }
    }
}
