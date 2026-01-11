<?php

declare(strict_types=1);

namespace App\Http\Requests\Escrow;

use Illuminate\Foundation\Http\FormRequest;

final class CreateEscrowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seller_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'integer', 'min:10000', 'max:100000000'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
