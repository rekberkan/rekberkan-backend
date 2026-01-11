<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * Chart of Accounts
 */
enum AccountType: string
{
    case CUSTOMER_AVAILABLE = 'CUSTOMER_AVAILABLE';
    case CUSTOMER_LOCKED = 'CUSTOMER_LOCKED';
    case PLATFORM_AVAILABLE = 'PLATFORM_AVAILABLE';
    case PLATFORM_LOCKED = 'PLATFORM_LOCKED';
    case CLEARING_SUSPENSE = 'CLEARING_SUSPENSE';
    case FEES_REVENUE = 'FEES_REVENUE';
    case CASHBACK_EXPENSE = 'CASHBACK_EXPENSE';
    case ADJUSTMENTS = 'ADJUSTMENTS';

    public function isAsset(): bool
    {
        return in_array($this, [
            self::CUSTOMER_AVAILABLE,
            self::CUSTOMER_LOCKED,
            self::PLATFORM_AVAILABLE,
            self::PLATFORM_LOCKED,
            self::CLEARING_SUSPENSE,
        ]);
    }

    public function isRevenue(): bool
    {
        return $this === self::FEES_REVENUE;
    }

    public function isExpense(): bool
    {
        return $this === self::CASHBACK_EXPENSE;
    }

    public function normalBalance(): string
    {
        return match(true) {
            $this->isAsset() => 'debit',
            $this->isRevenue() => 'credit',
            $this->isExpense() => 'debit',
            default => 'debit',
        };
    }
}
