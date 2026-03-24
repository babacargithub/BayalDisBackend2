<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Services\CustomerService;
use App\Services\SalesInvoiceStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiCustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    public function getTodayCustomersCount(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;

        return response()->json([
            'count' => $this->customerService->getTodayCustomersCount($commercial),
        ]);
    }

    public function getCustomers(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;

        $todayCount = null;
        if ($request->has('include_today_count')) {
            $todayCount = $this->customerService->getTodayCustomersCount($commercial);
        }

        $customers = $this->customerService->getCustomersQueryForCommercial($commercial)->get();

        return response()->json([
            'customers' => $customers,
            'today_count' => $todayCount,
        ]);
    }

    public function getCustomerCategories(): JsonResponse
    {
        return response()->json(
            CustomerCategory::select('id', 'name')->get()
        );
    }

    public function createCustomer(CreateCustomerRequest $request): JsonResponse
    {
        $commercial = $request->user()->commercial;

        $customer = $this->customerService->createCustomer($commercial, $request->validated());

        return response()->json($customer, 201);
    }

    public function updateCustomer(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if (! $this->customerService->canModifyCustomer($commercial, $customer)) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $updatedCustomer = $this->customerService->updateCustomer($customer, $request->validated());

        return response()->json($updatedCustomer);
    }

    public function getCustomerVentes(Request $request, Customer $customer): JsonResponse
    {
        $query = $customer->ventes()->with('product')->latest();

        if ($request->has('paid')) {
            $query->where('paid', $request->boolean('paid'));
        }

        return $this->venteResource($query);
    }

    public function getCustomerInvoices(Customer $customer): JsonResponse
    {
        $invoices = SalesInvoice::with(['items.product', 'payments'])
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'total' => $invoice->total,
                'paid' => $invoice->paid,
                'should_be_paid_at' => $invoice->should_be_paid_at,
                'status' => $invoice->status,
                'created_at' => $invoice->created_at,
                'items' => $invoice->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product' => $item->product,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ]),
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'comment' => $payment->comment,
                    'created_at' => $payment->created_at,
                ]),
            ]);

        return response()->json(['data' => $invoices]);
    }

    public function getCustomersWithVisits(): JsonResponse
    {
        $customers = Customer::whereHas('visits', function ($query) {
            $query->whereDate('visit_planned_at', '>=', now()->startOfDay());
        })->get()->map(fn ($customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone_number' => $customer->phone_number,
            'address' => $customer->address,
            'gps_coordinates' => $customer->gps_coordinates,
            'owner_number' => $customer->owner_number,
            'debt' => $customer->total_debt,
            'is_prospect' => $customer->is_prospect,
        ]);

        return response()->json(['customers' => $customers]);
    }

    public function getDebts(): JsonResponse
    {
        $commercial = auth()->user()->commercial;

        $invoices = SalesInvoice::with(['customer', 'items.product', 'payments'])
            ->where('paid', false)
            ->whereHas('customer', fn ($query) => $query->where('commercial_id', $commercial->id))
            ->latest()
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'customer' => [
                    'name' => $invoice->customer->name,
                    'phone_number' => $invoice->customer->phone_number,
                ],
                'total' => $invoice->total,
                'paid' => $invoice->paid,
                'should_be_paid_at' => $invoice->should_be_paid_at,
                'created_at' => $invoice->created_at,
                'items' => $invoice->items->map(fn ($item) => [
                    'product' => ['name' => $item->product->name],
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ]),
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'comment' => $payment->comment,
                    'created_at' => $payment->created_at,
                ]),
            ]);

        return response()->json($invoices);
    }

    public function getWeeklyDebts(Request $request, SalesInvoiceStatsService $salesInvoiceStatsService): JsonResponse
    {
        $commercial = $request->user()->commercial;
        if (! $commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        return response()->json($salesInvoiceStatsService->weeklyDebts($commercial->id));
    }

    private function venteResource($query): JsonResponse
    {
        $ventes = $query->get();

        return response()->json([
            'ventes' => $ventes->map(fn (Vente $vente) => [
                'id' => $vente->id,
                'product' => $vente->product?->name,
                'customer' => $vente->customer?->name,
                'customer_phone_number' => $vente->customer?->phone_number,
                'quantity' => $vente->quantity,
                'price' => $vente->price,
                'total' => $vente->price * $vente->quantity,
                'paid' => (bool) $vente->paid,
                'paid_at' => $vente->paid_at?->format('Y-m-d H:i:s'),
                'should_be_paid_at' => $vente->should_be_paid_at,
                'created_at' => $vente->created_at->format('Y-m-d H:i:s'),
            ]),
            'total' => $ventes->sum(fn ($vente) => $vente->price * $vente->quantity),
        ]);
    }
}
