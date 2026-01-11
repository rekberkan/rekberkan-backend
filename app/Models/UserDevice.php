<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_fingerprint_hash',
        'device_type',
        'device_name',
        'ip_address',
        'user_agent',
        'last_used_at',
        'is_trusted',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'is_trusted' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'is_trusted' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function trust(): void
    {
        $this->update(['is_trusted' => true]);
    }

    /**
     * Generate device fingerprint hash from request
     */
    public static function generateFingerprint(\Illuminate\Http\Request $request): string
    {
        $components = [
            $request->userAgent(),
            $request->header('Accept-Language'),
            $request->header('Accept-Encoding'),
        ];

        return hash('sha256', implode('|', $components));
    }
}
