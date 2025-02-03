<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Vente;
use App\Models\CustomerVisit;
use App\Models\VisitBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Commercial;
use Carbon\Carbon;
use App\Models\Order;
use App\Services\CustomerVisitService;

class SalespersonController extends Controller
{
    protected $visitService;

    public function __construct(CustomerVisitService $visitService)
    {
        $this->visitService = $visitService;
    }

    /**
     * Get today's customer count for the authenticated salesperson
     */
    public function getTodayCustomersCount(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $count = $commercial->customers()
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * Get all customers created by the authenticated salesperson
     */
    public function getCustomers(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $query = $commercial->customers()->latest();

        // Get today's count if requested
        $todayCount = null;
        if ($request->has('include_today_count')) {
            $todayCount = $commercial->customers()
                ->whereDate('created_at', today())
                ->count();
        }

        $customers = Customer::latest()->get();

        return response()->json([
            'customers' => $customers,
            'today_count' => $todayCount,
        ]);
    }

    public function createCustomer(Request $request)
    {
        $messages = [
            'name.required' => 'Le nom est obligatoire',
            'name.string' => 'Le nom doit être une chaîne de caractères',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            
            'phone_number.required' => 'Le numéro de téléphone est obligatoire',
            'phone_number.numeric' => 'Le numéro de téléphone doit être numérique',
            'phone_number.digits' => 'Le numéro de téléphone doit contenir 9 chiffres',
            'phone_number.unique' => 'Ce numéro de téléphone est déjà utilisé',
            
            'owner_phone_number.required' => 'Le numéro du propriétaire est obligatoire',
            'owner_phone_number.numeric' => 'Le numéro du propriétaire doit être numérique',
            'owner_phone_number.digits' => 'Le numéro du propriétaire doit contenir 9 chiffres',
            
            'latitude.required' => 'La latitude est obligatoire',
            'latitude.numeric' => 'La latitude doit être un nombre',
            
            'longitude.required' => 'La longitude est obligatoire',
            'longitude.numeric' => 'La longitude doit être un nombre',
            
            'address.max' => 'L\'adresse ne doit pas dépasser 255 caractères',
        ];

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|numeric|digits:9|unique:customers,phone_number',
            'owner_number' => 'required|numeric|digits:9',
            'gps_coordinates' => 'required|string',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
        ], $messages);

        $commercial = $request->user()->commercial;
        
        $customer = $commercial->customers()->create($validated);

