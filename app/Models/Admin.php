<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Hash;

final class Admin extends Authenticatable implements JWTSubject
{
    use HasFactory, HasRoles;

    protected $fillable = [
        'email',
        'password',
        'name',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'mfa_enabled' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'mfa_enabled' => false,
    ];

    /**
     * Admin roles (RBAC)
     */
    public const ROLE_SUPER_ADMIN = 'SUPER_ADMIN';
    public const ROLE_RISK_ADMIN = 'RISK_ADMIN';
    public const ROLE_FINANCE_ADMIN = 'FINANCE_ADMIN';
    public const ROLE_SUPPORT_ADMIN = 'SUPPORT_ADMIN';

    public static function roles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_RISK_ADMIN,
            self::ROLE_FINANCE_ADMIN,
            self::ROLE_SUPPORT_ADMIN,
        ];
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::make($value, [
            'memory' => 65536,
            'time' => 4,
            'threads' => 2,
        ]);
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'type' => 'admin',
            'role' => $this->role,
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function canPerformFinancialActions(): bool
    {
        return in_array($this->role, [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_FINANCE_ADMIN,
        ]);
    }

    // Relationships
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'actor');
    }

    public function makerActions(): HasMany
    {
        return $this->hasMany(MakerCheckerAction::class, 'maker_id');
    }

    public function checkerActions(): HasMany
    {
        return $this->hasMany(MakerCheckerAction::class, 'checker_id');
    }
}
