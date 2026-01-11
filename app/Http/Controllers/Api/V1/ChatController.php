<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ValidatesTenantOwnership;
use App\Application\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    use ValidatesTenantOwnership;

    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * List user's chat rooms with tenant scoping
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            $chats = $this->chatService->getUserChatsWithTenant($userId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $chats,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch chats', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch chats',
            ], 500);
        }
    }

    /**
     * Get messages from specific chat with authorization check
     */
    public function show(Request $request, string $chatId)
    {
        try {
            $userId = $request->user()->id;
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            $perPage = min((int) $request->input('per_page', 50), 100);

            // Check access BEFORE getting/creating chat
            if (!$this->chatService->canUserAccessChat($chatId, $userId, $tenantId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $chat = $this->chatService->getOrCreateChatForEscrowWithTenant($chatId, $tenantId);

            $messages = $this->chatService->getChatMessages(
                $chat->id,
                $perPage
            );

            return response()->json([
                'success' => true,
                'data' => $messages,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch messages', [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages',
            ], 500);
        }
    }

    /**
     * Send message to chat with authorization
     */
    public function sendMessage(Request $request, string $chatId)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'attachment_url' => 'nullable|url',
        ]);

        try {
            $userId = $request->user()->id;
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            // Check access before sending
            if (!$this->chatService->canUserAccessChat($chatId, $userId, $tenantId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $chat = $this->chatService->getOrCreateChatForEscrowWithTenant($chatId, $tenantId);

            $message = $this->chatService->sendMessage(
                $chat->id,
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
     * Mark messages as read with authorization
     */
    public function markAsRead(Request $request, string $chatId)
    {
        try {
            $userId = $request->user()->id;
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            // Check access before marking as read
            if (!$this->chatService->canUserAccessChat($chatId, $userId, $tenantId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $chat = $this->chatService->getOrCreateChatForEscrowWithTenant($chatId, $tenantId);

            $this->chatService->markAsRead($chat->id, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Messages marked as read',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark as read', [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as read',
            ], 500);
        }
    }
}
