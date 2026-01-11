<?php

declare(strict_types=1);

namespace App\Http\Requests\Deposit;

use Illuminate\Foundation\Http\FormRequest;

final class CreateDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:10000', 'max:100000000'],
            'payment_method' => ['required', 'string', 'in:bank_transfer,virtual_account,ewallet'],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Deposit amount must be at least Rp 10,000.',
            'amount.max' => 'Deposit amount must not exceed Rp 100,000,000.',
            'payment_method.required' => 'Payment method is required.',
            'payment_method.in' => 'Invalid payment method selected.',
        ];
    }
}
