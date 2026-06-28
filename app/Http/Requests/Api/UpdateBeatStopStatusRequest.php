<?php

namespace App\Http\Requests\Api;

use App\Enums\BeatStopStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBeatStopStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', new Enum(BeatStopStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        $validValues = implode(', ', array_column(BeatStopStatus::cases(), 'value'));

        return [
            'status.required' => 'Le statut est obligatoire',
            'status.Illuminate\Validation\Rules\Enum' => "Le statut doit être l'un des suivants : {$validValues}",
        ];
    }
}
