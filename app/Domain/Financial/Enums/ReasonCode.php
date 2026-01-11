<?php

declare(strict_types=1);

namespace App\Domain\Financial\Enums;

enum ReasonCode: string
{
    // Escrow lifecycle
    case ESCROW_CREATED = 'ESCROW_CREATED';
    case ESCROW_FUNDED = 'ESCROW_FUNDED';
    case ESCROW_RELEASED = 'ESCROW_RELEASED';
    case ESCROW_REFUNDED = 'ESCROW_REFUNDED';
    case ESCROW_DISPUTED = 'ESCROW_DISPUTED';
    case ESCROW_CANCELLED = 'ESCROW_CANCELLED';
    
    // Deposit/Withdrawal
    case DEPOSIT_COMPLETED = 'DEPOSIT_COMPLETED';
    case WITHDRAWAL_APPROVED = 'WITHDRAWAL_APPROVED';
    case WITHDRAWAL_REJECTED = 'WITHDRAWAL_REJECTED';
    
    // Fees
    case PLATFORM_FEE = 'PLATFORM_FEE';
    case TRANSACTION_FEE = 'TRANSACTION_FEE';
    
    // Reconciliation
    case BALANCE_ADJUSTMENT = 'BALANCE_ADJUSTMENT';
    case SYSTEM_CORRECTION = 'SYSTEM_CORRECTION';
    
    // Compliance
    case AML_HOLD = 'AML_HOLD';
    case REGULATORY_FREEZE = 'REGULATORY_FREEZE';
}
