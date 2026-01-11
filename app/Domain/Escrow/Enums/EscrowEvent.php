<?php

declare(strict_types=1);

namespace App\Domain\Escrow\Enums;

enum EscrowEvent: string
{
    case CREATED = 'CREATED';
    case FUNDED = 'FUNDED';
    case DELIVERED = 'DELIVERED';
    case DELIVERY_CONFIRMED = 'DELIVERY_CONFIRMED';
    case RELEASED = 'RELEASED';
    case REFUNDED = 'REFUNDED';
    case DISPUTED = 'DISPUTED';
    case DISPUTE_RESOLVED = 'DISPUTE_RESOLVED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case AUTO_RELEASED = 'AUTO_RELEASED';
    case AUTO_REFUNDED = 'AUTO_REFUNDED';
    case ADMIN_INTERVENTION = 'ADMIN_INTERVENTION';
    case PARTIAL_RELEASE = 'PARTIAL_RELEASE';

    public function isSystemGenerated(): bool
    {
        return in_array($this, [
            self::AUTO_RELEASED,
            self::AUTO_REFUNDED,
            self::EXPIRED,
        ]);
    }

    public function requiresAdminApproval(): bool
    {
        return in_array($this, [
            self::ADMIN_INTERVENTION,
            self::PARTIAL_RELEASE,
            self::DISPUTE_RESOLVED,
        ]);
    }
}
