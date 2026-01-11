<?php

declare(strict_types=1);

namespace App\Domain\Ledger\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Retrieval Reference Number
 * Global unique ID for transaction lifecycle
 */
final class RRN
{
    private string $value;

    private function __construct(string $value)
    {
        if (!preg_match('/^[A-Z0-9]{12,24}$/', $value)) {
            throw new InvalidArgumentException('Invalid RRN format');
        }
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function generate(): self
    {
        // Format: YYMMDDHHMMSS + random (12 chars total)
        $timestamp = date('ymdHis');
        $random = strtoupper(substr(Str::uuid()->toString(), 0, 6));
        return new self(str_replace('-', '', $timestamp . $random));
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
