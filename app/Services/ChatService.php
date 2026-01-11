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

    public function canAccessChat(Escrow $escrow, User|Admin $accessor): bool
    {
        if ($accessor instanceof Admin) {
            return $this->resolveStatus($escrow) === EscrowStatus::DISPUTED;
        }

        return $accessor->id === $escrow->buyer_id || $accessor->id === $escrow->seller_id;
    }

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

        return $escrows->map(function (Escrow $escrow) {
            $lastMessage = ChatMessage::where('escrow_id', $escrow->id)
                ->orderByDesc('created_at')
                ->first();

            return [
                'escrow' => $escrow,
                'last_message' => $lastMessage,
                'messages_count' => $escrow->chat_messages_count,
            ];
        })->all();
    }

    public function getChatMessages(Escrow $escrow, User|Admin $accessor, int $page = 1, int $perPage = 50): array
    {
        if (!$this->canAccessChat($escrow, $accessor)) {
            throw new \Exception('Unauthorized');
        }

        return $this->getMessages($escrow, $page, $this->clampPerPage($perPage));
    }

    public function markAsRead(Escrow $escrow, User|Admin $accessor): void
    {
        if (!$this->canAccessChat($escrow, $accessor)) {
            throw new \Exception('Unauthorized');
        }
    }

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

    private function resolveStatus(Escrow $escrow): EscrowStatus
    {
        if ($escrow->status instanceof EscrowStatus) {
            return $escrow->status;
        }

        return EscrowStatus::from((string) $escrow->status);
    }

    private function clampPerPage(int $perPage): int
    {
        if ($perPage <= 0) {
            return 50;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
