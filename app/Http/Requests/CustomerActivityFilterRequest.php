<?php

namespace App\Http\Requests;

use App\Data\Customer\CustomerActivityFilter;
use Illuminate\Foundation\Http\FormRequest;

class CustomerActivityFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'filter_type' => ['nullable', 'string'],
            'sector_id' => ['nullable', 'integer'],
            'minimum_amount' => ['nullable', 'integer', 'min:0'],
            'minimum_average_amount' => ['nullable', 'integer', 'min:0'],
            'inactive_days' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.date' => 'La date de début est invalide.',
            'end_date.date' => 'La date de fin est invalide.',
            'minimum_amount.integer' => 'Le montant minimum doit être un nombre entier.',
            'minimum_average_amount.integer' => 'La moyenne minimale doit être un nombre entier.',
            'inactive_days.integer' => 'Le nombre de jours d\'inactivité doit être un nombre entier.',
            'inactive_days.min' => 'Le nombre de jours d\'inactivité doit être supérieur ou égal à 1.',
        ];
    }

    public function toActivityFilter(): CustomerActivityFilter
    {
        return new CustomerActivityFilter(
            filterType: $this->input('filter_type') ?: CustomerActivityFilter::TYPE_WITH_INVOICE,
            startDate: $this->input('start_date') ?: now()->startOfMonth()->toDateString(),
            endDate: $this->input('end_date') ?: now()->toDateString(),
            sectorId: $this->filled('sector_id') ? (int) $this->input('sector_id') : null,
            minimumAmount: $this->filled('minimum_amount') ? (int) $this->input('minimum_amount') : null,
            minimumAverageAmount: $this->filled('minimum_average_amount') ? (int) $this->input('minimum_average_amount') : null,
            inactiveDays: $this->filled('inactive_days') ? (int) $this->input('inactive_days') : null,
        );
    }
}
