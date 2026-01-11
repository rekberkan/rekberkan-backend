<?php

declare(strict_types=1);

namespace App\Http\Requests\Deposit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Domain\Payment\Enums\PaymentMethod;

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
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Minimum deposit amount is Rp 100.00',
            'amount.max' => 'Maximum deposit amount is Rp 1,000,000.00',
        ];
    }
}
