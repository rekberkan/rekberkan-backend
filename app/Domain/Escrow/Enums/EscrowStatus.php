<?php

declare(strict_types=1);

namespace App\Domain\Escrow\Enums;

enum EscrowStatus: string
{
    case PENDING_PAYMENT = 'pending_payment';
    case FUNDED = 'funded';
    case IN_PROGRESS = 'in_progress';
    case DELIVERED = 'delivered';
    case DISPUTED = 'disputed';
    case COMPLETED = 'completed';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING_PAYMENT => in_array($target, [self::FUNDED, self::CANCELLED]),
            self::FUNDED => in_array($target, [self::IN_PROGRESS, self::REFUNDED, self::CANCELLED]),
            self::IN_PROGRESS => in_array($target, [self::DELIVERED, self::DISPUTED, self::REFUNDED]),
            self::DELIVERED => in_array($target, [self::COMPLETED, self::DISPUTED]),
            self::DISPUTED => in_array($target, [self::COMPLETED, self::REFUNDED]),
            self::COMPLETED => false,
            self::REFUNDED => false,
            self::CANCELLED => false,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::REFUNDED, self::CANCELLED]);
    }

    public function requiresPayment(): bool
    {
        return $this === self::PENDING_PAYMENT;
    }

    public function canDispute(): bool
    {
        return in_array($this, [self::IN_PROGRESS, self::DELIVERED]);
    }

    public function canRelease(): bool
    {
        return in_array($this, [self::DELIVERED, self::DISPUTED]);
    }

    public function canRefund(): bool
    {
        return in_array($this, [self::FUNDED, self::IN_PROGRESS, self::DISPUTED]);
    }
}
