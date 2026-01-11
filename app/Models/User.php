<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;

final class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id',
        'email',
        'password',
        'name',
        'phone',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'frozen_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
    ];

    /**
     * Hash password using Argon2id with strong parameters
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::make($value, [
            'memory' => 65536, // 64 MB
            'time' => 4,
            'threads' => 2,
        ]);
        $this->attributes['password_changed_at'] = now();
    }

    /**
     * JWT identifier
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * JWT custom claims
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'type' => 'user',
            'verified' => $this->isVerified(),
        ];
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function riskScores(): HasMany
    {
        return $this->hasMany(RiskScore::class);
    }

    public function behaviorLogs(): HasMany
    {
        return $this->hasMany(UserBehaviorLog::class);
    }
}
