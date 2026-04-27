<?php

namespace App\Http\Controllers;

use App\Models\Commercial;
use App\Models\CommercialPenalty;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Models\SalesInvoice;
use App\Services\CommercialService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class HrController extends Controller
{
    public function __construct(
        private readonly CommercialService $commercialService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'commercial_id' => ['nullable', 'integer', 'exists:commercials,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $commercials = Commercial::orderBy('name')->get(['id', 'name']);

        $selectedCommercial = null;
        $workPeriods = null;
        $inventoryResults = null;
        $overdueInvoices = null;
        $penalties = null;

        if (isset($validated['commercial_id'])) {
            $selectedCommercial = Commercial::with('team')->findOrFail($validated['commercial_id']);

            $workPeriods = $selectedCommercial->workPeriods()
                ->orderBy('period_start_date', 'desc')
                ->get(['id', 'period_start_date', 'period_end_date', 'is_finalized']);

            if (isset($validated['start_date'], $validated['end_date'])) {
                $startDate = $validated['start_date'];
                $endDate = $validated['end_date'];

                $inventoryResults = $this->commercialService->getInventoryResultsForCommercial(
                    $selectedCommercial,
                    $startDate,
                    $endDate,
                );

                $rawOverdueInvoices = $this->commercialService->getOverdueInvoicesForCommercial(
                    $selectedCommercial,
                    $startDate,
                    $endDate,
                );

                $overdueInvoices = $rawOverdueInvoices->map(fn (SalesInvoice $invoice) => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer->name,
                    'total_amount' => $invoice->total_amount,
                    'total_remaining' => $invoice->total_remaining,
                    'should_be_paid_at' => $invoice->should_be_paid_at?->toDateString(),
                    'days_overdue' => (int) Carbon::now()->startOfDay()->diffInDays($invoice->should_be_paid_at->startOfDay()),
                    'status' => $invoice->status->value,
                ])->values();

                $rawPenalties = $this->commercialService->getPenaltiesForCommercial(
                    $selectedCommercial,
                    $startDate,
                    $endDate,
                );

                $penalties = $rawPenalties->map(fn (CommercialPenalty $penalty) => [
                    'id' => $penalty->id,
                    'work_day' => $penalty->work_day->toDateString(),
                    'amount' => $penalty->amount,
                    'reason' => $penalty->reason,
                    'car_load_inventory_id' => $penalty->car_load_inventory_id,
                    'sales_invoice_id' => $penalty->sales_invoice_id,
                ])->values();
            }
        }

        return Inertia::render('Admin/Rh', [
            'commercials' => $commercials,
            'selectedCommercial' => $selectedCommercial ? [
                'id' => $selectedCommercial->id,
                'name' => $selectedCommercial->name,
                'salary' => $selectedCommercial->salary,
                'team_name' => $selectedCommercial->team?->name,
            ] : null,
            'workPeriods' => $workPeriods,
            'inventoryResults' => $inventoryResults,
            'overdueInvoices' => $overdueInvoices,
            'penalties' => $penalties,
            'filters' => [
                'commercial_id' => $validated['commercial_id'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ],
        ]);
    }

    public function generatePayrollPdf(Request $request): HttpResponse
    {
        $validated = $request->validate([
            'commercial_id' => ['required', 'integer', 'exists:commercials,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'base_salary' => ['required', 'integer', 'min:0'],
        ]);

        $commercial = Commercial::with('team')->findOrFail($validated['commercial_id']);
        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];
        $baseSalary = $validated['base_salary'];

        $workPeriodIds = CommercialWorkPeriod::query()
            ->where('commercial_id', $commercial->id)
            ->where('period_start_date', '<=', $endDate)
            ->where('period_end_date', '>=', $startDate)
            ->pluck('id');

        $dailyCommissions = DailyCommission::query()
            ->whereIn('commercial_work_period_id', $workPeriodIds)
            ->whereBetween('work_day', [$startDate, $endDate])
            ->orderBy('work_day')
            ->get();

        $totalBaseCommission = $dailyCommissions->sum('base_commission');
        $totalBasketBonus = $dailyCommissions->sum('basket_bonus');
        $totalObjectiveBonus = $dailyCommissions->sum('objective_bonus');
        $totalNewConfirmedCustomersBonus = $dailyCommissions->sum('new_confirmed_customers_bonus');
        $totalNewProspectCustomersBonus = $dailyCommissions->sum('new_prospect_customers_bonus');
        $totalGrossCommission = $totalBaseCommission + $totalBasketBonus + $totalObjectiveBonus
            + $totalNewConfirmedCustomersBonus + $totalNewProspectCustomersBonus;

        $penalties = $this->commercialService->getPenaltiesForCommercial($commercial, $startDate, $endDate);
        $totalPenalties = $penalties->sum('amount');

        $totalGrossEarnings = $baseSalary + $totalGrossCommission;
        $netToPay = max(0, $totalGrossEarnings - $totalPenalties);

        $pdf = Pdf::loadView('pdf.payroll-sheet', [
            'commercial' => $commercial,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'generatedAt' => Carbon::now(),
            'baseSalary' => $baseSalary,
            'totalBaseCommission' => $totalBaseCommission,
            'totalBasketBonus' => $totalBasketBonus,
            'totalObjectiveBonus' => $totalObjectiveBonus,
            'totalNewConfirmedCustomersBonus' => $totalNewConfirmedCustomersBonus,
            'totalNewProspectCustomersBonus' => $totalNewProspectCustomersBonus,
            'totalGrossCommission' => $totalGrossCommission,
            'totalGrossEarnings' => $totalGrossEarnings,
            'penalties' => $penalties,
            'totalPenalties' => $totalPenalties,
            'netToPay' => $netToPay,
            'logoDataUri' => $this->buildLogoDataUri(),
        ])->setPaper('a4', 'portrait');

        $filename = 'fiche-de-paie-'.$commercial->name.'-'.$startDate.'-'.$endDate.'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * DomPDF is extremely slow when processing large PNG files. The source logo is
     * 4600×4600 px (1.78 MB), which causes ~19 s render times. This resizes it to
     * 128×128 px before embedding so DomPDF receives a ~12 KB image instead.
     */
    private function buildLogoDataUri(): string
    {
        $logoPath = public_path('logo_v2.png');

        if (! file_exists($logoPath) || ! extension_loaded('gd')) {
            return '';
        }

        $targetSize = 128;
        $source = imagecreatefrompng($logoPath);
        $resized = imagecreatetruecolor($targetSize, $targetSize);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetSize, $targetSize, imagesx($source), imagesy($source));

        ob_start();
        imagepng($resized, null, 9);
        $pngBytes = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($pngBytes);
    }

    /** @throws \Throwable */
    public function storePenaltiesBulkFromInvoices(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commercial_id' => ['required', 'integer', 'exists:commercials,id'],
            'work_day' => ['required', 'date'],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'integer', 'exists:sales_invoices,id'],
        ]);

        $commercial = Commercial::findOrFail($validated['commercial_id']);

        $this->commercialService->createPenaltiesFromOverdueInvoices(
            commercial: $commercial,
            salesInvoiceIds: $validated['invoice_ids'],
            workDay: $validated['work_day'],
            createdByUserId: $request->user()->id,
        );

        return redirect()->back()->with('success', count($validated['invoice_ids']).' pénalité(s) créée(s) avec succès.');
    }

    /** @throws \Throwable */
    public function storePenalty(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commercial_id' => ['required', 'integer', 'exists:commercials,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'work_day' => ['required', 'date'],
            'car_load_inventory_id' => ['nullable', 'integer', 'exists:car_load_inventories,id'],
            'sales_invoice_id' => ['nullable', 'integer', 'exists:sales_invoices,id'],
        ]);

        $commercial = Commercial::findOrFail($validated['commercial_id']);

        $this->commercialService->createPenaltyForCommercial(
            commercial: $commercial,
            amount: $validated['amount'],
            reason: $validated['reason'],
            workDay: $validated['work_day'],
            createdByUserId: $request->user()->id,
            carLoadInventoryId: $validated['car_load_inventory_id'] ?? null,
            salesInvoiceId: $validated['sales_invoice_id'] ?? null,
        );

        return redirect()->back()->with('success', 'Pénalité créée avec succès.');
    }
}
