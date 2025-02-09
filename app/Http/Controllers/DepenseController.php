<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\TypeDepense;
use App\Models\Caisse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class DepenseController extends Controller
{
    public function index(): Response
    {
        $depenses = Depense::with('typeDepense')
            ->latest()
            ->get()
            ->map(function ($depense) {
                return [
                    'id' => $depense->id,
                    'amount' => $depense->amount,
                    'comment' => $depense->comment,
                    'type' => [
                        'id' => $depense->typeDepense->id,
                        'name' => $depense->typeDepense->name,
                    ],
                    'created_at' => $depense->created_at,
                ];
            });

        $types = TypeDepense::orderBy('name')
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                ];
            });

        $totalDepenses = $depenses->sum('amount');

        return Inertia::render('Depenses/Index', [
            'depenses' => $depenses,
            'types' => $types,
            'totalDepenses' => $totalDepenses,
            'caisses' => Caisse::where('closed', false)->get()
        ]);
    }

    public function storeType(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:type_depenses'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.unique' => 'Ce type de dépense existe déjà',
        ]);

        TypeDepense::create($validated);

        return back()->with('success', 'Type de dépense ajouté avec succès');
    }

    public function updateType(Request $request, TypeDepense $typeDepense)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:type_depenses,name,' . $typeDepense->id],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.unique' => 'Ce type de dépense existe déjà',
        ]);

        $typeDepense->update($validated);

        return back()->with('success', 'Type de dépense mis à jour avec succès');
    }

    public function destroyType(TypeDepense $typeDepense)
    {
        if ($typeDepense->depenses()->exists()) {
            return back()->with('error', 'Ce type de dépense ne peut pas être supprimé car il est utilisé par des dépenses');
        }

        $typeDepense->delete();

        return back()->with('success', 'Type de dépense supprimé avec succès');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:0'],
            'type_depense_id' => ['required', 'exists:type_depenses,id'],
            'comment' => ['nullable', 'string'],
            'caisse_id' => 'required|exists:caisses,id'

        ], [
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être positif',
            'type_depense_id.required' => 'Le type de dépense est obligatoire',
            'type_depense_id.exists' => 'Le type de dépense sélectionné n\'existe pas',
        ]);
        DB::beginTransaction();
             $depense= Depense::create($validated);    
        // Update caisse balance
        $caisse = Caisse::findOrFail($validated['caisse_id']);  
        $caisse->transactions()->create([
            'amount' => -$validated['amount'],
            'label' => "Dépense: " . $depense->typeDepense->name,
            'transaction_type' => 'WITHDRAW'
        ]);
        $caisse->balance -= $validated['amount'];
        $caisse->save();    

            DB::commit();

            return redirect()->back()->with('success', 'Dépense enregistrée avec succès');
        
    }

    public function update(Request $request, Depense $depense)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:0'],
            'type_depense_id' => ['required', 'exists:type_depenses,id'],
            'comment' => ['nullable', 'string'],
        ], [
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être positif',
            'type_depense_id.required' => 'Le type de dépense est obligatoire',
            'type_depense_id.exists' => 'Le type de dépense sélectionné n\'existe pas',
        ]);

        $depense->update($validated);

        return back()->with('success', 'Dépense mise à jour avec succès');
    }

    public function destroy(Depense $depense)
    {
        // delete depense and put the amount back to caisse
     DB::transaction(function () use ($depense) {
         $depense->delete();
         if ($depense->caisse_id){
                $caisse = Caisse::findOrFail($depense->caisse_id);
                $caisse->transactions()->create([
                    'amount' => $depense->amount,
                    'label' => "Annulation de dépense: " . $depense->typeDepense->name. " ".$depense->comment." avec id: "
                        .$depense->id,
                    'transaction_type' => 'DEPOSIT'
                ]);
                $caisse->balance += $depense->amount;
                $caisse->save();
            }
        });


        return back()->with('success', 'Dépense supprimée avec succès');
    }
} 