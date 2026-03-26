<?php

namespace App\Models;

use App\Exceptions\DayCaisseClosedException;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Jobs\RecalculateDailyCommissionJob;
use App\Services\AccountService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * @property int $amount Payment amount in XOF.
 * @property int $profit Realized profit allocated to this payment (proportional share of invoice profit).
 * @property int $commercial_commission Commission owed to the commercial for this payment (proportional share of invoice commission).
 */
class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'sales_invoice_id',
        'amount',
        'profit',
        'commercial_commission',
        'payment_method',
        'comment',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'profit' => 'integer',
            'commercial_commission' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected $appends = [];

    protected static function boot(): void
    {
        parent::boot();

        /**
         * Auto-populate profit when a payment is created.
         * Uses the invoice's stored total_amount and total_estimated_profit columns,
         * so no extra DB query is needed to load items.
         */
        static::creating(function (Payment $payment): void {
            // Block payments when the commercial's caisse has been locked for today.
            if ($payment->user_id !== null) {
                $commercial = Commercial::where('user_id', $payment->user_id)->first();

                if ($commercial?->caisse?->isLockedForToday()) {
                    throw new DayCaisseClosedException(
                        "La caisse de «{$commercial->name}» a été clôturée pour aujourd'hui. "
                        .'Aucun paiement ne peut être enregistré jusqu\'à demain.'
                    );
                }
            }

            if ($payment->sales_invoice_id !== null) {
                $invoice = SalesInvoice::find($payment->sales_invoice_id);

                if ($invoice !== null) {
                    $payment->profit = $invoice->computeRealizedProfitForPaymentAmount($payment->amount);
                    $payment->commercial_commission = $invoice->computeCommercialCommissionForPaymentAmount($payment->amount);
                }
            }
        });

        /**
         * After a payment is created:
         *   - Recalculate the invoice stored totals.
         *   - Dispatch commission recalculation job.
         *   - Credit the commercial's personal caisse and their COMMERCIAL_COLLECTED account
         *     so the caisse balance reflects the cash they are now physically holding.
         */
        static::created(function (Payment $payment): void {
            if ($payment->sales_invoice_id !== null) {
                SalesInvoice::find($payment->sales_invoice_id)?->recalculateStoredTotals();
            }

            RecalculateDailyCommissionJob::dispatch(
                userId: $payment->user_id,
                workDay: $payment->created_at->toDateString(),
                salesInvoiceId: $payment->sales_invoice_id,
            );

            self::creditCommercialCaisseForPayment($payment);
        });

        /**
         * After a payment is updated, recalculate totals and commission.
         * Also adjust the commercial's caisse/account for any amount change.
         */
        static::updated(function (Payment $payment): void {
            if ($payment->sales_invoice_id !== null) {
                SalesInvoice::find($payment->sales_invoice_id)?->recalculateStoredTotals();
            }

            RecalculateDailyCommissionJob::dispatch(
                userId: $payment->user_id,
                workDay: $payment->created_at->toDateString(),
                salesInvoiceId: $payment->sales_invoice_id,
            );

            if ($payment->isDirty('amount')) {
                self::adjustCommercialCaisseForAmountChange(
                    payment: $payment,
                    oldAmount: (int) $payment->getOriginal('amount'),
                    newAmount: $payment->amount,
                );
            }
        });

        /**
         * After a payment is deleted, recalculate the invoice's stored totals
         * and reverse the commercial's caisse/account credit.
         */
        static::deleted(function (Payment $payment): void {
            if ($payment->sales_invoice_id !== null) {
                SalesInvoice::find($payment->sales_invoice_id)?->recalculateStoredTotals();
            }

            RecalculateDailyCommissionJob::dispatch(
                userId: $payment->user_id,
                workDay: $payment->created_at->toDateString(),
                salesInvoiceId: $payment->sales_invoice_id,
            );

            self::reverseCommercialCaisseForPayment($payment);
        });
    }

    // ── Caisse / account integration ────────────────────────────────────────

    /**
     * When a payment is received by a commercial, credit their personal caisse
     * and their COMMERCIAL_COLLECTED account so the ledger reflects the cash they hold.
     *
     * Skipped if there is no user_id, if no commercial profile exists for that user,
     * or if the commercial has not yet been assigned a caisse.
     */
    private static function creditCommercialCaisseForPayment(Payment $payment): void
    {
        if ($payment->user_id === null) {
            return;
        }

        $commercial = Commercial::where('user_id', $payment->user_id)->first();

        if ($commercial === null || $commercial->caisse === null) {
            return;
        }

        $accountService = app(AccountService::class);
        $accountService->depositToCaisseAndCreditAccount(
            caisse: $commercial->caisse,
            account: $accountService->getOrCreateCommercialCollectedAccount($commercial),
            amount: $payment->amount,
            caisseLabel: "Paiement — Facture #{$payment->sales_invoice_id}",
            accountLabel: "Paiement — Facture #{$payment->sales_invoice_id}",
            referenceType: 'PAYMENT',
            referenceId: $payment->id,
        );
    }

    /**
     * Reverse the commercial's caisse/account credit when a payment is deleted.
     *
     * If the account balance is already too low (e.g., the payment had already been
     * included in a versement), the reversal is skipped and a warning is logged.
     * Manual reconciliation may be needed in that case.
     */
    private static function reverseCommercialCaisseForPayment(Payment $payment): void
    {
        if ($payment->user_id === null) {
            return;
        }

        $commercial = Commercial::where('user_id', $payment->user_id)->first();

        if ($commercial === null || $commercial->caisse === null) {
            return;
        }

        $accountService = app(AccountService::class);

        try {
            $accountService->withdrawFromCaisseAndDebitAccount(
                caisse: $commercial->caisse,
                account: $accountService->getOrCreateCommercialCollectedAccount($commercial),
                amount: $payment->amount,
                caisseLabel: "Annulation paiement — Facture #{$payment->sales_invoice_id}",
                accountLabel: "Annulation paiement — Facture #{$payment->sales_invoice_id}",
                referenceType: 'PAYMENT_CANCELLATION',
                referenceId: $payment->id,
            );
        } catch (InsufficientAccountBalanceException) {
            Log::warning(
                "Payment #{$payment->id} deleted but CommercialCollected account for «{$commercial->name}» "
                .'has insufficient balance for reversal. Manual reconciliation may be needed.',
                ['payment_id' => $payment->id, 'commercial_id' => $commercial->id, 'amount' => $payment->amount]
            );
        }
    }

    /**
     * Adjust the commercial's caisse/account when a payment's amount is modified.
     * Credits the positive difference or debits the negative difference.
     */
    private static function adjustCommercialCaisseForAmountChange(
        Payment $payment,
        int $oldAmount,
        int $newAmount,
    ): void {
        if ($payment->user_id === null) {
            return;
        }

        $commercial = Commercial::where('user_id', $payment->user_id)->first();

        if ($commercial === null || $commercial->caisse === null) {
            return;
        }

        $difference = $newAmount - $oldAmount;

        if ($difference === 0) {
            return;
        }

        $accountService = app(AccountService::class);
        $collectedAccount = $accountService->getOrCreateCommercialCollectedAccount($commercial);

        if ($difference > 0) {
            $accountService->depositToCaisseAndCreditAccount(
                caisse: $commercial->caisse,
                account: $collectedAccount,
                amount: $difference,
                caisseLabel: "Ajustement paiement — Facture #{$payment->sales_invoice_id}",
                accountLabel: "Ajustement paiement — Facture #{$payment->sales_invoice_id}",
                referenceType: 'PAYMENT_ADJUSTMENT',
                referenceId: $payment->id,
            );
        } else {
            try {
                $accountService->withdrawFromCaisseAndDebitAccount(
                    caisse: $commercial->caisse,
                    account: $collectedAccount,
                    amount: abs($difference),
                    caisseLabel: "Ajustement paiement — Facture #{$payment->sales_invoice_id}",
                    accountLabel: "Ajustement paiement — Facture #{$payment->sales_invoice_id}",
                    referenceType: 'PAYMENT_ADJUSTMENT',
                    referenceId: $payment->id,
                );
            } catch (InsufficientAccountBalanceException) {
                Log::warning(
                    "Payment #{$payment->id} amount reduced but CommercialCollected account for «{$commercial->name}» "
                    .'has insufficient balance for adjustment. Manual reconciliation may be needed.',
                    ['payment_id' => $payment->id, 'commercial_id' => $commercial->id, 'difference' => $difference]
                );
            }
        }
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The profit portion of this payment, computed from the invoice's profit margin.
     * Uses stored columns on the invoice — no extra DB query for items.
     */
    public function getTotalProfitAttribute(): int
    {
        $invoice = $this->salesInvoice;

        if ($invoice === null || $invoice->total_amount === 0) {
            return 0;
        }

        return (int) round($invoice->total_estimated_profit / $invoice->total_amount * $this->amount);
    }
}
