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
    private const SEQUENCE_TTL = 86400; // 24 hours in seconds

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

    /**
     * Get next sequence with automatic daily TTL.
     * 
     * Redis key auto-expires at midnight, ensuring daily reset.
     */
    private static function getNextSequence(int $tenantId): int
    {
        $key = "stan:sequence:{$tenantId}:" . date('Ymd');
        $redis = \Illuminate\Support\Facades\Redis::connection();
        
        // Increment atomically
        $sequence = $redis->incr($key);
        
        // Set TTL only on first increment (when key is new)
        if ($sequence === 1) {
            // Calculate seconds until midnight
            $midnight = strtotime('tomorrow 00:00:00');
            $secondsUntilMidnight = $midnight - time();
            
            // Set expiry to midnight (ensures daily reset)
            $redis->expire($key, $secondsUntilMidnight);
        }
        
        return $sequence;
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
