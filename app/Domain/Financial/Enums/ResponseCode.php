<?php

declare(strict_types=1);

namespace App\Domain\Financial\Enums;

enum ResponseCode: string
{
    // Success
    case APPROVED = '00';
    
    // Validation errors
    case INVALID_AMOUNT = '13';
    case INVALID_ACCOUNT = '14';
    case INVALID_TRANSACTION = '12';
    
    // Business logic errors
    case INSUFFICIENT_FUNDS = '51';
    case EXCEEDS_LIMIT = '61';
    case ACCOUNT_INACTIVE = '62';
    case ACCOUNT_BLOCKED = '78';
    
    // System errors
    case SYSTEM_ERROR = '96';
    case TIMEOUT = '68';
    case DUPLICATE_TRANSACTION = '94';
    
    // Security
    case SECURITY_VIOLATION = '63';
    case SUSPECTED_FRAUD = '59';

    public function isSuccess(): bool
    {
        return $this === self::APPROVED;
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::TIMEOUT, self::SYSTEM_ERROR]);
    }
}
