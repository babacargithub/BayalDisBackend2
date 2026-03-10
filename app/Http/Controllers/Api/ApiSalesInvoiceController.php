<?php /** @noinspection UnknownColumnInspection */

namespace App\Http\Controllers\Api;

use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvoicePaymentMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSalesInvoiceRequest;
use App\Http\Requests\PaySalesInvoiceRequest;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Services\SalesInvoiceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ApiSalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceService $salesInvoiceService,
    ) {}
    public function createSalesInvoice(CreateSalesInvoiceRequest $request): JsonResponse
    {
        try {
            $this->salesInvoiceService->createSalesInvoice($request->validated());
        } catch (InsufficientStockException|InvoicePaymentMismatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Facture créée avec succès'], 201);
    }


    /**
     * @throws Throwable
     */
    public function paySalesInvoice(PaySalesInvoiceRequest $request, SalesInvoice $invoice): JsonResponse
    {
        $this->salesInvoiceService->paySalesInvoice($invoice, $request->validated(), $request->user()->id);

        return response()->json([
            'message' => 'Paiement effectué avec succès',
            'data' => $invoice->id,
        ]);
    }

    public function getSalesAndPaymentsOfATeamInAPeriod(Request $request): JsonResponse
    {
        $date = $request->query('date', today()->toDateString());


        $invoicesQuery = SalesInvoice::with(['customer', 'items.product'])
            ->whereDate('created_at', $date);

        $paymentsQuery = Payment::whereDate('created_at', $date)
            ->where('user_id', $request->user()->id);


        $invoices = $invoicesQuery->get();
        $activityReport = $this->salesInvoiceService->buildCommercialActivityReport(auth()->user()->commercial,
            Carbon::parse($date), Carbon::parse($date));
        $totalSales = $activityReport->totalSales;
        $totalPayments = $activityReport->totalPayments;


        return response()->json([
            'ventes' =>[],
            'invoices' => $invoices->map(fn (SalesInvoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer' => [
                    'name' => $invoice->customer->name,
                    'phone_number' => $invoice->customer->phone_number,
                ],
                'items' => [],
                'total' => $invoice->total_amount,
                'paid' => $invoice->status == SalesInvoiceStatus::FullyPaid,
                'should_be_paid_at' => $invoice->should_be_paid_at,
                'created_at' => $invoice->created_at,
            ]),
            'payments' => $paymentsQuery->get()->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'created_at' => $payment->created_at,
                'label' => 'Paiement : '.$payment->salesInvoice?->customer?->name,
            ]),
            "total_payments"=> $totalPayments,
            'total' => $totalSales
        ]);
    }



    public function getActivityReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:daily,weekly',
        ]);

        $commercial = auth()->user()->commercial;
        if (! $commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        $date = Carbon::parse($validated['date']);
        $startDate = $validated['type'] === 'weekly' ? $date->copy()->startOfWeek() : $date->copy()->startOfDay();
        $endDate = $validated['type'] === 'weekly' ? $date->copy()->endOfWeek() : $date->copy()->endOfDay();

        $activityReport = $this->salesInvoiceService->buildCommercialActivityReport($commercial, $startDate, $endDate);

        return response()->json([
            'period' => [
                'start' => $startDate->toDateTimeString(),
                'end' => $endDate->toDateTimeString(),
                'type' => $validated['type'],
            ],
            'data' => $activityReport->toSnakeCaseArray(),
        ]);
    }



    public function getCommercials(): JsonResponse
    {
        return response()->json(
            Commercial::select('id', 'name', 'phone_number')->orderBy('name')->get()
        );
    }

    public function getCustomersAndProducts(): JsonResponse
    {
        return response()->json([
            'customers' => Customer::latest()->get(),
            'products' => Product::all(),
        ]);
    }
}
