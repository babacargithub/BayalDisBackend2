<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\CaisseTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CaisseController extends Controller
{
    public function index()
    {
        return Inertia::render('Caisse/Index', [
            'caisses' => Caisse::all()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'balance' => 'required|integer|min:0',
            'closed' => 'boolean'
        ]);

        Caisse::create($validated);

        return redirect()->route('caisses.index')->with('success', 'Caisse créée avec succès');
    }

    public function update(Request $request, Caisse $caisse)
    {
        try {
            Log::info('Updating caisse', [
                'caisse_id' => $caisse->id,
                'request_data' => $request->all()
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'balance' => 'required|integer|min:0',
                'closed' => 'boolean'
            ]);

            $caisse->update($validated);

            Log::info('Caisse updated successfully', [
                'caisse_id' => $caisse->id,
                'updated_data' => $validated
            ]);

            return redirect()->route('caisses.index')->with('success', 'Caisse mise à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Error updating caisse', [
                'caisse_id' => $caisse->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la mise à jour de la caisse: ' . $e->getMessage()])
                ->withInput();
        }
    }

    public function destroy(Caisse $caisse)
    {
        try {
            $caisse->delete();
            return redirect()->route('caisses.index')->with('success', 'Caisse supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la suppression de la caisse']);
        }
    }

    public function transactions(Caisse $caisse)
    {
        $transactions = $caisse->transactions()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'effective_amount' => $transaction->effective_amount,
                    'label' => $transaction->label,
                    'transaction_type' => $transaction->transaction_type,
                    'created_at' => $transaction->created_at
                ];
            });

        if (request()->wantsJson()) {
            return response()->json([
                'transactions' => $transactions
            ]);
        }

        return Inertia::render('Caisse/Transactions', [
            'caisse' => $caisse,
            'transactions' => $transactions
        ]);
    }

    public function storeTransaction(Request $request, Caisse $caisse)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'amount' => 'required|integer|not_in:0',
                'label' => 'required|string|max:255'
            ]);

            $transaction = $caisse->transactions()->create($validated);
            
            // Update caisse balance using effective amount
            $caisse->balance += $transaction->effective_amount;
            $caisse->save();

            DB::commit();

            return redirect()->back()->with('success', 'Transaction enregistrée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Erreur lors de l\'enregistrement de la transaction']);
        }
    }

    public function destroyTransaction(Caisse $caisse, CaisseTransaction $transaction)
    {
        try {
            DB::beginTransaction();

            // Update caisse balance using effective amount
            $caisse->balance -= $transaction->effective_amount;
            $caisse->save();

            $transaction->delete();

            DB::commit();

            return redirect()->back()->with('success', 'Transaction supprimée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la suppression de la transaction']);
        }
    }
} 