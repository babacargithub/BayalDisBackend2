<?php

namespace App\Models;

use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvoicePaymentMismatchException;
use App\Services\SalesInvoiceStatsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $total_amount Stored: sum of (price × quantity) for all invoice items.
 * @property int $total_payments Stored: sum of all payment amounts received.
 * @property int $total_estimated_profit Stored: sum of profit on all invoice items (full potential profit).
 * @property int $total_realized_profit Stored: sum of profit on payments (proportional earned profit).
 * @property SalesInvoiceStatus $status Stored: DRAFT | ISSUED | PARTIALLY_PAID | FULLY_PAID.
 *
 * Backward-compat aliases (delegate to stored columns — no DB query):
 * @property int $total Alias for total_amount.
 * @property int $total_paid Alias for total_payments.
 * @property int $total_remaining Computed as total_amount − total_payments.
 * @property mixed $invoice_number
 */
class SalesInvoice extends Model
{
    protected $fillable = [
        'customer_id',
        'paid',
        'should_be_paid_at',
        'comment',
        'commercial_id',
        'car_load_id',
        'invoice_number',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean',
            'should_be_paid_at' => 'datetime',
            'total_amount' => 'integer',
            'total_payments' => 'integer',
            'total_estimated_profit' => 'integer',
            'total_realized_profit' => 'integer',
            'status' => SalesInvoiceStatus::class,
        ];
    }

    protected $appends = [];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Vente::class)->where('type', 'INVOICE_ITEM');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }

    // =========================================================================
    // Boot
    // =========================================================================

    protected static function boot(): void
    {
        parent::boot();

        // Propagate paid/paid_at/should_be_paid_at changes down to vente items.
        static::updated(function (SalesInvoice $invoice) {
            if ($invoice->isDirty('paid')) {
                $invoice->items()->update([
                    'paid' => $invoice->paid,
                    'paid_at' => $invoice->paid ? now() : null,
                ]);
            }

            if ($invoice->isDirty('should_be_paid_at')) {
                $invoice->items()->update(['should_be_paid_at' => $invoice->should_be_paid_at]);
            }
        });
    }

    // =========================================================================
    // Backward-compat aliases (no DB queries — delegate to stored columns)
    // =========================================================================

    public function getTotalAttribute(): int
    {
        return $this->total_amount;
    }

    public function getTotalPaidAttribute(): int
    {
        return $this->total_payments;
    }

    public function getTotalRemainingAttribute(): int
    {
        return $this->total_amount - $this->total_payments;
    }

    // =========================================================================
    // Stored totals management
    // =========================================================================

    /**
     * Re-query and persist all stored financial totals and the payment status.
     *
     * Must be called whenever invoice items or payments change (saved or deleted).
     * Triggers are registered in the Vente and Payment model boot() methods.
     *
     * Also keeps the legacy `paid` boolean in sync so existing code relying
     * on it continues to work without modification.
     */
    public function recalculateStoredTotals(): void
    {
        $salesInvoiceStatsService = app(SalesInvoiceStatsService::class);

        $freshTotalAmount = $salesInvoiceStatsService->calculateTotalAmountForInvoice($this);
        $freshTotalEstimatedProfit = $salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($this);
        $freshTotalPayments = $salesInvoiceStatsService->calculateTotalPaymentsForInvoice($this);
        $freshTotalRealizedProfit = $salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($this);

        // FULLY_PAID is never set automatically — only markAsFullyPaid() may do that.
        // However, if the invoice is already FULLY_PAID and payments still cover the
        // total, we preserve that status (guard against silent demotion on minor updates).
        // Any reduction in payments that breaks coverage will demote appropriately.
        $currentStatus = $this->status ?? SalesInvoiceStatus::Draft;
        $paymentsStillCoverTotal = $freshTotalAmount > 0 && $freshTotalPayments >= $freshTotalAmount;

        $newStatus = match (true) {
            $currentStatus === SalesInvoiceStatus::FullyPaid && $paymentsStillCoverTotal => SalesInvoiceStatus::FullyPaid,
            $freshTotalPayments > 0 => SalesInvoiceStatus::PartiallyPaid,
            // Preserve Issued — an invoice issued to a customer must not be silently demoted
            // back to Draft when a payment is removed or rolled back.
            $currentStatus === SalesInvoiceStatus::Issued => SalesInvoiceStatus::Issued,
            default => SalesInvoiceStatus::Draft,
        };

        $this->total_amount = $freshTotalAmount;
        $this->total_estimated_profit = $freshTotalEstimatedProfit;
        $this->total_payments = $freshTotalPayments;
        $this->total_realized_profit = $freshTotalRealizedProfit;
        $this->status = $newStatus;
        $this->paid = $newStatus === SalesInvoiceStatus::FullyPaid;
        $this->save();
    }

    // =========================================================================
    // Business operations
    // =========================================================================

    /**
     * Transition this invoice to FULLY_PAID status.
     *
     * This is the ONLY authorised path to FULLY_PAID — recalculateStoredTotals()
     * never sets it automatically. Throws if payments do not exactly match the
     * invoice total, ensuring financial consistency before the transition.
     *
     * Refreshes the model from the database first so stale in-memory values
     * cannot cause a false positive or false negative validation.
     *
     * @throws InvoicePaymentMismatchException when total_payments ≠ total_amount.
     */
    public function markAsFullyPaid(): void
    {
        // Query fresh from the database — do not rely on any in-memory cached values
        // or previously stored columns, as the caller may hold a stale model instance.
        $freshTotalAmount = (int) $this->items()->selectRaw('SUM(price * quantity) as total')->value('total');
        $freshTotalPayments = (int) $this->payments()->selectRaw('SUM(amount) as total')->value('total');

        if ($freshTotalPayments !== $freshTotalAmount) {
            throw new InvoicePaymentMismatchException;
        }

        $this->status = SalesInvoiceStatus::FullyPaid;
        $this->paid = true;
        $this->save();
    }

    // =========================================================================
    // Financial computation helpers
    // =========================================================================

    /**
     * Compute the portion of this invoice's profit realized by a given payment amount.
     *
     * Formula: (total_estimated_profit / total_amount) × payment_amount
     *
     * Uses stored columns — no extra DB query required.
     * Returns 0 if total_amount is 0 to prevent division by zero.
     *
     * This is the single source of truth for realized-profit-per-payment computation.
     */
    public function computeRealizedProfitForPaymentAmount(int $paymentAmount): int
    {
        if (! $this->total_amount) {
            return 0;
        }

        return (int) round($this->total_estimated_profit / $this->total_amount * $paymentAmount);
    }
}
