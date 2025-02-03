<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\CustomerVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerVisitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var $this CustomerVisit */
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'name' => $this->customer->name,
            'phone_number' => $this->customer->phone_number,
            'address' => $this->customer->address,
            'status' => $this->status,
            'visit_planned_at' => $this->visit_planned_at,
            'visit_completed_at' => $this->visited_at,
            'notes' => $this->notes,
            'gps_coordinates' => $this->customer->gps_coordinates,
            'resulted_in_sale' => $this->resulted_in_sale,
            "customer_debt" => $this->customer->salesInvoices->sum("total_remaining"),
        ];
    }
}
