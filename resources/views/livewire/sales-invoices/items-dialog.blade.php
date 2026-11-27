@php use App\Support\XofFormatter; @endphp

<div>
    @if($invoice)
        <x-modal title="Articles de la Facture — {{ $invoice->customer?->name }}" close-action="close">
            <x-notices :success="$successMessage" :error="$errorMessage" />

            @if(! $invoice->paid)
                <div class="flex justify-end">
                    <x-button wire:click="$toggle('isAddFormOpen')"><i class="mdi mdi-plus"></i> Ajouter un Article</x-button>
                </div>
            @endif

            @if($isAddFormOpen && ! $invoice->paid)
                <form wire:submit="addItem" class="grid grid-cols-1 items-end gap-3 rounded-lg bg-gray-50 p-4 sm:grid-cols-4">
                    <label class="text-xs font-semibold text-gray-500">Produit
                        <select wire:model.live="newItem.product_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option value="">—</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-gray-500">Quantité
                        <input type="number" min="1" wire:model="newItem.quantity" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-gray-500">Prix
                        <input type="number" min="0" wire:model="newItem.price" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <x-button type="submit" wire:loading.attr="disabled">Ajouter</x-button>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-3 py-2 text-left">Produit</th>
                            <th class="px-3 py-2 text-right">Quantité</th>
                            <th class="px-3 py-2 text-right">Prix</th>
                            <th class="px-3 py-2 text-right">Sous-total</th>
                            <th class="px-3 py-2 text-right">Bénéfice</th>
                            <th class="px-3 py-2 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($invoice->items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $item->product?->name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $item->quantity }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ XofFormatter::format($item->price) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ XofFormatter::format($item->price * $item->quantity) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    @if($editedItemId === $item->id)
                                        <input type="number" wire:model="editedProfit" wire:keydown.enter="saveProfit" aria-label="Bénéfice"
                                               class="w-28 rounded-lg border-gray-300 text-right text-sm">
                                    @else
                                        {{ XofFormatter::format($item->profit) }}
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex justify-center gap-1">
                                        @if($editedItemId === $item->id)
                                            <x-icon-button icon="mdi-check" title="Sauvegarder" color="text-emerald-600" wire:click="saveProfit" />
                                            <x-icon-button icon="mdi-close" title="Annuler" color="text-red-600" wire:click="cancelEditing" />
                                        @else
                                            <x-icon-button icon="mdi-pencil" title="Modifier le bénéfice" color="text-blue-600"
                                                           wire:click="startEditingProfit({{ $item->id }})" />
                                            @if(! $invoice->paid)
                                                <x-icon-button icon="mdi-delete" title="Supprimer" color="text-red-600"
                                                               wire:click="removeItem({{ $item->id }})"
                                                               wire:confirm="Êtes-vous sûr de vouloir supprimer cet article ?" />
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 bg-gray-50 font-bold">
                            <td colspan="3" class="px-3 py-2 text-right">Total :</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ XofFormatter::format($invoice->total_amount) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ XofFormatter::format($invoice->total_estimated_profit) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-modal>
    @endif
</div>
