<?php

declare(strict_types=1);

namespace App\Domain\Financial\Enums;

enum MessagePhase: string
{
    case INITIATED = 'INITIATED'; // Request received
    case VALIDATED = 'VALIDATED'; // Validation passed
    case LOCKED = 'LOCKED'; // Accounts locked
    case POSTED = 'POSTED'; // Ledger entries created
    case COMPLETED = 'COMPLETED'; // Transaction complete
    case FAILED = 'FAILED'; // Transaction failed
    case REVERSED = 'REVERSED'; // Transaction reversed

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::REVERSED]);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::INITIATED => in_array($target, [self::VALIDATED, self::FAILED]),
            self::VALIDATED => in_array($target, [self::LOCKED, self::FAILED]),
            self::LOCKED => in_array($target, [self::POSTED, self::FAILED]),
            self::POSTED => in_array($target, [self::COMPLETED, self::REVERSED]),
            self::COMPLETED => $target === self::REVERSED,
            default => false,
        };
    }
}
