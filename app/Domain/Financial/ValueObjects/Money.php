<?php

declare(strict_types=1);

namespace App\Domain\Financial\ValueObjects;

use Brick\Money\Money as BrickMoney;
use Brick\Money\Currency;
use JsonSerializable;
use Stringable;

final class Money implements JsonSerializable, Stringable
{
    private BrickMoney $amount;

    private function __construct(BrickMoney $amount)
    {
        $this->amount = $amount;
    }

    /**
     * Create Money from integer amount (in minor units/cents).
     * 
     * Example: Money::fromMinorUnits(150000, 'IDR') = Rp 1,500.00
     */
    public static function fromMinorUnits(int $amount, string $currency = 'IDR'): self
    {
        return new self(
            BrickMoney::ofMinor($amount, Currency::of($currency))
        );
    }

    /**
     * Create Money from major units (rupiah).
     * 
     * Example: Money::fromMajorUnits('1500.50', 'IDR') = Rp 1,500.50
     */
    public static function fromMajorUnits(string $amount, string $currency = 'IDR'): self
    {
        return new self(
            BrickMoney::of($amount, Currency::of($currency))
        );
    }

    /**
     * Create zero amount.
     */
    public static function zero(string $currency = 'IDR'): self
    {
        return self::fromMinorUnits(0, $currency);
    }

    /**
     * Get amount in minor units (cents) for database storage.
     */
    public function getMinorUnits(): int
    {
        return (int) $this->amount->getMinorAmount()->toInt();
    }

    /**
     * Get amount in major units (rupiah) for display.
     */
    public function getMajorUnits(): string
    {
        return $this->amount->getAmount()->toFloat();
    }

    /**
     * Get currency code.
     */
    public function getCurrency(): string
    {
        return $this->amount->getCurrency()->getCurrencyCode();
    }

    /**
     * Add money (returns new Money instance).
     */
    public function add(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    /**
     * Subtract money (returns new Money instance).
     */
    public function subtract(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    /**
     * Multiply by scalar (returns new Money instance).
     */
    public function multiply(int|float|string $multiplier): self
    {
        return new self($this->amount->multipliedBy($multiplier));
    }

    /**
     * Divide by scalar (returns new Money instance).
     */
    public function divide(int|float|string $divisor): self
    {
        return new self($this->amount->dividedBy($divisor));
    }

    /**
     * Check if zero.
     */
    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    /**
     * Check if positive.
     */
    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    /**
     * Check if negative.
     */
    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    /**
     * Compare with another Money instance.
     * Returns: -1 if less, 0 if equal, 1 if greater
     */
    public function compareTo(self $other): int
    {
        return $this->amount->compareTo($other->amount);
    }

    /**
     * Check if equal.
     */
    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    /**
     * Check if greater than.
     */
    public function greaterThan(self $other): bool
    {
        return $this->amount->isGreaterThan($other->amount);
    }

    /**
     * Check if greater than or equal.
     */
    public function greaterThanOrEqual(self $other): bool
    {
        return $this->amount->isGreaterThanOrEqualTo($other->amount);
    }

    /**
     * Check if less than.
     */
    public function lessThan(self $other): bool
    {
        return $this->amount->isLessThan($other->amount);
    }

    /**
     * Absolute value (returns new Money instance).
     */
    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    /**
     * Negate / change sign (returns new Money instance).
     */
    public function negate(): self
    {
        return new self($this->amount->negated());
    }

    /**
     * Format for display.
     */
    public function format(): string
    {
        $currency = $this->getCurrency();
        $formatted = number_format((float) $this->getMajorUnits(), 2, ',', '.');
        
        return match ($currency) {
            'IDR' => 'Rp ' . $formatted,
            'USD' => '$' . $formatted,
            'EUR' => '€' . $formatted,
            default => $currency . ' ' . $formatted,
        };
    }

    /**
     * JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->getMinorUnits(),
            'currency' => $this->getCurrency(),
            'formatted' => $this->format(),
        ];
    }

    /**
     * String representation.
     */
    public function __toString(): string
    {
        return $this->format();
    }
}
