<?php

namespace App\Http\Requests\Api;

use App\Enums\ProspectionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreCustomerProspectionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', new Enum(ProspectionStatus::class)],
            'notes' => ['nullable', 'string'],
            'scheduled_revisit_date' => [
                'nullable',
                'date',
                'required_if:status,'.ProspectionStatus::OwnerAbsent->value,
                'required_if:status,'.ProspectionStatus::HasCurrentStock->value,
            ],
        ];
    }

    public function messages(): array
    {
        $validValues = implode(', ', array_column(ProspectionStatus::cases(), 'value'));

        return [
            'status.required' => 'Le statut est obligatoire',
            'status.Illuminate\Validation\Rules\Enum' => "Le statut doit être l'un des suivants : {$validValues}",
            'scheduled_revisit_date.required_if' => 'La date de revisit est obligatoire pour ce statut',
        ];
    }
}
