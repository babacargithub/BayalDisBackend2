<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\CaisseTransaction;
use App\Models\Commercial;
use App\Services\AccountService;
use App\Services\CaisseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CaisseController extends Controller
{
    public function index()
    {
        return Inertia::render('Caisse/Index', [
            'caisses' => Caisse::all(),
            'totalCaissesBalance' => (int) Caisse::query()->sum('balance'),
            'totalAccountsBalance' => (int) Account::query()->sum('balance'),
            'debitableAccounts' => Account::query()
                ->where('balance', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'balance']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'balance' => 'required|integer|min:0',
            'closed' => 'boolean',
        ]);

        Caisse::create($validated);

        return redirect()->route('caisses.index')->with('success', 'Caisse créée avec succès');
    }

    public function update(Request $request, Caisse $caisse)
    {
        try {
            Log::info('Updating caisse', [
                'caisse_id' => $caisse->id,
                'request_data' => $request->all(),
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'balance' => 'required|integer|min:0',
                'closed' => 'boolean',
            ]);

            $caisse->update($validated);

            Log::info('Caisse updated successfully', [
                'caisse_id' => $caisse->id,
                'updated_data' => $validated,
            ]);

            return redirect()->route('caisses.index')->with('success', 'Caisse mise à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Error updating caisse', [
                'caisse_id' => $caisse->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la mise à jour de la caisse: '.$e->getMessage()])
                ->withInput();
        }
    }

    public function destroy(Caisse $caisse)
    {
        if ($caisse->balance !== 0) {
            return redirect()->back()->withErrors([
                'error' => "Impossible de supprimer la caisse «{$caisse->name}» : son solde doit être à zéro avant suppression.",
            ]);
        }

        try {
            $caisse->delete();

            return redirect()->route('caisses.index')->with('success', 'Caisse supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la suppression de la caisse']);
        }
    }

    public function sortieDeCaisse(Request $request, AccountService $accountService)
    {
        $validated = $request->validate([
            'caisse_id' => ['required', 'integer', 'exists:caisses,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:255'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
        ], [
            'caisse_id.required' => 'La caisse source est obligatoire.',
            'caisse_id.exists' => 'La caisse sélectionnée est introuvable.',
            'amount.required' => 'Le montant est obligatoire.',
            'amount.min' => 'Le montant doit être supérieur à zéro.',
            'label.required' => 'Le libellé est obligatoire.',
            'account_ids.required' => 'Veuillez sélectionner au moins un compte à débiter.',
            'account_ids.min' => 'Veuillez sélectionner au moins un compte à débiter.',
        ]);

        $caisse = Caisse::findOrFail($validated['caisse_id']);
        $orderedAccountIds = $validated['account_ids'];

        if ($caisse->balance < $validated['amount']) {
            return back()->withErrors([
                'caisse_id' => "Le solde de la caisse «{$caisse->name}» ({$caisse->balance} F) est insuffisant pour cette sortie de {$validated['amount']} F.",
            ]);
        }

        // Validate that the total balance of selected accounts covers the amount.
        $totalSelectedBalance = Account::whereIn('id', $orderedAccountIds)->sum('balance');

        if ($totalSelectedBalance < $validated['amount']) {
            return back()->withErrors([
                'account_ids' => "Le solde total des comptes sélectionnés ({$totalSelectedBalance} F) est insuffisant pour couvrir le montant de la sortie ({$validated['amount']} F).",
            ]);
        }

        $accountService->processSortieDeCaisse(
            caisse: $caisse,
            amount: $validated['amount'],
            label: $validated['label'],
            orderedAccountIds: $orderedAccountIds,
        );

        return back()->with('success', 'Sortie de caisse enregistrée avec succès.');
    }

    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'from_caisse_id' => 'required|exists:caisses,id',
            'to_caisse_id' => 'required|exists:caisses,id|different:from_caisse_id',
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ], [
            'from_caisse_id.required' => 'La caisse source est obligatoire',
            'to_caisse_id.required' => 'La caisse destination est obligatoire',
            'to_caisse_id.different' => 'Les caisses source et destination doivent être différentes',
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être supérieur à 0',
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $fromCaisse = Caisse::findOrFail($validated['from_caisse_id']);
                $toCaisse = Caisse::findOrFail($validated['to_caisse_id']);

                // Check if source caisse has enough balance
                if ($fromCaisse->balance < $validated['amount']) {
                    throw new \Exception('Solde insuffisant dans la caisse source');
                }

                // Create withdrawal transaction for source caisse
                $fromCaisse->transactions()->create([
                    'amount' => -$validated['amount'],
                    'label' => 'Transfert vers '.$toCaisse->name.($validated['description'] ? ' - '.$validated['description'] : ''),
                    'transaction_type' => 'WITHDRAW',
                ]);
                $fromCaisse->decrement('balance', $validated['amount']);

                // Create deposit transaction for destination caisse
                $toCaisse->transactions()->create([
                    'amount' => $validated['amount'],
                    'label' => 'Transfert depuis '.$fromCaisse->name.($validated['description'] ? ' - '.$validated['description'] : ''),
                    'transaction_type' => 'DEPOSIT',
                ]);
                $toCaisse->increment('balance', $validated['amount']);
            });

            return redirect()->back()->with('success', 'Transfert effectué avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
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
                    'created_at' => $transaction->created_at,
                ];
            });

        if (request()->wantsJson()) {
            return response()->json([
                'transactions' => $transactions,
            ]);
        }

        return Inertia::render('Caisse/Transactions', [
            'caisse' => $caisse,
            'transactions' => $transactions,
        ]);
    }

    public function storeTransaction(Request $request, Caisse $caisse)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'amount' => 'required|integer|not_in:0',
                'label' => 'required|string|max:255',
            ]);

            $caisse->transactions()->create($validated);

            $caisse->updateBalanceFromLedger();

            DB::commit();

            return redirect()->back()->with('success', 'Transaction enregistrée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors(['error' => 'Erreur lors de l\'enregistrement de la transaction']);
        }
    }

    /**
     * Close the day for the commercial that owns the given caisse.
     *
     * POST /caisses/{caisse}/close-day
     */
    public function closeDay(Caisse $caisse, CaisseService $closeDayService): JsonResponse
    {
        $commercial = $caisse->commercial;

        if ($commercial === null) {
            return response()->json([
                'message' => 'Cette caisse n\'est pas associée à un commercial.',
            ], 422);
        }

        try {
            $closeDayService->closeCaisseForDay($commercial, Carbon::today());
        } catch (DayAlreadyClosedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => "La journée a été clôturée pour «{$commercial->name}».",
        ]);
    }

    public function destroyTransaction(Caisse $caisse, CaisseTransaction $transaction)
    {
        try {
            DB::beginTransaction();

            $caisse->updateBalanceFromLedger();

            $transaction->delete();

            DB::commit();

            return redirect()->back()->with('success', 'Transaction supprimée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors(['error' => 'Erreur lors de la suppression de la transaction']);
        }
    }
}
