<?php

declare(strict_types=1);

namespace App\Domain\Escrow\Enums;

enum EscrowStatus: string
{
    case CREATED = 'CREATED';
    case FUNDED = 'FUNDED';
    case DELIVERED = 'DELIVERED';
    case RELEASED = 'RELEASED';
    case REFUNDED = 'REFUNDED';
    case DISPUTED = 'DISPUTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    public function canTransitionTo(self $newStatus): bool
    {
        return match($this) {
            self::CREATED => in_array($newStatus, [
                self::FUNDED,
                self::CANCELLED,
                self::EXPIRED,
            ]),
            self::FUNDED => in_array($newStatus, [
                self::DELIVERED,
                self::DISPUTED,
                self::REFUNDED,
                self::EXPIRED,
            ]),
            self::DELIVERED => in_array($newStatus, [
                self::RELEASED,
                self::DISPUTED,
                self::REFUNDED,
            ]),
            self::DISPUTED => in_array($newStatus, [
                self::RELEASED,
                self::REFUNDED,
            ]),
            // Terminal states cannot transition
            self::RELEASED,
            self::REFUNDED,
            self::CANCELLED,
            self::EXPIRED => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::RELEASED,
            self::REFUNDED,
            self::CANCELLED,
            self::EXPIRED,
        ]);
    }

    public function isFundsLocked(): bool
    {
        return in_array($this, [
            self::FUNDED,
            self::DELIVERED,
            self::DISPUTED,
        ]);
    }
}
