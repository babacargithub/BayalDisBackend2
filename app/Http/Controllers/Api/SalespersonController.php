<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Vente;
use Doctrine\DBAL\Query\QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SalespersonController extends Controller
{
    /**
     * Get today's client count for the authenticated salesperson
     */
    public function getTodayClientsCount(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $count = $commercial->clients()
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * Get all clients created by the authenticated salesperson
     */
    public function getClients(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $query = $commercial->clients()->latest();

        // Get today's count if requested
        $todayCount = null;
        if ($request->has('include_today_count')) {
            $todayCount = $commercial->clients()
                ->whereDate('created_at', today())
                ->count();
        }

        $clients = $query->get();

        return response()->json([
            'clients' => $clients,
            'today_count' => $todayCount,
        ]);
    }

    /**
     * Create a new client
     */
    public function createClient(Request $request)
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
            'address' => 'nullable|string|max:255',
        ], $messages);

        $commercial = $request->user()->commercial;
        
        $client = $commercial->clients()->create($validated);

        return response()->json($client, 201);
    }

    /**
     * Update an existing client
     */
    public function updateClient(Request $request, Customer $client)
    {
        // Check if the client belongs to the authenticated salesperson
        if ($client->commercial_id !== $request->user()->commercial->id) {
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
            'address' => 'nullable|string|max:255',
        ], $messages);

        $client->update($validated);

        return response()->json($client);
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

        // Verify that the client belongs to this salesperson
        $client = $commercial->clients()->findOrFail($validated['customer_id']);
        
        $vente = $commercial->ventes()->create([
            'product_id' => $validated['product_id'],
            'customer_id' => $validated['customer_id'],
            'quantity' => $validated['quantity'],
            'price' => $validated['price'],
            'paid' => $validated['paid'],
            'paid_at' => $validated['paid'] ? now() : null,
            'should_be_paid_at' => $validated['should_be_paid_at'] ?? null,
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

    public function getClientVentes(Request $request, Customer $client)
    {
        // Verify that the client belongs to the authenticated commercial
        if ($client->commercial_id !== $request->user()->commercial->id) {
            return response()->json([
                'message' => 'Ce client ne vous appartient pas'
            ], 403);
        }

        $query = $client->ventes()->with('product')->latest();

        // Filter by payment status if specified
        if ($request->has('paid')) {
            $query->where('paid', $request->boolean('paid'));
        }
        return $this->venteResource($query);
    }

    public function payVente(Request $request, Vente $vente)
    {

        // Verify that the vente is not already paid
        if ($vente->paid) {
            return response()->json([
                'message' => 'Cette vente est déjà payée'
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:Wave,OM,Cash',
        ], [
            'payment_method.required' => 'La méthode de paiement est requise',
            'payment_method.in' => 'La méthode de paiement doit être Wave, OM ou Cash',
        ]);

        // if payement method is Cash we update the paid_at field
        if (strtolower($validated['payment_method']) === 'cash') {
            $vente->update([
                'paid' => true,
                'paid_at' => now()
            ]);
        } else {
            // if Wave
            if (strtolower($validated['payment_method']) === 'wave') {
                // TODO fetch the wave payment url and return it
            }else if (strtolower($validated['payment_method']) === 'om') {
                // TODO trigger the orange money payment  and return it
            }
        }

        return response()->json([
            'message' => 'Paiement enregistré avec succès',
            'vente' => $vente->load('product'),
        ]);
    }


    public function venteResource($query): \Illuminate\Http\JsonResponse
    {
        $ventes = $query->get();
        return response()->json([
            'ventes' => $ventes->map(function (Vente $vente) {
                return [
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
                ];
            }),
            'total' => $ventes->sum(function ($vente) {
                return $vente->price * $vente->quantity;
            }),
        ]);
    }
}
