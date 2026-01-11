<?php

declare(strict_types=1);

namespace App\Domain\Ledger\ValueObjects;

use InvalidArgumentException;

/**
 * System Trace Audit Number
 * Unique per tenant per day
 */
final class STAN
{
    private string $value;

    private function __construct(string $value)
    {
        if (!preg_match('/^[0-9]{6,12}$/', $value)) {
            throw new InvalidArgumentException('Invalid STAN format');
        }
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function generate(int $tenantId): self
    {
        // Format: YYMMDD + sequence (6 digits)
        $date = date('ymd');
        $sequence = str_pad((string) self::getNextSequence($tenantId), 6, '0', STR_PAD_LEFT);
        return new self($date . $sequence);
    }

    private static function getNextSequence(int $tenantId): int
    {
        // This will be implemented with Redis INCR for atomic sequence
        $key = "stan:sequence:{$tenantId}:" . date('Ymd');
        return \Illuminate\Support\Facades\Redis::incr($key);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
