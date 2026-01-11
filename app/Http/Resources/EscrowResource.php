<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EscrowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'buyer' => [
                'id' => $this->buyer_id,
                'name' => $this->buyer->name ?? null,
            ],
            'seller' => [
                'id' => $this->seller_id,
                'name' => $this->seller->name ?? null,
            ],
            'amount' => $this->amount,
            'fee_amount' => $this->fee_amount,
            'net_amount' => $this->amount - $this->fee_amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'title' => $this->title,
            'description' => $this->description,
            'funded_at' => $this->funded_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'disputed_at' => $this->disputed_at?->toIso8601String(),
            'sla_auto_release_at' => $this->sla_auto_release_at?->toIso8601String(),
            'sla_auto_refund_at' => $this->sla_auto_refund_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'timeline' => $this->whenLoaded('timeline', function () {
                return $this->timeline->map(fn($event) => [
                    'event' => $event->event->value,
                    'actor_type' => $event->actor_type,
                    'created_at' => $event->created_at->toIso8601String(),
                ]);
            }),
        ];
    }
}
