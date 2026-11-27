<?php

namespace App\Services;

use App\Data\Beat\ArBucket;
use App\Models\SalesInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of truth for accounts-receivable aging: how old an invoice is
 * and which bucket it belongs to.
 */
readonly class ArAgingService
{
    private const SAME_DAY_BUCKET = ['labelFr' => "Aujourd'hui", 'labelEn' => 'Today', 'from' => 0, 'to' => 0];

    private const BUCKET_DEFINITIONS = [
        ['labelFr' => '1 à 7 jours', 'labelEn' => '1 to 7 days', 'from' => 1, 'to' => 7],
        ['labelFr' => '8 à 14 jours', 'labelEn' => '8 to 14 days', 'from' => 8, 'to' => 14],
        ['labelFr' => '15 à 21 jours', 'labelEn' => '15 to 21 days', 'from' => 15, 'to' => 21],
        ['labelFr' => '22 à 30 jours', 'labelEn' => '22 to 30 days', 'from' => 22, 'to' => 30],
        ['labelFr' => 'Plus de 30 jours', 'labelEn' => 'Over 30 days', 'from' => 31, 'to' => null],
    ];

    /**
     * Base row of a bucket table. Age is counted in calendar days, ignoring the time of day.
     *
     * @return array{id: int, customer_id: int|null, customer_name: string, total_amount: int, total_payments: int, remaining: int, days_overdue: int, created_at: string}
     */
    public function buildInvoiceRow(SalesInvoice $invoice, CarbonInterface $referenceDate): array
    {
        return [
            'id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer->name ?? '',
            'total_amount' => $invoice->total_amount,
            'total_payments' => $invoice->total_payments,
            'remaining' => $invoice->total_amount - $invoice->total_payments,
            'days_overdue' => (int) $invoice->created_at->copy()->startOfDay()
                ->diffInDays($referenceDate->copy()->startOfDay()),
            'created_at' => $invoice->created_at->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $invoiceRows  Rows built with buildInvoiceRow().
     * @param  bool  $includeSameDayBucket  Prepend a bucket for invoices aged zero days, when there are any.
     * @return ArBucket[]
     */
    public function groupRowsIntoBuckets(Collection $invoiceRows, bool $includeSameDayBucket = false): array
    {
        $bucketDefinitions = self::BUCKET_DEFINITIONS;

        if ($includeSameDayBucket && $invoiceRows->contains(fn (array $row) => $row['days_overdue'] <= 0)) {
            array_unshift($bucketDefinitions, self::SAME_DAY_BUCKET);
        }

        return array_map(function (array $definition) use ($invoiceRows): ArBucket {
            $bucketRows = $invoiceRows
                ->filter(fn (array $row) => $row['days_overdue'] >= $definition['from']
                    && ($definition['to'] === null || $row['days_overdue'] <= $definition['to']))
                ->values();

            return new ArBucket(
                labelFr: $definition['labelFr'],
                labelEn: $definition['labelEn'],
                fromDays: $definition['from'],
                toDays: $definition['to'],
                invoiceCount: $bucketRows->count(),
                totalAmount: (int) $bucketRows->sum('remaining'),
                totalInvoicedAmount: (int) $bucketRows->sum('total_amount'),
                totalPaidAmount: (int) $bucketRows->sum('total_payments'),
                invoices: $bucketRows->all(),
            );
        }, $bucketDefinitions);
    }
}
