<?php

declare(strict_types=1);

namespace App\Http\Resources\;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method->value,
            'status' => $this->status->value,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'gateway_reference' => $this->gateway_reference,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
