<div>
    @if($isOpen)
        <x-modal title="Nouvelle Facture" close-action="close">
            <x-notices :error="$errorMessage" />

            <form wire:submit="create" class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-gray-500">Client
                        <input type="search" wire:model.live.debounce.300ms="customerSearch"
                               placeholder="{{ $customerName ?: 'Tapez au moins 2 lettres…' }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    @if($customerName)
                        <p class="mt-1 text-sm text-gray-700">Client choisi : <strong>{{ $customerName }}</strong></p>
                    @endif
                    @if($matchingCustomers->isNotEmpty())
                        <ul class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200">
                            @foreach($matchingCustomers as $customer)
                                <li wire:key="customer-{{ $customer->id }}">
                                    <button type="button" wire:click="selectCustomer({{ $customer->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50">
                                        {{ $customer->name }} <span class="text-xs text-gray-400">{{ $customer->address }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-gray-500">Commentaire
                        <input type="text" wire:model="comment" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-gray-500">Date d'échéance
                        <input type="date" wire:model="dueDate" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                </div>

                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-bold text-gray-900">Articles</h4>
                    <x-button variant="secondary" wire:click="addItemLine"><i class="mdi mdi-plus"></i> Ajouter un Article</x-button>
                </div>

                @foreach($items as $lineIndex => $item)
                    <div class="flex items-end gap-2" wire:key="line-{{ $item['key'] }}">
                        <label class="flex-1 text-xs font-semibold text-gray-500">Produit
                            <select wire:model.live="items.{{ $lineIndex }}.product_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option value="">—</option>
                                @foreach($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="w-24 text-xs font-semibold text-gray-500">Quantité
                            <input type="number" min="1" wire:model="items.{{ $lineIndex }}.quantity" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <label class="w-32 text-xs font-semibold text-gray-500">Prix
                            <input type="number" min="0" wire:model="items.{{ $lineIndex }}.price" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <x-icon-button icon="mdi-delete" title="Supprimer" color="text-red-600" wire:click="removeItemLine({{ $lineIndex }})" />
                    </div>
                @endforeach

                <div class="flex justify-end gap-2">
                    <x-button variant="secondary" wire:click="close">Annuler</x-button>
                    <x-button type="submit" wire:loading.attr="disabled">Créer la Facture</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
