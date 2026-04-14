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

    private const UNPAID_INVOICES_START_DATE = '2026-03-29';

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

        $unpaidInvoices = SalesInvoice::query()
            ->whereIn('status', $unpaidInvoiceStatuses)
            ->where('created_at', '>=', self::UNPAID_INVOICES_START_DATE)
            ->get();

        $totalUnpaidInvoicesAmount = $unpaidInvoices->sum('total_remaining');
        $totalUnpaidInvoicesCount = $unpaidInvoices->count();

        $businessValue = $warehouseStockValue + $carLoadsStockValue + $totalCaissesBalance + $totalUnpaidInvoicesAmount;
        $netPlusValue = $businessValue - self::FOND_DE_ROULEMENT;

        return Inertia::render('Admin/Rapport', [
            'statistics' => [
                'warehouse_stock_value' => $warehouseStockValue,
                'car_loads_stock_value' => $carLoadsStockValue,
                'total_caisses_balance' => $totalCaissesBalance,
                'total_unpaid_invoices_amount' => $totalUnpaidInvoicesAmount,
                'total_unpaid_invoices_count' => $totalUnpaidInvoicesCount,
                'unpaid_invoices_start_date' => self::UNPAID_INVOICES_START_DATE,
                'business_value' => $businessValue,
                'fond_de_roulement' => self::FOND_DE_ROULEMENT,
                'net_plus_value' => $netPlusValue,
            ],
        ]);
    }
}
