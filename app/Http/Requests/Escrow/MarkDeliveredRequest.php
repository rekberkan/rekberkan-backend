<?php

declare(strict_types=1);

namespace App\Http\Requests\Escrow;

use Illuminate\Foundation\Http\FormRequest;

class MarkDeliveredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'delivery_proof' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
