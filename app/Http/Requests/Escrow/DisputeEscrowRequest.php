<?php

declare(strict_types=1);

namespace App\Http\Requests\Escrow;

use Illuminate\Foundation\Http\FormRequest;

final class DisputeEscrowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
