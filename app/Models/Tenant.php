<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subdomain',
        'domain',
        'is_active',
        'config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
        'config' => '{}',
    ];

    /**
     * Default tenant configuration
     */
    public function getDefaultConfig(): array
    {
        return [
            'fee_percentage' => 5.00,
            'currency' => 'IDR',
            'min_escrow_amount' => 10000,
            'max_escrow_amount' => 100000000,
            'sla_auto_release_hours' => 72,
            'sla_auto_refund_hours' => 168,
            'risk_threshold_low' => 30,
            'risk_threshold_medium' => 60,
            'risk_threshold_high' => 80,
            'features' => [
                'vouchers_enabled' => true,
                'promotions_enabled' => true,
                'chat_enabled' => true,
                'disputes_enabled' => true,
            ],
        ];
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $config = array_merge($this->getDefaultConfig(), $this->config ?? []);
        return data_get($config, $key, $default);
    }

    // Relationships
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function platformWallet(): HasOne
    {
        return $this->hasOne(PlatformWallet::class);
    }

    public function escrows(): HasMany
    {
        return $this->hasMany(Escrow::class);
    }
}
