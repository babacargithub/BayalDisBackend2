<?php

namespace App\Http\Controllers;

use App\Enums\SalesInvoiceStatus;
use App\Models\Caisse;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Services\CarLoadService;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    private const FOND_DE_ROULEMENT = 1_944_000;

    public function __construct(private readonly CarLoadService $carLoadService) {}

    public function rapport(): Response
    {
        $warehouseStockValue = Product::all()->sum('stock_value');

        $carLoadsStockValue = $this->carLoadService->getTotalActiveCarLoadsStockValue();

        $totalCaissesBalance = Caisse::sum('balance');

        $unpaidInvoiceStatuses = [
            SalesInvoiceStatus::Draft->value,
            SalesInvoiceStatus::Issued->value,
            SalesInvoiceStatus::PartiallyPaid->value,
        ];

        // Written-off invoices (definitively lost debt) are excluded automatically
        // by the SalesInvoice global scope.
        $unpaidInvoices = SalesInvoice::query()
            ->whereIn('status', $unpaidInvoiceStatuses)
            ->get();

        $totalUnpaidInvoicesAmount = $unpaidInvoices->sum('total_remaining');
        $totalUnpaidInvoicesCount = $unpaidInvoices->count();

        $businessValue = $warehouseStockValue + $carLoadsStockValue + $totalCaissesBalance + $totalUnpaidInvoicesAmount;
        $netPlusValue = $businessValue - self::FOND_DE_ROULEMENT;
        $realValue = $warehouseStockValue + $carLoadsStockValue + $totalCaissesBalance;

        return Inertia::render('Admin/Rapport', [
            'statistics' => [
                'warehouse_stock_value' => $warehouseStockValue,
                'car_loads_stock_value' => $carLoadsStockValue,
                'total_caisses_balance' => $totalCaissesBalance,
                'total_unpaid_invoices_amount' => $totalUnpaidInvoicesAmount,
                'total_unpaid_invoices_count' => $totalUnpaidInvoicesCount,
                'business_value' => $businessValue,
                'fond_de_roulement' => self::FOND_DE_ROULEMENT,
                'net_plus_value' => $netPlusValue,
                'real_value' => $realValue,
                'real_net_value' => $realValue - self::FOND_DE_ROULEMENT,
            ],
        ]);
    }
}
