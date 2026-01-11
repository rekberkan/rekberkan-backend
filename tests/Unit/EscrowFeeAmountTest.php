<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Escrow;
use App\Models\Tenant;
use Tests\TestCase;

class EscrowFeeAmountTest extends TestCase
{
    public function test_fee_amount_zero_is_preserved_on_creating(): void
    {
        $tenant = new Tenant(['config' => ['fee_percentage' => 5.00]]);

        $escrow = new Escrow([
            'amount' => 100_000,
            'fee_amount' => 0,
        ]);
        $escrow->setRelation('tenant', $tenant);

        $dispatcher = Escrow::getEventDispatcher();
        $dispatcher->dispatch('eloquent.creating: ' . Escrow::class, $escrow);

        $this->assertSame(0, $escrow->fee_amount);
    }
}
