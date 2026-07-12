<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveCombatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monsterId' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ];
    }
}
