<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

enum RiskTier: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    public function getScore(): int
    {
        return match($this) {
            self::LOW => 0,
            self::MEDIUM => 40,
            self::HIGH => 70,
            self::CRITICAL => 90,
        };
    }

    public static function fromScore(int $score): self
    {
        return match(true) {
            $score < 40 => self::LOW,
            $score < 70 => self::MEDIUM,
            $score < 90 => self::HIGH,
            default => self::CRITICAL,
        };
    }

    public function getActions(): array
    {
        return match($this) {
            self::LOW => ['allow_all'],
            self::MEDIUM => ['delay_withdrawal_24h', 'enhanced_monitoring'],
            self::HIGH => ['freeze_wallet', 'require_kyc', 'admin_review'],
            self::CRITICAL => ['permanent_lock', 'mandatory_kyc', 'fraud_investigation'],
        };
    }
}
