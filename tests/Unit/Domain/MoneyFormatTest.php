<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyFormatTest extends TestCase
{
    public function test_formats_idr_with_zero_scale(): void
    {
        $money = Money::IDR(10000);

        $this->assertSame('Rp 10.000', $money->format());
    }
}
