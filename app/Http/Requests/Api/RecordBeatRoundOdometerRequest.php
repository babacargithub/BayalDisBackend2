<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RecordBeatRoundOdometerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:start,end'],
            'km' => ['required', 'integer', 'min:0'],
            'vehicle_id' => ['required_if:type,start', 'nullable', 'integer', 'exists:vehicles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Le type de relevé est obligatoire (start ou end)',
            'type.in' => 'Le type doit être "start" (départ) ou "end" (arrivée)',
            'km.required' => 'Le kilométrage est obligatoire',
            'km.integer' => 'Le kilométrage doit être un entier',
            'km.min' => 'Le kilométrage doit être positif ou nul',
            'vehicle_id.required_if' => 'Le véhicule est obligatoire pour le relevé de départ',
            'vehicle_id.exists' => 'Véhicule introuvable',
        ];
    }
}
