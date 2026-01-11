<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

enum RiskSignal: string
{
    case ACCOUNT_AGE = 'ACCOUNT_AGE';
    case DISPUTE_RATIO = 'DISPUTE_RATIO';
    case CANCEL_RATIO = 'CANCEL_RATIO';
    case RAPID_WITHDRAWAL = 'RAPID_WITHDRAWAL';
    case VOUCHER_ABUSE = 'VOUCHER_ABUSE';
    case IP_CHANGE_FREQUENCY = 'IP_CHANGE_FREQUENCY';
    case DEVICE_CHANGE_FREQUENCY = 'DEVICE_CHANGE_FREQUENCY';
    case SLA_VIOLATION_RATE = 'SLA_VIOLATION_RATE';
    case LARGE_ESCROW_PATTERN = 'LARGE_ESCROW_PATTERN';
    case NEGATIVE_FEEDBACK_RATIO = 'NEGATIVE_FEEDBACK_RATIO';

    public function getWeight(): int
    {
        return match($this) {
            self::ACCOUNT_AGE => -10,
            self::DISPUTE_RATIO => 25,
            self::CANCEL_RATIO => 15,
            self::RAPID_WITHDRAWAL => 20,
            self::VOUCHER_ABUSE => 30,
            self::IP_CHANGE_FREQUENCY => 10,
            self::DEVICE_CHANGE_FREQUENCY => 10,
            self::SLA_VIOLATION_RATE => 15,
            self::LARGE_ESCROW_PATTERN => 20,
            self::NEGATIVE_FEEDBACK_RATIO => 25,
        };
    }
}
