<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TenantConfig extends Model
{
    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'type',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
