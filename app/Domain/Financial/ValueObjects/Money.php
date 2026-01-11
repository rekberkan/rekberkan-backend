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
     * Example: Money::fromMinorUnits(150000) = Rp 1,500.00
     */
    public static function fromMinorUnits(int $amount): self
    {
        return new self(
            BrickMoney::ofMinor($amount, Currency::of('IDR'))
        );
    }

    /**
     * Create Money from major units (rupiah).
     * 
     * Example: Money::fromMajorUnits('1500.50') = Rp 1,500.50
     */
    public static function fromMajorUnits(string $amount): self
    {
        return new self(
            BrickMoney::of($amount, Currency::of('IDR'))
        );
    }

    /**
     * Create zero amount.
     */
    public static function zero(): self
    {
        return self::fromMinorUnits(0);
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
     * Add money.
     */
    public function add(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    /**
     * Subtract money.
     */
    public function subtract(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    /**
     * Multiply by scalar.
     */
    public function multiply(int|float|string $multiplier): self
    {
        return new self($this->amount->multipliedBy($multiplier));
    }

    /**
     * Divide by scalar.
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
     * Absolute value.
     */
    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    /**
     * Negate (change sign).
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
        return 'Rp ' . number_format((float) $this->getMajorUnits(), 2, ',', '.');
    }

    /**
     * JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->getMinorUnits(),
            'currency' => 'IDR',
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
