<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Financial\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_creates_money_from_minor_units(): void
    {
        $money = Money::fromMinorUnits(150000);
        
        $this->assertEquals(150000, $money->getMinorUnits());
    }

    public function test_creates_money_from_major_units(): void
    {
        $money = Money::fromMajorUnits('1500.50');
        
        $this->assertEquals(150050, $money->getMinorUnits());
    }

    public function test_adds_money_correctly(): void
    {
        $money1 = Money::fromMinorUnits(100000);
        $money2 = Money::fromMinorUnits(50000);
        
        $result = $money1->add($money2);
        
        $this->assertEquals(150000, $result->getMinorUnits());
    }

    public function test_subtracts_money_correctly(): void
    {
        $money1 = Money::fromMinorUnits(150000);
        $money2 = Money::fromMinorUnits(50000);
        
        $result = $money1->subtract($money2);
        
        $this->assertEquals(100000, $result->getMinorUnits());
    }

    public function test_compares_money_correctly(): void
    {
        $money1 = Money::fromMinorUnits(100000);
        $money2 = Money::fromMinorUnits(150000);
        
        $this->assertTrue($money1->lessThan($money2));
        $this->assertTrue($money2->greaterThan($money1));
        $this->assertFalse($money1->equals($money2));
    }

    public function test_formats_money_for_display(): void
    {
        $money = Money::fromMinorUnits(150000);
        
        $formatted = $money->format();
        
        $this->assertStringContainsString('Rp', $formatted);
        $this->assertStringContainsString('1.500', $formatted);
    }
}
