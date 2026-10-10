<?php

namespace App\Data\Rapport;

/**
 * Financial snapshot displayed on the admin "Rapport" page. All amounts are integers (XOF).
 */
readonly class RapportStatisticsDTO
{
    public function __construct(
        public int $warehouseStockValue,
        public int $carLoadsStockValue,
        public int $totalCaissesBalance,
        public int $totalUnpaidInvoicesAmount,
        public int $totalUnpaidInvoicesCount,
        public int $totalSupplierDebtsAmount,
        public int $totalUnpaidPurchaseInvoicesCount,
        public int $totalBorrowingsAmount,
        public int $businessValue,
        public int $fondDeRoulement,
        public int $netPlusValue,
        public int $realValue,
        public int $realNetValue,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'warehouse_stock_value' => $this->warehouseStockValue,
            'car_loads_stock_value' => $this->carLoadsStockValue,
            'total_caisses_balance' => $this->totalCaissesBalance,
            'total_unpaid_invoices_amount' => $this->totalUnpaidInvoicesAmount,
            'total_unpaid_invoices_count' => $this->totalUnpaidInvoicesCount,
            'total_supplier_debts_amount' => $this->totalSupplierDebtsAmount,
            'total_unpaid_purchase_invoices_count' => $this->totalUnpaidPurchaseInvoicesCount,
            'total_borrowings_amount' => $this->totalBorrowingsAmount,
            'business_value' => $this->businessValue,
            'fond_de_roulement' => $this->fondDeRoulement,
            'net_plus_value' => $this->netPlusValue,
            'real_value' => $this->realValue,
            'real_net_value' => $this->realNetValue,
        ];
    }
}
