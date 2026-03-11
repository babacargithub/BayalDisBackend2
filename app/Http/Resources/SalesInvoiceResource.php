<?php

namespace App\Http\Resources;

use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceResource extends JsonResource
{
    /**
     * @var SalesInvoice $this
     */
    public function toArray(Request $request): array
    {
        /* @var $this SalesInvoice */
        return [
            'id' => $this->id,
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone_number' => $this->customer->phone_number,
                'address' => $this->customer->address,
            ],
            'paid' => $this->paid,
            // Stored financial columns — never computed on-the-fly
            'total_amount' => $this->total_amount,
            'total_payments' => $this->total_payments,
            'total_remaining' => $this->total_amount - $this->total_payments,
            'total_estimated_profit' => $this->total_estimated_profit,
            'total_realized_profit' => $this->total_realized_profit,
            // Backward-compat alias used by ItemsDialog and PaymentsDialog
            'total' => $this->total_amount,
            'comment' => $this->comment,
            'should_be_paid_at' => $this->should_be_paid_at,
            'created_at' => $this->created_at,
            'payments' => [],
            'items' => [],
        ];
    }
}
