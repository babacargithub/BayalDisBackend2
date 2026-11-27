@php use App\Support\XofFormatter; @endphp

<div>
    @if($invoice)
        <x-modal title="Paiements — {{ $invoice->customer?->name }}" close-action="close">
            <x-notices :success="$successMessage" :error="$errorMessage" />

            <div class="grid grid-cols-3 gap-3">
                <x-kpi-card label="Montant total" :value="XofFormatter::format($invoice->total_amount)" />
                <x-kpi-card label="Montant payé" :value="XofFormatter::format($invoice->total_payments)"
                            border-class="border-t-emerald-600" value-class="text-emerald-600" />
                <x-kpi-card label="Reste à payer" :value="XofFormatter::format($invoice->total_remaining)"
                            border-class="border-t-red-600" value-class="text-red-600" />
            </div>

            @if(! $invoice->paid)
                <div class="flex justify-end">
                    <x-button wire:click="$toggle('isAddFormOpen')"><i class="mdi mdi-plus"></i> Ajouter un Paiement</x-button>
                </div>
            @endif

            @if($isAddFormOpen && ! $invoice->paid)
                <form wire:submit="addPayment" class="grid grid-cols-1 items-end gap-3 rounded-lg bg-gray-50 p-4 sm:grid-cols-4">
                    <label class="text-xs font-semibold text-gray-500">Montant
                        <input type="number" min="1" wire:model="newPayment.amount" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-gray-500">Méthode de paiement
                        <select wire:model="newPayment.payment_method" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option value="">—</option>
                            @foreach($paymentMethods as $methodValue => $methodLabel)
                                <option value="{{ $methodValue }}">{{ $methodLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-gray-500">Commentaire
                        <input type="text" wire:model="newPayment.comment" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <x-button type="submit" wire:loading.attr="disabled">Ajouter le Paiement</x-button>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-3 py-2 text-left">Date</th>
                            <th class="px-3 py-2 text-right">Montant</th>
                            <th class="px-3 py-2 text-right">Commission</th>
                            <th class="px-3 py-2 text-left">Payé par</th>
                            <th class="px-3 py-2 text-left">Commentaire</th>
                            <th class="px-3 py-2 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($invoice->payments as $payment)
                            <tr wire:key="payment-{{ $payment->id }}" class="{{ $payment->cancelled_at ? 'line-through opacity-55' : '' }}">
                                <td class="px-3 py-2 tabular-nums">{{ $payment->created_at->format('d/m/Y') }}</td>
                                @if($editedPaymentId === $payment->id)
                                    <td class="px-3 py-2">
                                        <input type="number" min="1" wire:model="editedPayment.amount" aria-label="Montant" class="w-28 rounded-lg border-gray-300 text-sm">
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ XofFormatter::format($payment->commercial_commission) }}</td>
                                    <td class="px-3 py-2">
                                        <select wire:model="editedPayment.payment_method" aria-label="Méthode de paiement" class="rounded-lg border-gray-300 text-sm">
                                            @foreach($paymentMethods as $methodValue => $methodLabel)
                                                <option value="{{ $methodValue }}">{{ $methodLabel }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model="editedPayment.comment" aria-label="Commentaire" class="w-full rounded-lg border-gray-300 text-sm">
                                    </td>
                                @else
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        {{ XofFormatter::format($payment->amount) }}
                                        @if($payment->cancelled_at)
                                            <span class="ml-1 rounded-full bg-red-100 px-2 text-xs text-red-800"
                                                  title="Annulé le {{ $payment->cancelled_at->format('d/m/Y') }}{{ $payment->cancelledBy ? ' par '.$payment->cancelledBy->name : '' }}{{ $payment->cancellation_reason ? ' — '.$payment->cancellation_reason : '' }}">
                                                Annulé
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-purple-700">{{ XofFormatter::format($payment->commercial_commission) }}</td>
                                    <td class="px-3 py-2">{{ $payment->payment_method }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ $payment->comment }}</td>
                                @endif
                                <td class="px-3 py-2">
                                    <div class="flex justify-center gap-1">
                                        @if($editedPaymentId === $payment->id)
                                            <x-icon-button icon="mdi-check" title="Sauvegarder" color="text-emerald-600" wire:click="updatePayment" />
                                            <x-icon-button icon="mdi-close" title="Annuler" color="text-red-600" wire:click="cancelEditing" />
                                        @elseif(! $payment->cancelled_at)
                                            <x-icon-button icon="mdi-pencil" title="Modifier" color="text-blue-600" wire:click="startEditing({{ $payment->id }})" />
                                            <x-icon-button icon="mdi-cancel" title="Annuler le paiement" color="text-yellow-600" wire:click="startCancellation({{ $payment->id }})" />
                                            <x-icon-button icon="mdi-delete" title="Supprimer" color="text-red-600"
                                                           wire:click="deletePayment({{ $payment->id }})"
                                                           wire:confirm="Êtes-vous sûr de vouloir supprimer ce paiement ?" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if($cancelledPaymentId === $payment->id)
                                <tr wire:key="cancellation-{{ $payment->id }}">
                                    <td colspan="6" class="space-y-3 bg-yellow-50 px-3 py-3">
                                        <p class="text-sm text-yellow-800">
                                            L'annulation de ce paiement de <strong>{{ XofFormatter::format($payment->amount) }} XOF</strong>
                                            va recalculer les totaux de la facture, la commission du commercial et retirer le montant de la caisse concernée.
                                        </p>
                                        <textarea wire:model="cancellationReason" rows="2" placeholder="Motif de l'annulation"
                                                  aria-label="Motif de l'annulation" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                                        <div class="flex justify-end gap-2">
                                            <x-button variant="secondary" wire:click="abortCancellation">Retour</x-button>
                                            <x-button variant="warning" wire:click="confirmCancellation" wire:loading.attr="disabled">Confirmer l'annulation</x-button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Aucun paiement</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-modal>
    @endif
</div>
