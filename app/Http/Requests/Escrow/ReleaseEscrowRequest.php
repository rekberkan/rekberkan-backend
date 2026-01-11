<?php

declare(strict_types=1);

namespace App\Http\Requests\Escrow;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseEscrowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
