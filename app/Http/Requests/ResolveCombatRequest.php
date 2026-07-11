<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Intencja walki. Klient mówi CO robi (monsterId) + klucz idempotencji.
 * Świadomie NIE ma tu pól nagród — XP/gold/level liczy serwer. Nawet jeśli
 * klient dorzuci `gold`/`xp` do body, kontroler ich nie czyta.
 */
final class ResolveCombatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autoryzacja: middleware supabase.auth + owns.character
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monsterId' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ];
    }
}
