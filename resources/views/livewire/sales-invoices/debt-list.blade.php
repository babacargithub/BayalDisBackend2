@php
    use App\Support\XofFormatter;

    $statusFilters = ['all' => 'Tout', 'paid' => 'Payées', 'unpaid' => 'Non Payées', 'past_due' => 'Factures passées'];
@endphp

<div class="space-y-8">

    <x-page-banner title="Dettes clients" subtitle="Factures en attente de paiement">
        <x-button variant="secondary" wire:click="$dispatch('open-create-invoice')">
            <i class="mdi mdi-plus"></i> Nouvelle Facture
        </x-button>
        @if($debtList->invoiceCount > 0)
            <x-button variant="success" :href="$filteredPdfUrl" target="_blank">
                <i class="mdi mdi-download"></i> Exporter PDF ({{ $debtList->invoiceCount }})
            </x-button>
        @else
            <x-button variant="success" disabled>
                <i class="mdi mdi-download"></i> Exporter PDF (0)
            </x-button>
        @endif
        <x-button variant="danger" :href="route('sales-invoices.unpaid-pdf')" target="_blank">
            <i class="mdi mdi-file-pdf-box"></i> Factures impayées
        </x-button>
        <x-button variant="dark" :href="route('sales-invoices.written-off')">
            <i class="mdi mdi-close-circle-outline"></i> Factures radiées
        </x-button>
    </x-page-banner>

    <x-notices :success="$successMessage" :error="$errorMessage" />

    <x-section-heading title="Suivi des créances" />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-kpi-card label="Total restant à payer" :value="XofFormatter::format($debtList->totalRemaining)" unit="XOF"
                    :hint="$debtList->invoiceCount.' facture(s)'"
                    border-class="border-t-red-600" value-class="text-red-600" unit-class="text-red-400" />
        <x-kpi-card label="Montant facturé" :value="XofFormatter::format($debtList->totalAmount)" unit="XOF"
                    hint="Factures affichées" />
        <x-kpi-card label="Déjà payé" :value="XofFormatter::format($debtList->totalPaid)" unit="XOF"
                    hint="Acomptes reçus"
                    border-class="border-t-emerald-600" value-class="text-emerald-600" unit-class="text-emerald-400" />
        <x-kpi-card label="Échéance dépassée" :value="XofFormatter::format($debtList->overdueRemaining)" unit="XOF"
                    :hint="$debtList->overdueInvoiceCount.' facture(s) en retard'"
                    border-class="border-t-yellow-500" value-class="text-yellow-600" unit-class="text-yellow-500" />
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-center gap-3">
        <input type="search" wire:model.live.debounce.300ms="customerNameSearch"
               placeholder="Rechercher par nom de client" aria-label="Rechercher par nom de client"
               class="w-full sm:w-72 rounded-lg border border-gray-300 text-sm">

        <div class="inline-flex overflow-hidden rounded-lg border border-gray-300">
            @foreach($statusFilters as $statusValue => $statusLabel)
                <button type="button" wire:key="status-{{ $statusValue }}" wire:click="$set('paymentStatus', '{{ $statusValue }}')"
                        class="px-3 py-2 text-sm font-semibold transition-colors {{ $paymentStatus === $statusValue ? 'bg-blue-800 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">
                    {{ $statusLabel }}
                </button>
            @endforeach
        </div>

        <x-button variant="secondary" wire:click="openWeekFilter">
            <i class="mdi mdi-calendar-week"></i> Filtrer par semaines
            @if(count($selectedWeekStartDates) > 0)
                <span class="rounded-full bg-blue-800 px-2 text-xs text-white">{{ count($selectedWeekStartDates) }}</span>
            @endif
        </x-button>

        @if(count($selectedWeekStartDates) > 0)
            <x-button variant="secondary" wire:click="clearWeekFilter">Effacer filtre semaines</x-button>
        @endif

        <span wire:loading class="text-xs text-gray-400">Chargement…</span>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-center gap-3">
        <span class="text-sm font-semibold text-gray-700">{{ count($selectedInvoiceIds) }} facture(s) sélectionnée(s)</span>
        <x-button variant="secondary" wire:click="selectAllDisplayedInvoices" :disabled="$debtList->invoiceCount === 0">
            Tout sélectionner ({{ $debtList->invoiceCount }})
        </x-button>
        @if(count($selectedInvoiceIds) > 0)
            <x-button variant="secondary" wire:click="clearSelection">Tout désélectionner</x-button>
            <span class="flex-1"></span>
            <x-button variant="warning" wire:click="askToWriteOffSelectedInvoices" wire:loading.attr="disabled">
                <i class="mdi mdi-close-circle-outline"></i> Radier la sélection
            </x-button>
            <x-button variant="danger" wire:click="askToDeleteSelectedInvoices" wire:loading.attr="disabled">
                <i class="mdi mdi-delete"></i> Supprimer la sélection
            </x-button>
        @endif
    </div>

    <x-ar-aging.report
        :buckets="$debtList->buckets"
        :total-amount="$debtList->totalRemaining"
        :columns="['select', 'created_at', 'due_date', 'customer', 'customer_phone_number', 'total_amount', 'total_payments', 'remaining', 'total_estimated_profit', 'total_realized_profit', 'age', 'actions']"
        empty-message="Aucune facture à afficher"
    />

    <p class="text-center text-xs text-gray-400 pb-6">
        Bayal Distribution &nbsp;·&nbsp; Rapport généré le {{ ucfirst(now()->locale('fr')->isoFormat('D MMMM YYYY à HH:mm')) }}
    </p>

    @if($isWeekFilterOpen)
        <x-modal title="Filtrer par semaines" close-action="closeWeekFilter" max-width="max-w-xl">
            <div class="flex gap-2">
                <x-button variant="secondary" wire:click="selectAllWeeks">Tout sélectionner</x-button>
                <x-button variant="secondary" wire:click="clearWeekFilter">Tout désélectionner</x-button>
            </div>
            <div class="max-h-96 space-y-2 overflow-y-auto">
                @forelse($debtList->availableWeekLabels as $weekStartDate => $weekLabel)
                    <label class="flex items-center gap-2 text-sm text-gray-700" wire:key="week-{{ $weekStartDate }}">
                        <input type="checkbox" wire:model.live="selectedWeekStartDates" value="{{ $weekStartDate }}" class="rounded-sm border-gray-500">
                        {{ $weekLabel }}
                    </label>
                @empty
                    <p class="py-4 text-center text-sm text-gray-400">Aucune facture trouvée</p>
                @endforelse
            </div>
        </x-modal>
    @endif

    @if($pendingAction === 'delete')
        <x-modal title="Confirmation de suppression" close-action="cancelPendingAction" max-width="max-w-md">
            <p class="text-sm text-gray-600">
                Êtes-vous sûr de vouloir supprimer
                {{ count($pendingInvoiceIds) === 1 ? 'cette facture' : 'ces '.count($pendingInvoiceIds).' factures' }} ?
                Cette action est irréversible.
            </p>
            @if($skippedInvoiceCount > 0)
                <p class="text-sm text-yellow-700">{{ $skippedInvoiceCount }} facture(s) avec des paiements ne seront pas supprimées.</p>
            @endif
            <div class="flex justify-end gap-2">
                <x-button variant="secondary" wire:click="cancelPendingAction">Annuler</x-button>
                <x-button variant="danger" wire:click="confirmPendingAction" wire:loading.attr="disabled">Supprimer</x-button>
            </div>
        </x-modal>
    @endif

    @if($pendingPreview !== null)
        <x-modal title="Radier {{ count($pendingInvoiceIds) === 1 ? 'la facture' : count($pendingInvoiceIds).' factures' }}"
                 close-action="cancelPendingAction" max-width="max-w-md">
            <dl class="grid grid-cols-2 gap-1 rounded-lg bg-gray-50 p-3 text-sm">
                <dt class="text-gray-500">Déjà encaissé (conservé)</dt>
                <dd class="text-right tabular-nums text-emerald-600">{{ XofFormatter::format($pendingPreview->totalCollected) }} XOF</dd>
                <dt class="text-gray-500">Montant perdu</dt>
                <dd class="text-right font-bold tabular-nums text-red-600">{{ XofFormatter::format($pendingPreview->totalRemaining) }} XOF</dd>
            </dl>
            <p class="text-sm text-gray-600">
                Les factures radiées sont considérées comme définitivement perdues. Elles sortent des dettes clients et de tous les totaux,
                et restent consultables dans « Factures radiées ». Leurs paiements ne seront plus modifiables.
                Aucune action de l'application ne permet d'annuler une radiation.
            </p>
            @if($skippedInvoiceCount > 0)
                <p class="text-sm text-yellow-700">{{ $skippedInvoiceCount }} facture(s) entièrement payée(s) ne seront pas radiées.</p>
            @endif
            <div class="flex justify-end gap-2">
                <x-button variant="secondary" wire:click="cancelPendingAction">Annuler</x-button>
                <x-button variant="warning" wire:click="confirmPendingAction" wire:loading.attr="disabled">Radier</x-button>
            </div>
        </x-modal>
    @endif

    <livewire:sales-invoices.items-dialog />
    <livewire:sales-invoices.payments-dialog />
    <livewire:sales-invoices.create-invoice-dialog />
</div>
