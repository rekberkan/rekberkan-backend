<?php

declare(strict_types=1);

namespace App\Domain\Money;

use Brick\Money\Money as BrickMoney;
use Brick\Money\Currency;
use InvalidArgumentException;

final class Money
{
    private BrickMoney $amount;

    private function __construct(BrickMoney $amount)
    {
        $this->amount = $amount;
    }

    public static function IDR(int $minorUnits): self
    {
        return new self(BrickMoney::ofMinor($minorUnits, 'IDR'));
    }

    public static function fromFloat(float $amount): self
    {
        return new self(BrickMoney::of($amount, 'IDR'));
    }

    public static function zero(): self
    {
        return self::IDR(0);
    }

    public function add(self $other): self
    {
        $this->ensureSameCurrency($other);
        return new self($this->amount->plus($other->amount));
    }

    public function subtract(self $other): self
    {
        $this->ensureSameCurrency($other);
        return new self($this->amount->minus($other->amount));
    }

    public function multiply(int|float $multiplier): self
    {
        return new self($this->amount->multipliedBy($multiplier));
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount->isGreaterThan($other->amount);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount->isGreaterThanOrEqualTo($other->amount);
    }

    public function isLessThan(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount->isLessThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function getMinorAmount(): int
    {
        return $this->amount->getMinorAmount()->toInt();
    }

    public function getAmount(): string
    {
        return $this->amount->getAmount()->__toString();
    }

    public function getCurrency(): string
    {
        return $this->amount->getCurrency()->getCurrencyCode();
    }

    public function format(): string
    {
        return 'Rp ' . number_format($this->getMinorAmount() / 100, 0, ',', '.');
    }

    private function ensureSameCurrency(self $other): void
    {
        if ($this->getCurrency() !== $other->getCurrency()) {
            throw new InvalidArgumentException('Currency mismatch in money operation');
        }
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->getMinorAmount(),
            'currency' => $this->getCurrency(),
            'formatted' => $this->format(),
        ];
    }
}
