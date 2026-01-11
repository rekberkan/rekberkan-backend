<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Escrow;
use App\Models\User;
use App\Models\Admin;
use App\Events\ChatMessageSent;
use App\Domain\Escrow\Enums\EscrowStatus;
use Illuminate\Support\Facades\DB;

class ChatService
{
    private const MAX_PER_PAGE = 100;
    private const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Send message to escrow chat
     */
    public function sendMessage(
        Escrow $escrow,
        User|Admin $sender,
        string $body
    ): ChatMessage {
        $sanitizedBody = trim(strip_tags($body));
        if ($sanitizedBody === '') {
            throw new \Exception('Message body cannot be empty');
        }

        if (mb_strlen($sanitizedBody) > self::MAX_MESSAGE_LENGTH) {
            throw new \Exception('Message body exceeds maximum length');
        }

        $this->validateParticipant($escrow, $sender);

        return DB::transaction(function () use ($escrow, $sender, $sanitizedBody) {
            $message = ChatMessage::create([
                'tenant_id' => $escrow->tenant_id,
                'escrow_id' => $escrow->id,
                'sender_type' => get_class($sender),
                'sender_id' => $sender->id,
                'body' => $sanitizedBody,
            ]);

            $this->auditService->log([
                'event_type' => 'CHAT_MESSAGE_SENT',
                'subject_type' => ChatMessage::class,
                'subject_id' => $message->id,
                'actor_id' => $sender->id,
                'actor_type' => get_class($sender),
                'metadata' => [
                    'escrow_id' => $escrow->id,
                    'message_length' => mb_strlen($sanitizedBody),
                ],
            ]);

            broadcast(new ChatMessageSent($message))->toOthers();

            return $message;
        });
    }

    /**
     * Validate participant can access escrow chat
     */
    protected function validateParticipant(Escrow $escrow, User|Admin $sender): void
    {
        if ($sender instanceof Admin) {
            if ($this->resolveStatus($escrow) !== EscrowStatus::DISPUTED) {
                throw new \Exception('Admin can only join chat for disputed escrows');
            }
            return;
        }

        if ($sender->id !== $escrow->buyer_id && $sender->id !== $escrow->seller_id) {
            throw new \Exception('User is not a participant of this escrow');
        }
    }

    /**
     * Check if user/admin can access chat
     */
    public function canAccessChat(Escrow $escrow, User|Admin $accessor): bool
    {
        if ($accessor instanceof Admin) {
            return $this->resolveStatus($escrow) === EscrowStatus::DISPUTED;
        }

        return $accessor->id === $escrow->buyer_id || $accessor->id === $escrow->seller_id;
    }

    /**
     * Check if user can access chat by escrow ID (with tenant validation)
     */
    public function canUserAccessChat(string $escrowId, int $userId, int $tenantId): bool
    {
        $escrow = Escrow::where('id', $escrowId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$escrow) {
            return false;
        }

        return $escrow->buyer_id === $userId || $escrow->seller_id === $userId;
    }

    /**
     * Get user chats (without tenant filter)
     * @deprecated Use getUserChatsWithTenant() instead
     */
    public function getUserChats(int $userId): array
    {
        $escrows = Escrow::where(function ($query) use ($userId) {
            $query->where('buyer_id', $userId)
                ->orWhere('seller_id', $userId);
        })
        ->with(['buyer', 'seller'])
        ->withCount('chatMessages')
        ->orderByDesc('updated_at')
        ->get();

        return $this->formatChatsList($escrows);
    }

    /**
     * Get user chats with tenant scoping (safe)
     */
    public function getUserChatsWithTenant(int $userId, int $tenantId): array
    {
        $escrows = Escrow::where('tenant_id', $tenantId)
            ->where(function ($query) use ($userId) {
                $query->where('buyer_id', $userId)
                    ->orWhere('seller_id', $userId);
            })
            ->with(['buyer', 'seller'])
            ->withCount('chatMessages')
            ->orderByDesc('updated_at')
            ->get();

        return $this->formatChatsList($escrows);
    }

    /**
     * Get or create chat for escrow with tenant validation (safe)
     */
    public function getOrCreateChatForEscrowWithTenant(string $escrowId, int $tenantId): Escrow
    {
        return Escrow::where('id', $escrowId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /**
     * Get chat messages
     */
    public function getChatMessages(Escrow $escrow, User|Admin $accessor, int $page = 1, int $perPage = 50): array
    {
        if (!$this->canAccessChat($escrow, $accessor)) {
            throw new \Exception('Unauthorized');
        }

        return $this->getMessages($escrow, $page, $this->clampPerPage($perPage));
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(Escrow $escrow, User|Admin $accessor): void
    {
        if (!$this->canAccessChat($escrow, $accessor)) {
            throw new \Exception('Unauthorized');
        }

        // TODO: Implement notification_reads table logic
        // For now, just validate access
    }

    /**
     * Get messages for escrow
     */
    public function getMessages(Escrow $escrow, int $page = 1, int $perPage = 50): array
    {
        $perPage = $this->clampPerPage($perPage);

        $messages = ChatMessage::where('escrow_id', $escrow->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $messages->items(),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'total_pages' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ];
    }

    /**
     * Format chats list with last message
     */
    private function formatChatsList($escrows): array
    {
        return $escrows->map(function (Escrow $escrow) {
            $lastMessage = ChatMessage::where('escrow_id', $escrow->id)
                ->orderByDesc('created_at')
                ->first();

            return [
                'escrow' => $escrow,
                'last_message' => $lastMessage,
                'messages_count' => $escrow->chat_messages_count ?? 0,
            ];
        })->all();
    }

    /**
     * Resolve escrow status
     */
    private function resolveStatus(Escrow $escrow): EscrowStatus
    {
        if ($escrow->status instanceof EscrowStatus) {
            return $escrow->status;
        }

        return EscrowStatus::from((string) $escrow->status);
    }

    /**
     * Clamp per page value
     */
    private function clampPerPage(int $perPage): int
    {
        if ($perPage <= 0) {
            return 50;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
