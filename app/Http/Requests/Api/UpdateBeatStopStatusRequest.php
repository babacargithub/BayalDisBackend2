<?php

namespace App\Http\Requests\Api;

use App\Models\BeatStop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBeatStopStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                BeatStop::STATUS_PLANNED,
                BeatStop::STATUS_COMPLETED,
                BeatStop::STATUS_CANCELLED,
            ])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Le statut est obligatoire',
            'status.in' => 'Le statut doit être l\'un des suivants : planned, completed, cancelled',
        ];
    }
}
