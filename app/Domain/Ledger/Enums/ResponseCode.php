<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * Response/Reason Codes (Bank-grade taxonomy)
 */
enum ResponseCode: string
{
    case APPROVED = 'APPROVED';
    case DO_NOT_HONOR = 'DO_NOT_HONOR';
    case INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    case SUSPECTED_FRAUD = 'SUSPECTED_FRAUD';
    case DUPLICATE = 'DUPLICATE';
    case INVALID_AMOUNT = 'INVALID_AMOUNT';
    case INVALID_TENANT = 'INVALID_TENANT';
    case SECURITY_VIOLATION = 'SECURITY_VIOLATION';
    case SYSTEM_MALFUNCTION = 'SYSTEM_MALFUNCTION';
    case ACCOUNT_FROZEN = 'ACCOUNT_FROZEN';
    case TRANSACTION_NOT_PERMITTED = 'TRANSACTION_NOT_PERMITTED';
    case LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    public function isSuccess(): bool
    {
        return $this === self::APPROVED;
    }

    public function httpStatus(): int
    {
        return match($this) {
            self::APPROVED => 200,
            self::INSUFFICIENT_FUNDS => 402,
            self::ACCOUNT_FROZEN => 403,
            self::SECURITY_VIOLATION => 403,
            self::DUPLICATE => 409,
            self::INVALID_AMOUNT => 400,
            self::INVALID_TENANT => 400,
            self::SUSPECTED_FRAUD => 403,
            self::TRANSACTION_NOT_PERMITTED => 403,
            self::LIMIT_EXCEEDED => 429,
            default => 400,
        };
    }
}
