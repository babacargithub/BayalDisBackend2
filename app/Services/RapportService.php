<?php

namespace App\Services;

use App\Data\Rapport\RapportStatisticsDTO;

readonly class RapportService
{
    public const FOND_DE_ROULEMENT = 1_944_000;

    public function __construct(
        private ProductService $productService,
        private CarLoadService $carLoadService,
        private AccountService $accountService,
        private SalesInvoiceService $salesInvoiceService,
        private PurchaseInvoiceService $purchaseInvoiceService,
        private AccountDebtService $accountDebtService,
    ) {}

    public function buildRapportStatistics(): RapportStatisticsDTO
    {
        $warehouseStockValue = $this->productService->getTotalWarehouseStockValue();
        $carLoadsStockValue = $this->carLoadService->getTotalActiveCarLoadsStockValue();
        $totalCaissesBalance = $this->accountService->computeTotalCaisseBalance();
        $totalUnpaidInvoicesAmount = $this->salesInvoiceService->getTotalUnpaidInvoicesAmount();
        $totalSupplierDebtsAmount = $this->purchaseInvoiceService->getTotalUnpaidSupplierDebtAmount();
        $totalBorrowingsAmount = $this->accountDebtService->getTotalBorrowedAmountOwedToLenders();

        $realValue = $warehouseStockValue + $carLoadsStockValue + $totalCaissesBalance;
        $businessValue = $realValue + $totalUnpaidInvoicesAmount;

        return new RapportStatisticsDTO(
            warehouseStockValue: $warehouseStockValue,
            carLoadsStockValue: $carLoadsStockValue,
            totalCaissesBalance: $totalCaissesBalance,
            totalUnpaidInvoicesAmount: $totalUnpaidInvoicesAmount,
            totalUnpaidInvoicesCount: $this->salesInvoiceService->getUnpaidInvoicesCount(),
            totalSupplierDebtsAmount: $totalSupplierDebtsAmount,
            totalUnpaidPurchaseInvoicesCount: $this->purchaseInvoiceService->getUnpaidPurchaseInvoicesCount(),
            totalBorrowingsAmount: $totalBorrowingsAmount,
            businessValue: $businessValue,
            fondDeRoulement: self::FOND_DE_ROULEMENT,
            netPlusValue: $businessValue - self::FOND_DE_ROULEMENT - $totalSupplierDebtsAmount - $totalBorrowingsAmount,
            realValue: $realValue,
            realNetValue: $realValue - self::FOND_DE_ROULEMENT,
        );
    }
}
