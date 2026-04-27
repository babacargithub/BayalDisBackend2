<?php

namespace App\Services;

use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Vente;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Single source of truth for all SalesInvoice CRUD and mutation operations.
 *
 * This service owns every state transition and data mutation on invoices and their
 * related entities (items, payments). For read-only calculations and statistics,
 * use SalesInvoiceStatsService instead.
 */
readonly class SalesInvoiceService
{
    public function __construct(
        private CarLoadService $carLoadService,
        private PricingPolicyService $pricingPolicyService,
    ) {}

    // =========================================================================
    // Invoice lifecycle
    // =========================================================================

    /** @throws InsufficientStockException|Throwable */
    public function createSalesInvoice(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            $user = auth()->user();
            $user->load('commercial');

            // Resolve the active car load first so car_load_id is stored on the invoice.
            // This is the single source of truth linking sales to the car load they belong to,
            // and is used when computing total_sold during inventory aggregation.
            $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($user->commercial->team);
            if ($currentCarLoad === null) {
                throw new UnprocessableEntityHttpException(
                    'Pour pourvoir faire une vente, il faut un chargement de véhicule attribué à votre équipe !'
                );
            }

            $isPaid = (bool) ($data['paid'] ?? false);

            // Step 1: Replace normal prices with credit_price for each product when the active
            // policy has apply_credit_price enabled and the invoice is unpaid.
            $creditPricedItems = $this->pricingPolicyService->applyCreditPricingToItems(
                items: $data['items'],
                isPaid: $isPaid,
            );

            // Compute the credit price difference before the surcharge is applied so we capture
            // only the delta caused by credit pricing, not the subsequent percent surcharge.
            $creditPriceDifference = 0;
            foreach ($data['items'] as $itemIndex => $originalItem) {
                $creditPriceDifference += ($creditPricedItems[$itemIndex]['price'] - $originalItem['price']) * $originalItem['quantity'];
            }

            $salesInvoice = SalesInvoice::create([
                'customer_id' => $data['customer_id'],
                'invoice_number' => 'F'.date('Ymd').'-'.str_pad(SalesInvoice::max('id') + 1, 4, '0', STR_PAD_LEFT),
                'comment' => $data['comment'] ?? 'Facture de Vente',
                'should_be_paid_at' => $data['should_be_paid_at'] ?? null,
                'commercial_id' => $user->commercial->id,
                'status' => SalesInvoiceStatus::Issued,
                'car_load_id' => $currentCarLoad->id,
                'credit_price_difference' => $creditPriceDifference,
            ]);
            $salesInvoice->refresh();

            // Step 2: Apply surcharge policy (e.g., percent increase for deferred payments) on top
            // of the already credit-priced items.
            $adjustedItems = $this->pricingPolicyService->applyPolicyToItems(
                items: $creditPricedItems,
                shouldBePaidAt: isset($data['should_be_paid_at']) ? \Carbon\Carbon::parse($data['should_be_paid_at']) : null,
                isPaid: $isPaid,
            );

            $itemsToSave = [];
            foreach ($adjustedItems as $item) {
                $salesInvoiceItem = new Vente([
                    'sales_invoice_id' => $salesInvoice->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'type' => 'INVOICE_ITEM',
                ]);
                $salesInvoiceItem->calculateProfit();
                $itemsToSave[] = $salesInvoiceItem;
            }
            $salesInvoice->items()->saveMany($itemsToSave);

            foreach ($itemsToSave as $salesInvoiceItem) {
                $this->carLoadService->decreaseProductStockInCarLoadUsingFifo(
                    $salesInvoiceItem->product,
                    $salesInvoiceItem->quantity,
                    $currentCarLoad
                );
            }

            if ($isPaid) {
                // Refresh to get stored total_amount after items were saved and recalculated.
                $salesInvoice->refresh();
                $this->paySalesInvoice($salesInvoice, [
                    'amount' => $salesInvoice->total_amount,
                    'payment_method' => $data['payment_method'],
                ], request()->user()->id);
            }

            $customer = Customer::withoutEagerLoads()->findOrFail($data['customer_id']);
            if ($customer->is_prospect) {
                $customer->is_prospect = false;
                $customer->save();
            }

            return $salesInvoice;
        });
    }

    /** @throws Throwable */
    public function updateSalesInvoice(SalesInvoice $salesInvoice, array $validatedData): void
    {
        DB::transaction(fn () => $salesInvoice->update($validatedData));
    }

    /** @throws Throwable */
    public function deleteSalesInvoice(SalesInvoice $salesInvoice): void
    {
        DB::transaction(function () use ($salesInvoice) {
            $commercialForInvoice = $salesInvoice->commercial;
            $currentCarLoad = $commercialForInvoice
                ? $this->carLoadService->getCurrentCarLoadForTeam($commercialForInvoice->team)
                : null;

            foreach ($salesInvoice->items as $item) {
                if ($currentCarLoad !== null) {
                    $this->carLoadService->increaseProductStockInCarLoad(
                        $item->product,
                        $item->quantity,
                        $currentCarLoad
                    );
                }
            }

            $salesInvoice->items()->delete();
            $salesInvoice->delete();
        });
    }

    // =========================================================================
    // Invoice item mutations
    // =========================================================================

    /** @throws Throwable */
    public function addItemToInvoice(SalesInvoice $salesInvoice, array $validatedData): Vente
    {
        return DB::transaction(function () use ($salesInvoice, $validatedData) {
            $salesInvoiceItem = new Vente([
                'sales_invoice_id' => $salesInvoice->id,
                'product_id' => $validatedData['product_id'],
                'quantity' => $validatedData['quantity'],
                'price' => $validatedData['price'],
                'type' => 'INVOICE_ITEM',
                'paid' => $salesInvoice->paid,
                'should_be_paid_at' => $salesInvoice->should_be_paid_at,
            ]);
            $salesInvoiceItem->calculateProfit();
            $salesInvoiceItem->save();
            $salesInvoiceItem->refresh();

            $currentCarLoad = $salesInvoice->commercial
                ? $this->carLoadService->getCurrentCarLoadForTeam($salesInvoice->commercial->team)
                : null;

            if ($currentCarLoad !== null) {
                $this->carLoadService->decreaseProductStockInCarLoadUsingFifo(
                    $salesInvoiceItem->product,
                    $salesInvoiceItem->quantity,
                    $currentCarLoad
                );
            }

            return $salesInvoiceItem;
        });
    }

    /** @throws Throwable */
    public function removeItemFromInvoice(SalesInvoice $salesInvoice, Vente $item): void
    {
        DB::transaction(function () use ($salesInvoice, $item) {
            $commercial = $salesInvoice->commercial;
            if ($commercial !== null) {
                $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($commercial->team);
                if ($currentCarLoad !== null) {
                    $this->carLoadService->increaseProductStockInCarLoad(
                        $item->product,
                        $item->quantity,
                        $currentCarLoad
                    );
                }
            }
            $item->delete();
            // Vente::deleted event fires and recalculates stored totals automatically.
        });
    }

    /** @throws Throwable */
    public function updateInvoiceItemProfit(Vente $item, int $profitAmount): Vente
    {
        $item->profit = $profitAmount;
        $item->save();

        return $item;
    }

    // =========================================================================
    // Payment mutations
    // =========================================================================

    /** @throws Throwable */
    public function paySalesInvoice(SalesInvoice $salesInvoice, array $validatedData, int $userId): void
    {
        DB::transaction(function () use ($salesInvoice, $validatedData, $userId) {
            $salesInvoice->payments()->create([
                'amount' => $validatedData['amount'],
                'payment_method' => $validatedData['payment_method'],
                'comment' => $validatedData['comment'] ?? null,
                'user_id' => $userId,
            ]);
            // Payment::saved event fires and recalculates stored totals automatically.
            $salesInvoice->refresh();

            if ($salesInvoice->total_payments >= $salesInvoice->total_amount) {
                $salesInvoice->markAsFullyPaid();
            }
        });
    }

    // =========================================================================
    // Queries
    // =========================================================================

    /**
     * Returns overdue unpaid invoices created for the given commercial within the date range.
     * Uses the SalesInvoice::scopeOverdue() scope as the single source of truth for the
     * "overdue" definition.
     *
     * @return Collection<int, SalesInvoice>
     */
    public function getOverdueInvoicesForCommercial(
        Commercial $commercial,
        string $startDate,
        string $endDate,
    ): Collection {
        return SalesInvoice::query()
            ->overdue()
            ->where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->whereDate('should_be_paid_at', '<', today()->toDateString())
            ->with('customer:id,name')
            ->orderBy('should_be_paid_at')
            ->get();
    }

    /** @throws Throwable */
    public function removePaymentFromInvoice(Payment $payment): void
    {
        DB::transaction(fn () => $payment->delete());
        // Payment::deleted event fires and recalculates stored totals automatically.
    }

    /** @throws Throwable */
    public function updatePaymentOnInvoice(
        SalesInvoice $salesInvoice,
        Payment $payment,
        array $validatedData,
    ): void {
        DB::transaction(function () use ($salesInvoice, $payment, $validatedData) {
            // Refresh so stored total_amount and total_estimated_profit are current
            // before computing the proportional profit and commission for the new amount.
            $salesInvoice->refresh();
            $newAmount = $validatedData['amount'];

            $payment->update([
                'amount' => $newAmount,
                'profit' => $salesInvoice->computeRealizedProfitForPaymentAmount($newAmount),
                'commercial_commission' => $salesInvoice->computeCommercialCommissionForPaymentAmount($newAmount),
                'payment_method' => $validatedData['payment_method'],
                'comment' => $validatedData['comment'] ?? null,
            ]);
            // Payment::saved event fires and recalculates stored totals automatically.
            $salesInvoice->refresh();

            if ($salesInvoice->total_payments >= $salesInvoice->total_amount) {
                $salesInvoice->markAsFullyPaid();
            }
        });
    }
}
