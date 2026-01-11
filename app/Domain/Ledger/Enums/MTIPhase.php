<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * Message Type Indicator Phase (ISO 8583-inspired)
 */
enum MTIPhase: string
{
    case AUTH = 'AUTH';                    // 0100/0110 - Authorization/Hold
    case REVERSAL = 'REVERSAL';            // 0400/0410 - Void/Cancellation
    case PRESENTMENT = 'PRESENTMENT';      // Clearing/Settlement
    case ADJUSTMENT = 'ADJUSTMENT';        // Correction/Dispute

    public function description(): string
    {
        return match($this) {
            self::AUTH => 'Authorization - Reserve/Hold funds',
            self::REVERSAL => 'Reversal - Void authorization',
            self::PRESENTMENT => 'Presentment - Capture/Settlement',
            self::ADJUSTMENT => 'Adjustment - Correction or dispute',
        };
    }
}
