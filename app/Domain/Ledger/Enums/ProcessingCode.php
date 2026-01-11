<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * Processing/Transaction Codes
 */
enum ProcessingCode: string
{
    case DEPOSIT = 'DEPOSIT';
    case WITHDRAW = 'WITHDRAW';
    case ESCROW_LOCK = 'ESCROW_LOCK';
    case ESCROW_RELEASE = 'ESCROW_RELEASE';
    case ESCROW_REFUND = 'ESCROW_REFUND';
    case FEE = 'FEE';
    case CASHBACK = 'CASHBACK';
    case ADJUSTMENT = 'ADJUSTMENT';

    public function isEscrowRelated(): bool
    {
        return in_array($this, [
            self::ESCROW_LOCK,
            self::ESCROW_RELEASE,
            self::ESCROW_REFUND,
        ]);
    }

    public function isMoneyMovement(): bool
    {
        return in_array($this, [
            self::DEPOSIT,
            self::WITHDRAW,
        ]);
    }
}
