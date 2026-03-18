<?php

namespace App\Data\SalesInvoice;

use App\Models\SalesInvoice;

/**
 * Represents the financial snapshot of a single sales invoice for the daily summary view.
 *
 * All monetary values are integers (XOF, no sub-units).
 * Built from the stored columns on SalesInvoice — no extra queries needed.
 */
class SalesInvoiceDailySummaryDTO
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $customerName,
        public readonly string $customerAddress,
        public readonly ?string $commercialName,
        public readonly int $totalAmount,
        public readonly int $totalPayments,
        public readonly int $totalRemaining,
        public readonly int $totalEstimatedProfit,
        public readonly int $totalRealizedProfit,
        public readonly int $estimatedCommercialCommission,
        public readonly int $deliveryCost,
        public readonly string $status,
        public readonly string $createdAt,
    ) {}

    public static function fromInvoice(SalesInvoice $invoice): self
    {
        return new self(
            invoiceId: $invoice->id,
            customerName: $invoice->customer->name,
            customerAddress: $invoice->customer->address ?? '',
            commercialName: $invoice->commercial?->name,
            totalAmount: $invoice->total_amount,
            totalPayments: $invoice->total_payments,
            totalRemaining: $invoice->total_amount - $invoice->total_payments,
            totalEstimatedProfit: $invoice->total_estimated_profit,
            totalRealizedProfit: $invoice->total_realized_profit,
            estimatedCommercialCommission: $invoice->estimated_commercial_commission,
            deliveryCost: $invoice->delivery_cost ?? 0,
            status: $invoice->status->value,
            createdAt: $invoice->created_at->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'customer_name' => $this->customerName,
            'customer_address' => $this->customerAddress,
            'commercial_name' => $this->commercialName,
            'total_amount' => $this->totalAmount,
            'total_payments' => $this->totalPayments,
            'total_remaining' => $this->totalRemaining,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'estimated_commercial_commission' => $this->estimatedCommercialCommission,
            'delivery_cost' => $this->deliveryCost,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];
    }
}
