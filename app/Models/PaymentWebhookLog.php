<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PaymentWebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_id',
        'event_type',
        'payload',
        'signature',
        'signature_verified',
        'processed',
        'processed_at',
        'error_message',
        'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_verified' => 'boolean',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    protected $attributes = [
        'signature_verified' => false,
        'processed' => false,
    ];

    // This is append-only for audit
    public static function boot(): void
    {
        parent::boot();

        static::updating(function ($model) {
            // Only allow status updates
            if ($model->isDirty(['webhook_id', 'event_type', 'payload', 'signature'])) {
                throw new \RuntimeException('Webhook log immutable fields cannot be modified');
            }
        });
    }

    public function markAsProcessed(): void
    {
        $this->update([
            'processed' => true,
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'processed' => false,
            'error_message' => $error,
        ]);
    }
}
