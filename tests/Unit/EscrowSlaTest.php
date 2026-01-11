<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Escrow\Enums\EscrowStatus;
use App\Models\Escrow;
use Tests\TestCase;

class EscrowSlaTest extends TestCase
{
    public function test_is_overdue_sla_returns_false_when_release_at_null(): void
    {
        $escrow = new Escrow();
        $escrow->status = EscrowStatus::DELIVERED;
        $escrow->sla_auto_release_at = null;

        $this->assertFalse($escrow->isOverdueSLA());
    }

    public function test_is_expired_returns_false_when_refund_at_null(): void
    {
        $escrow = new Escrow();
        $escrow->status = EscrowStatus::CREATED;
        $escrow->sla_auto_refund_at = null;

        $this->assertFalse($escrow->isExpired());
    }
}
