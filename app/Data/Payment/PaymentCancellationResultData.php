<?php

namespace App\Data\Payment;

/**
 * Result of a payment cancellation, used to build the user-facing summary.
 *
 * - wasCancelledAfterDayClose : false = same-day cancellation (simple caisse reversal),
 *                               true  = the day was already closed/versed (full reversal).
 * - cashReversalAmount        : amount withdrawn from a caisse (0 when the payment had
 *                               no commercial caisse attached).
 * - cashReversalCaisseName    : name of the caisse that was debited (null when none).
 * - commissionClawbackAmount  : commission recovered from the commercial's commission
 *                               account (only for after-day-close cancellations).
 */
readonly class PaymentCancellationResultData
{
    public function __construct(
        public int $paymentId,
        public bool $wasCancelledAfterDayClose,
        public int $cashReversalAmount,
        public ?string $cashReversalCaisseName,
        public int $commissionClawbackAmount,
    ) {}

    public function buildSuccessMessage(): string
    {
        $messageParts = ['Paiement annulé avec succès.'];

        if ($this->cashReversalAmount > 0 && $this->cashReversalCaisseName !== null) {
            $formattedAmount = number_format($this->cashReversalAmount, 0, ',', ' ');
            $messageParts[] = "{$formattedAmount} F retirés de «{$this->cashReversalCaisseName}».";
        }

        if ($this->commissionClawbackAmount > 0) {
            $formattedClawback = number_format($this->commissionClawbackAmount, 0, ',', ' ');
            $messageParts[] = "Commission récupérée : {$formattedClawback} F.";
        }

        return implode(' ', $messageParts);
    }
}
