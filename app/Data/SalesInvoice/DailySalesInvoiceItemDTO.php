<?php

namespace App\Data\SalesInvoice;

use App\Models\Payment;
use App\Models\SalesInvoice;

/**
 * Represents a single item in the daily sales timeline.
 *
 * A timeline item is either a sales invoice created on the filtered date,
 * or a payment collected on that date that belongs to an invoice from a previous day.
 *
 * Use the `rowType` field ('invoice' | 'payment') to distinguish between the two.
 * The `sortKey` field (ISO 8601 datetime) is used to sort items newest-first.
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class DailySalesInvoiceItemDTO
{
    public function __construct(
        // ── Discriminator ─────────────────────────────────────────────────────
        public string $rowType,   // 'invoice' | 'payment'
        public string $sortKey,   // ISO 8601, used for newest-first sorting
        public string $createdAt,

        // ── Shared by both row types ───────────────────────────────────────────
        public int $invoiceId,
        public string $customerName,

        // ── Invoice-specific (null on payment rows) ───────────────────────────
        public ?string $customerAddress = null,
        public ?string $commercialName = null,
        public ?int $totalAmount = null,
        public ?int $totalPayments = null,
        public ?int $totalRemaining = null,
        public ?int $totalEstimatedProfit = null,
        public ?int $totalRealizedProfit = null,
        public ?int $estimatedCommercialCommission = null,
        public ?int $deliveryCost = null,
        public ?string $status = null,

        // ── Payment-specific (null on invoice rows) ───────────────────────────
        public ?int $paymentId = null,
        public ?string $invoiceDate = null,  // date the invoice was originally created
        public ?int $paymentAmount = null,
        public ?int $paymentRealizedProfit = null,  // profit earned on this specific payment
        public ?int $amountPaid = null,       // invoice total_payments after this payment
        public ?int $amountRemaining = null,  // invoice total_remaining after this payment
        public ?string $paymentMethod = null,
    ) {}

    public static function fromInvoice(SalesInvoice $invoice): self
    {
        return new self(
            rowType: 'invoice',
            sortKey: $invoice->created_at->toIso8601String(),
            createdAt: $invoice->created_at->toIso8601String(),
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
        );
    }

    public static function fromPayment(Payment $payment): self
    {
        $invoice = $payment->salesInvoice;

        return new self(
            rowType: 'payment',
            sortKey: $payment->created_at->toIso8601String(),
            createdAt: $payment->created_at->toIso8601String(),
            invoiceId: $payment->sales_invoice_id,
            customerName: $invoice->customer->name,
            paymentId: $payment->id,
            invoiceDate: $invoice->created_at->toDateString(),
            paymentAmount: $payment->amount,
            paymentRealizedProfit: $payment->profit,
            amountPaid: $invoice->total_payments,
            amountRemaining: $invoice->total_remaining,
            paymentMethod: $payment->payment_method,
        );
    }

    public function isInvoice(): bool
    {
        return $this->rowType === 'invoice';
    }

    public function toArray(): array
    {
        $base = [
            'row_type' => $this->rowType,
            'created_at' => $this->createdAt,
            'invoice_id' => $this->invoiceId,
            'customer_name' => $this->customerName,
        ];

        if ($this->rowType === 'invoice') {
            return array_merge($base, [
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
            ]);
        }

        return array_merge($base, [
            'payment_id' => $this->paymentId,
            'invoice_date' => $this->invoiceDate,
            'payment_amount' => $this->paymentAmount,
            'amount_paid' => $this->amountPaid,
            'amount_remaining' => $this->amountRemaining,
            'payment_method' => $this->paymentMethod,
        ]);
    }
}
