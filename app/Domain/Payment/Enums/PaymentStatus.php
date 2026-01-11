<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

enum PaymentStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';

    public function isComplete(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return in_array($this, [self::FAILED, self::EXPIRED, self::CANCELLED]);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING]);
    }
}
