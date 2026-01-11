<?php

declare(strict_types=1);

namespace App\Http\Requests\Escrow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateEscrowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Get tenant_id from request context (set by ResolveTenant middleware)
        $tenantId = $this->attributes->get('tenant_id') ?? $this->user()?->tenant_id;

        return [
            // Tenant-scoped seller validation
            'seller_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($tenantId) {
                    if ($tenantId) {
                        $query->where('tenant_id', $tenantId);
                    }
                }),
            ],
            'amount' => ['required', 'integer', 'min:10000', 'max:100000000'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'seller_id.required' => 'Seller ID is required.',
            'seller_id.exists' => 'Seller not found or does not belong to your tenant.',
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Amount must be at least Rp 10,000.',
            'amount.max' => 'Amount must not exceed Rp 100,000,000.',
            'title.required' => 'Title is required.',
            'title.max' => 'Title must not exceed 255 characters.',
            'description.max' => 'Description must not exceed 2000 characters.',
        ];
    }
}
