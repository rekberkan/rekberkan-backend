<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

enum PaymentMethod: string
{
    case VIRTUAL_ACCOUNT = 'VIRTUAL_ACCOUNT';
    case EWALLET = 'EWALLET';
    case RETAIL_OUTLET = 'RETAIL_OUTLET';
    case CREDIT_CARD = 'CREDIT_CARD';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case QRIS = 'QRIS';

    public function xenditChannel(): string
    {
        return match($this) {
            self::VIRTUAL_ACCOUNT => 'VIRTUAL_ACCOUNT',
            self::EWALLET => 'EWALLET',
            self::RETAIL_OUTLET => 'RETAIL_OUTLET',
            self::CREDIT_CARD => 'CREDIT_CARD',
            self::BANK_TRANSFER => 'BANK_TRANSFER',
            self::QRIS => 'QRIS',
        };
    }
}
