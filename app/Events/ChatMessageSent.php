<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $escrowId,
        public string $messageId,
        public string $senderId,
        public string $senderName,
        public string $message,
        public ?string $attachmentUrl = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("escrow-chat.{$this->escrowId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'sender_id' => $this->senderId,
            'sender_name' => $this->senderName,
            'message' => $this->message,
            'attachment_url' => $this->attachmentUrl,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
