<?php

declare(strict_types=1);

namespace App\Domain\Financial\Enums;

enum ProcessingCode: string
{
    case DEPOSIT = '200000'; // Account credit
    case WITHDRAWAL = '010000'; // Account debit
    case TRANSFER = '400000'; // Transfer between accounts
    case ESCROW_LOCK = '500001'; // Lock funds in escrow
    case ESCROW_RELEASE = '500002'; // Release escrow to recipient
    case ESCROW_REFUND = '500003'; // Refund escrow to sender
    case FEE_DEBIT = '990001'; // Platform fee deduction
    case BALANCE_INQUIRY = '310000'; // Balance check (non-financial)

    public function isFinancial(): bool
    {
        return !in_array($this, [self::BALANCE_INQUIRY]);
    }

    public function requiresLedgerEntry(): bool
    {
        return $this->isFinancial();
    }
}
