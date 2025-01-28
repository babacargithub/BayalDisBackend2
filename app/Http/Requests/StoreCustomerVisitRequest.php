<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'visit_batch_id' => ['required', 'exists:visit_batches,id'],
            'visit_planned_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Le client est obligatoire',
            'customer_id.exists' => 'Le client sélectionné n\'existe pas',
            'visit_batch_id.required' => 'Le lot de visite est obligatoire',
            'visit_batch_id.exists' => 'Le lot de visite sélectionné n\'existe pas',
            'visit_planned_at.required' => 'La date de visite prévue est obligatoire',
            'visit_planned_at.date' => 'La date de visite prévue n\'est pas valide',
        ];
    }
} 