        return response()->json($customer, 201);
    }

    /**
     * Update an existing customer
     */
    public function updateCustomer(Request $request, Customer $customer)
    {
        // Check if the customer belongs to the authenticated salesperson
        if ($customer->commercial_id !== $request->user()->commercial->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $messages = [
            'name.required' => 'Le nom est obligatoire',
            'name.string' => 'Le nom doit être une chaîne de caractères',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            
            'phone_number.required' => 'Le numéro de téléphone est obligatoire',
            'phone_number.numeric' => 'Le numéro de téléphone doit être numérique',
            'phone_number.digits' => 'Le numéro de téléphone doit contenir 9 chiffres',
            
            'owner_phone_number.required' => 'Le numéro du propriétaire est obligatoire',
            'owner_phone_number.numeric' => 'Le numéro du propriétaire doit être numérique',
            'owner_phone_number.digits' => 'Le numéro du propriétaire doit contenir 9 chiffres',
            
            'latitude.required' => 'La latitude est obligatoire',
            'latitude.numeric' => 'La latitude doit être un nombre',
            
            'longitude.required' => 'La longitude est obligatoire',
            'longitude.numeric' => 'La longitude doit être un nombre',
            
            'address.max' => 'L\'adresse ne doit pas dépasser 255 caractères',
        ];

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|numeric|digits:9',
            'owner_number' => 'required|numeric|digits:9',
            'gps_coordinates' => 'string',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
        ], $messages);

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Create a new vente
     */
    public function createVente(Request $request)
    {
        $messages = [
            'customer_id.required' => 'Le client est obligatoire',
            'customer_id.exists' => 'Le client sélectionné n\'existe pas',
            
            'product_id.required' => 'Le produit est obligatoire',
            'product_id.exists' => 'Le produit sélectionné n\'existe pas',
            
            'quantity.required' => 'La quantité est obligatoire',
            'quantity.integer' => 'La quantité doit être un nombre entier',
            'quantity.min' => 'La quantité doit être au moins 1',
            
            'price.required' => 'Le prix est obligatoire',
            'price.numeric' => 'Le prix doit être un nombre',
            'price.min' => 'Le prix doit être positif',
            
            'paid.required' => 'Le statut de paiement est obligatoire',
            'paid.boolean' => 'Le statut de paiement doit être vrai ou faux',
            
            'should_be_paid_at.required_if' => 'La date de paiement est obligatoire si la vente n\'est pas payée',
            'should_be_paid_at.date' => 'La date de paiement n\'est pas valide',
        ];

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required_if:paid,true|string|nullable',
            'paid' => 'required|boolean',
            'should_be_paid_at' => 'nullable|required_if:paid,false|date',
        ], $messages);

        $commercial = $request->user()->commercial;

        // Verify that the customer belongs to this salesperson
        $customer = Customer::findOrFail($validated['customer_id']);
        
        $vente = $commercial->ventes()->create([
            'product_id' => $validated['product_id'],
            'customer_id' => $validated['customer_id'],
            'quantity' => $validated['quantity'],
            'price' => $validated['price'],
            'paid' => $validated['paid'],
            'paid_at' => $validated['paid'] ? now() : null,
            'should_be_paid_at' => $validated['should_be_paid_at'] ?? null,
            'payment_method' => $validated['payment_method'] ?? "Cash",
        ]);

        // Load the relationships
        $vente->load(['customer', 'product']);

        $response = [
            'vente' => [
                'id' => $vente->id,
                'product' => $vente->product->name,
                'customer' => $vente->customer->name,
                'customer_phone_number' => $vente->customer->phone_number,
                'quantity' => $vente->quantity,
                'price' => $vente->price,
                'total' => $vente->price * $vente->quantity,
                'paid' => (bool)$vente->paid,
                'paid_at' => $vente->paid_at?->format('Y-m-d H:i:s'),
                'should_be_paid_at' => $vente->should_be_paid_at?->format('Y-m-d H:i:s'),
                'created_at' => $vente->created_at->format('Y-m-d H:i:s'),
            ],
        ];

        // Add Wave payment URL if Wave is selected as payment method
        if (strtolower($validated['payment_method']) == 'wave') {
            $response['vente']['wave_payment_url'] = 'https://pay.wave.com/m/M_lzWrf_pI8keK/c/sn/?amount=' . $vente->price * $vente->quantity;
        }

        return response()->json($response, 201);
    }

    public function getCustomerVentes(Request $request, Customer $customer)
    {
        // Verify that the customer belongs to the authenticated commercial
        if ($customer->commercial_id !== $request->user()->commercial->id) {
            return response()->json([
                'message' => 'Ce client ne vous appartient pas'
            ], 403);
        }

        $query = $customer->ventes()->with('product')->latest();

        // Filter by payment status if specified
        if ($request->has('paid')) {
            $query->where('paid', $request->boolean('paid'));
        }
        return $this->venteResource($query);
    }

    public function payVente(Request $request, Vente $vente)
    {

            $validated = $request->validate([
                'payment_method' => 'required|in:' . implode(',', [
                    Vente::PAYMENT_METHOD_CASH,
                    Vente::PAYMENT_METHOD_WAVE,
                    Vente::PAYMENT_METHOD_OM,
                ]),
            ]);

        $vente->paid = true;
        $vente->paid_at = now();
        $vente->payment_method = $validated['payment_method'];
        $vente->save();

        return response()->json($vente->load(['customer', 'product']));
    }

    public function paySalesInvoice(Request $request, \App\Models\SalesInvoice $invoice)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'payment_method' => 'required',
            'comment' => 'nullable|string',
        ], [
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être supérieur à 0',
            'payment_method.required' => 'La méthode de paiement est obligatoire',
        ]);
        DB::transaction(function () use ($invoice, $validated) {

            // Create the payment
            $payment = $invoice->payments()->create([
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'comment' => $validated['comment'],
            ]);

            // Check if invoice is fully paid
            $totalPaid = $invoice->payments()->sum('amount') + $validated['amount'];
            if ($totalPaid >= $invoice->total) {
                $invoice->update(['paid' => true]);
                $invoice->items()->update([
                    'paid' => true,
                    'paid_at' => now(),
                ]);
            }
        });


      
            return response()->json([
                'message' => 'Paiement effectué avec succès',
                'data' => $invoice->load(['items.product', 'customer', 'payments']),
            ]);
      
    }

    public function getVentes(Request $request)
    {
        $query = Vente::with(['product', 'customer', 'commercial'])
            ->whereHas('commercial', function ($query) {
                $query->where('id', auth()->id());
            })->whereDate("created_at", today()->toDateString())
            ->latest();

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('paid')) {
            $query->where('paid', $request->paid === 'true');
        }

        return $this->venteResource($query);
    }

    public function venteResource($query): JsonResponse
    {
        $ventes = $query->get();
        return response()->json([
            'ventes' => $ventes->map(function (Vente $vente) {
                return [
                    'id' => $vente->id,
                    'product' => $vente->product?->name,
                    'customer' => $vente->customer?->name,
                    'customer_phone_number' => $vente->customer?->phone_number,
                    'quantity' => $vente->quantity,
                    'price' => $vente->price,
                    'total' => $vente->price * $vente->quantity,
                    'paid' => (bool)$vente->paid,
                    'paid_at' => $vente->paid_at?->format('Y-m-d H:i:s'),
                    'should_be_paid_at' => $vente->should_be_paid_at,
                    'created_at' => $vente->created_at->format('Y-m-d H:i:s'),
                ];
            }),
            'total' => $ventes->sum(function ($vente) {
                return $vente->price * $vente->quantity;
            }),
        ]);
    }

    public function getCustomersAndProducts(Request $request    )
    {
        $customers = Customer::latest()->get();
        $products = Product::all();

        return response()->json([
            'customers' => $customers,
            'products' => $products
        ]);
    }

    /**
     * Get activity report for the authenticated salesperson
     */
    public function getActivityReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:daily,weekly',
        ]);

        $commercial = auth()->user()->commercial;
        if (!$commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        // Parse the date and set the time range
        $date = Carbon::parse($validated['date']);
        $startDate = $validated['type'] === 'weekly' 
            ? $date->copy()->startOfWeek() 
            : $date->copy()->startOfDay();
        $endDate = $validated['type'] === 'weekly' 
            ? $date->copy()->endOfWeek() 
            : $date->copy()->endOfDay();

        // Get total customers and prospects
        // filter by date
        $totalCustomers = $commercial->customers()->whereHas('ventes')->whereBetween('created_at', [$startDate, $endDate])->count();
        $prospectsCount = $commercial->customers()->whereDoesntHave('ventes')->whereBetween('created_at', [$startDate, $endDate])->count();

        // Get customers created in period
        $customersCreated = $commercial->customers()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Get product sales
        $productSales = DB::table('ventes')
            ->select(
                'products.id',
                'products.name',
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(ventes.quantity) as total_quantity'),
                DB::raw('SUM(ventes.price * ventes.quantity) as total_amount')
            )
            ->join('products', 'ventes.product_id', '=', 'products.id')
            ->where('ventes.commercial_id', $commercial->id)
            ->whereBetween('ventes.created_at', [$startDate, $endDate])
            ->groupBy('products.id', 'products.name')
            ->get();

        // Get payment method sales
        $paymentMethodSales = DB::table('ventes')
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(price * quantity) as total_amount')
            )
            ->where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->get()
            ->map(function ($sale) {
                return [
                    'payment_method' => $sale->payment_method,
                    'count' => $sale->count,
                    'total_amount' => $sale->total_amount,
                ];
            });

        \Log::info('Activity Report Query', [
            'date' => $validated['date'],
            'type' => $validated['type'],
            'start_date' => $startDate->toDateTimeString(),
            'end_date' => $endDate->toDateTimeString(),
            'customers_created' => $customersCreated,
            'product_sales_count' => $productSales->count(),
            'payment_method_sales_count' => $paymentMethodSales->count()
        ]);

        return response()->json([
            'period' => [
                'start' => $startDate->toDateTimeString(),
                'end' => $endDate->toDateTimeString(),
                'type' => $validated['type'],
            ],
            'customers_created' => $customersCreated,
            'customers_count' => $totalCustomers,
            'prospects_count' => $prospectsCount,
            'product_sales' => $productSales,
            'payment_method_sales' => $paymentMethodSales,
        ]);
    }

    public function getOrders()
    {
        $commercial = auth()->user()->commercial;
        if (!$commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        $orders =Order::
            with(['customer', 'product'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'should_be_delivered_at' => 'required|date',
            'comment' => 'nullable|string',
        ]);

        $commercial = auth()->user()->commercial;
        if (!$commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        $order = $commercial->orders()->create([
            'customer_id' => $validated['customer_id'],
            'product_id' => $validated['product_id'],
            'quantity' => $validated['quantity'],
            'should_be_delivered_at' => $validated['should_be_delivered_at'],
            'comment' => $validated['comment'] ?? null,
            'status' => 'WAITING',
        ]);

        return response()->json([
            'message' => 'Order created successfully',
            'data' => $order->load(['customer', 'product'])
        ], 201);
    }

    public function cancelOrder(Order $order)
    {
        if ($order->status !== 'WAITING') {
            return response()->json(['message' => 'Seules les commandes en attente peuvent etre annulées'], 422);
        }

        $validated = request()->validate([
            'comment' => 'required',
        ], [
            'comment.required' => 'La raison de l\'annulation est obligatoire',
        ]);

        $order->update([
            'status' => 'CANCELLED',
            'comment' => $validated['comment']
        ]);

        return response()->json([
            'message' => 'Order cancelled successfully',
            'data' => $order->load(['customer', 'product'])
        ]);
    }

    public function deliverOrder(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_WAITING) {
            return response()->json(['message' => "Seules les commandes en attente peuvent etre livrées !"], 422);
        }

        $validated = $request->validate([
            'paid' => 'required|boolean',
            'payment_method' => 'required_if:paid,true|nullable|string|in:CASH,WAVE,OM,FREE',
            'should_be_paid_at' => 'required_if:paid,false|nullable|date',
        ]);

            // Create sales invoice
            DB::transaction(function () use ($order, $validated, $request) {
                $salesInvoice = \App\Models\SalesInvoice::create([
                    'customer_id' => $order->customer_id,
                    'paid' => $validated['paid'],
                    'should_be_paid_at' => $validated['should_be_paid_at'] ?? null,
                    'comment' => "Facture de livraison",
                ]);

                // Create invoice items from order items
                $ordersToCreate =[];
                foreach ($order->items as $item) {
                    $ordersToCreate[] = [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'commercial_id' => $request->user()->commercial->id,
                        'type' => 'INVOICE_ITEM',
                        'paid' => $validated['paid'],
                        'payment_method' => $validated['payment_method'] ?? null,
                        'should_be_paid_at' => $validated['should_be_paid_at'] ?? null,
                        'paid_at' => $validated['paid'] ? now() : null,
                    ];
                }
                $salesInvoice->items()->createMany($ordersToCreate);

                // Update order status and link to invoice
                $order->update([
                    'status' => Order::STATUS_DELIVERED,
                    'sales_invoice_id' => $salesInvoice->id,
                ]);

                // If paid, create a payment record
                if ($validated['paid']) {
                    $salesInvoice->payments()->create([
                        'amount' => $salesInvoice->total,
                        'payment_method' => $validated['payment_method'],
                        'comment' => 'Paiement à la livraison',
                    ]);
                }
            });

            return response()->json([
                'message' => 'Order delivered successfully and invoice created',
                'data' => [
                    'order' => $order->load(['customer', 'product']),
                ],
            ]);

    }

    public function updateOrderItems(Request $request, Order $order)
    {
        // Validate request
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|integer|min:0',
        ]);

        try {
            DB::beginTransaction();

            // Delete existing items
            $order->items()->delete();

            // Create new items
            foreach ($validated['items'] as $item) {
                // if exists update the data if not create a new one

                $order->items()->updateOrCreate([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ],["product_id"]);
            }

            DB::commit();

            // Return the updated order with items
            return response()->json([
                'message' => 'Order items updated successfully',
                'data' => $order->load(['items.product', 'customer']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating order items: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating order items',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getCustomerInvoices(Customer $customer)
    {
      
        $invoices = \App\Models\SalesInvoice::with(['items.product', 'payments'])
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'total' => $invoice->total,
                    'paid' => $invoice->paid,
                    'should_be_paid_at' => $invoice->should_be_paid_at,
                    'created_at' => $invoice->created_at,
                    'items' => $invoice->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product' => $item->product,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                        ];
                    }),
                    'payments' => $invoice->payments->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'amount' => $payment->amount,
                            'payment_method' => $payment->payment_method,
                            'comment' => $payment->comment,
                            'created_at' => $payment->created_at,
                        ];
                    }),
                ];
            });

        return response()->json(['data' => $invoices]);
    }

    /**
     * Get all visit batches for the authenticated salesperson
     */
    public function getVisitBatches(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;
        $batches = $this->visitService->getVisitBatches($commercial);
        return response()->json($batches);
    }

    /**
     * Get today's visits for the authenticated salesperson
     */
    public function getTodayVisits(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;
        $data = $this->visitService->getTodayVisits($commercial);
        return response()->json($data);
    }

    /**
     * Get details of a specific visit batch
     */
    public function getVisitBatchDetails(Request $request, \App\Models\VisitBatch $batch): JsonResponse
    {
        $commercial = $request->user()->commercial;
        
        if (!$this->visitService->canAccessVisitBatch($commercial, $batch)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $batch = $this->visitService->getVisitBatchDetails($batch);
        return response()->json($batch);
    }

    /**
     * Mark a visit as completed
     */
    public function completeVisit(Request $request, CustomerVisit $visit): JsonResponse
    {
        $commercial = $request->user()->commercial;
        
        if (!$this->visitService->canAccessVisit($commercial, $customerVisit)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'gps_coordinates' => 'required|string',
            'resulted_in_sale' => 'required|boolean',
        ]);

        $visit = $this->visitService->completeVisit($visit, $validated);
        return response()->json($visit);
    }

    /**
     * Cancel a visit
     */
    public function cancelVisit(Request $request, CustomerVisit $customerVisit): JsonResponse
    {
        $commercial = $request->user()->commercial;
        
        if (!$this->visitService->canAccessVisit($commercial, $customerVisit)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $visit = $this->visitService->cancelVisit($customerVisit, $validated);
        return response()->json($visit);
    }

    /**
     * Update a visit's details
     */
    public function updateVisit(Request $request, CustomerVisit $customerVisit): JsonResponse
    {
        $commercial = $request->user()->commercial;
        
        if (!$this->visitService->canAccessVisit($commercial, $customerVisit)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'visit_planned_at' => 'nullable|date_format:H:i',
        ]);

        $visit = $this->visitService->updateVisit($customerVisit, $validated);
        return response()->json($visit);
    }
}
