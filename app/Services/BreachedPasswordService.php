<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BreachedPasswordService
{
    private const HIBP_API_URL = 'https://api.pwnedpasswords.com/range/';

    /**
     * Check if password exists in HaveIBeenPwned database
     * 
     * @param string $password
     * @return bool True if password is breached
     */
    public function isPasswordBreached(string $password): bool
    {
        try {
            // Hash password dengan SHA-1
            $hash = strtoupper(sha1($password));
            $prefix = substr($hash, 0, 5);
            $suffix = substr($hash, 5);

            // Request ke HIBP API dengan k-anonymity
            $response = Http::timeout(5)
                ->withHeaders([
                    'Add-Padding' => 'true',
                    'User-Agent' => 'Rekberkan-Security-Check',
                ])
                ->get(self::HIBP_API_URL . $prefix);

            if (!$response->successful()) {
                Log::warning('Failed to check HIBP database', [
                    'status' => $response->status(),
                ]);
                return false; // Fail open untuk availability
            }

            // Parse response dan cari suffix
            $hashes = collect(explode("\n", $response->body()))
                ->map(fn($line) => explode(':', trim($line)))
                ->filter(fn($parts) => count($parts) === 2)
                ->mapWithKeys(fn($parts) => [$parts[0] => (int) $parts[1]]);

            return $hashes->has($suffix);

        } catch (\Exception $e) {
            Log::error('Error checking breached password', [
                'error' => $e->getMessage(),
            ]);
            return false; // Fail open
        }
    }

    /**
     * Get breach count for a password
     */
    public function getBreachCount(string $password): int
    {
        try {
            $hash = strtoupper(sha1($password));
            $prefix = substr($hash, 0, 5);
            $suffix = substr($hash, 5);

            $response = Http::timeout(5)
                ->withHeaders(['Add-Padding' => 'true'])
                ->get(self::HIBP_API_URL . $prefix);

            if (!$response->successful()) {
                return 0;
            }

            $hashes = collect(explode("\n", $response->body()))
                ->map(fn($line) => explode(':', trim($line)))
                ->filter(fn($parts) => count($parts) === 2)
                ->mapWithKeys(fn($parts) => [$parts[0] => (int) $parts[1]]);

            return $hashes->get($suffix, 0);

        } catch (\Exception $e) {
            Log::error('Error getting breach count', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
