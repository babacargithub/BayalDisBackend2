<?php

namespace App\Services;

use App\Data\SalesInvoice\DebtListDTO;
use App\Data\SalesInvoice\DebtListFilterDTO;
use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read model of the "Dettes clients" page: every invoice not yet fully paid,
 * filtered and grouped by age.
 */
readonly class SalesInvoiceDebtListService
{
    public function __construct(private ArAgingService $arAgingService) {}

    public function buildDebtList(DebtListFilterDTO $filter): DebtListDTO
    {
        $openInvoices = SalesInvoice::query()
            ->with('customer:id,name,phone_number,address')
            ->where('status', '!=', SalesInvoiceStatus::FullyPaid->value)
            ->latest()
            ->get();

        $today = today();

        $invoiceRows = $this->applyFilter($openInvoices, $filter)
            ->map(fn (SalesInvoice $invoice) => $this->buildDebtRow($invoice, $today))
            ->values();

        $overdueRows = $invoiceRows->where('is_overdue', true);

        return new DebtListDTO(
            buckets: $this->arAgingService->groupRowsIntoBuckets($invoiceRows, includeSameDayBucket: true),
            invoiceCount: $invoiceRows->count(),
            totalAmount: (int) $invoiceRows->sum('total_amount'),
            totalPaid: (int) $invoiceRows->sum('total_payments'),
            totalRemaining: (int) $invoiceRows->sum('remaining'),
            overdueInvoiceCount: $overdueRows->count(),
            overdueRemaining: (int) $overdueRows->sum('remaining'),
            availableWeekLabels: $this->buildAvailableWeekLabels($openInvoices),
        );
    }

    /**
     * @param  Collection<int, SalesInvoice>  $invoices
     * @return Collection<int, SalesInvoice>
     */
    private function applyFilter(Collection $invoices, DebtListFilterDTO $filter): Collection
    {
        $searchedName = mb_strtolower($filter->customerNameSearch);

        return $invoices
            ->filter(fn (SalesInvoice $invoice) => match ($filter->paymentStatus) {
                DebtListFilterDTO::STATUS_PAID => $invoice->paid,
                DebtListFilterDTO::STATUS_UNPAID => ! $invoice->paid,
                DebtListFilterDTO::STATUS_PAST_DUE => $invoice->should_be_paid_at !== null
                    && $invoice->should_be_paid_at->lte(now()->endOfDay()),
                default => true,
            })
            ->filter(fn (SalesInvoice $invoice) => $searchedName === ''
                || str_contains(mb_strtolower($invoice->customer->name ?? ''), $searchedName))
            ->filter(fn (SalesInvoice $invoice) => $filter->selectedWeekStartDates === []
                || in_array($this->weekStartDate($invoice->created_at), $filter->selectedWeekStartDates, true));
    }

    /** @return array<string, mixed> */
    private function buildDebtRow(SalesInvoice $invoice, CarbonInterface $today): array
    {
        $invoiceRow = $this->arAgingService->buildInvoiceRow($invoice, $today);

        return [
            ...$invoiceRow,
            'customer_phone_number' => $invoice->customer->phone_number ?? '',
            'customer_address' => $invoice->customer->address ?? '',
            'should_be_paid_at' => $invoice->should_be_paid_at?->toDateString(),
            'is_overdue' => $invoice->should_be_paid_at !== null
                && $invoice->should_be_paid_at->lt(now())
                && $invoiceRow['remaining'] > 0,
            'total_estimated_profit' => $invoice->total_estimated_profit,
            'total_realized_profit' => $invoice->total_realized_profit,
        ];
    }

    private function weekStartDate(CarbonInterface $date): string
    {
        return $date->copy()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * @param  Collection<int, SalesInvoice>  $invoices
     * @return array<string, string>
     */
    private function buildAvailableWeekLabels(Collection $invoices): array
    {
        return $invoices
            ->sortByDesc('created_at')
            ->mapWithKeys(function (SalesInvoice $invoice): array {
                $weekStart = $invoice->created_at->copy()->startOfWeek(CarbonInterface::MONDAY)->locale('fr');
                $weekEnd = $weekStart->copy()->addDays(6);

                return [
                    $weekStart->toDateString() => 'Factures du lundi '.$weekStart->isoFormat('D MMMM YYYY')
                        .' au dimanche '.$weekEnd->isoFormat('D MMMM YYYY'),
                ];
            })
            ->all();
    }
}
