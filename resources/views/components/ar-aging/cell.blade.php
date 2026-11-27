@props(['column', 'invoice', 'badgeClass'])

@php use App\Support\XofFormatter; @endphp

@switch($column)
    @case('select')
        <input type="checkbox" wire:model.live="selectedInvoiceIds" value="{{ $invoice['id'] }}"
               aria-label="Sélectionner la facture de {{ $invoice['customer_name'] }}" class="rounded-sm border-gray-500">
        @break

    @case('customer')
        <span class="font-medium text-gray-900">{{ $invoice['customer_name'] }}</span>
        @if(! empty($invoice['customer_address']))
            <span class="block text-xs text-gray-400">{{ $invoice['customer_address'] }}</span>
        @endif
        @break

    @case('customer_phone_number')
        <span class="text-gray-500 tabular-nums">{{ $invoice['customer_phone_number'] }}</span>
        @break

    @case('total_amount')
        <span class="text-gray-500 tabular-nums">{{ XofFormatter::format($invoice['total_amount']) }}</span>
        @break

    @case('total_payments')
        <span class="text-gray-400 tabular-nums">
            {{ $invoice['total_payments'] > 0 ? XofFormatter::format($invoice['total_payments']) : '—' }}
        </span>
        @break

    @case('remaining')
        <span class="font-bold text-red-600 tabular-nums">{{ XofFormatter::format($invoice['remaining']) }}</span>
        <span class="text-red-400 text-xs ml-0.5">XOF</span>
        @break

    @case('total_estimated_profit')
    @case('total_realized_profit')
        <span class="text-gray-500 tabular-nums">{{ XofFormatter::format($invoice[$column]) }}</span>
        @break

    @case('age')
        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $badgeClass }}">
            {{ $invoice['days_overdue'] }}j
        </span>
        @break

    @case('created_at')
        <span class="text-xs text-gray-400 tabular-nums">{{ \Carbon\Carbon::parse($invoice['created_at'])->format('d/m/Y') }}</span>
        @break

    @case('due_date')
        @if($invoice['should_be_paid_at'])
            <span class="text-xs tabular-nums {{ $invoice['is_overdue'] ? 'font-bold text-red-600' : 'text-gray-500' }}">
                {{ \Carbon\Carbon::parse($invoice['should_be_paid_at'])->format('d/m/Y') }}
            </span>
        @else
            <span class="text-gray-300">—</span>
        @endif
        @break

    @case('actions')
        <div class="flex items-center justify-center gap-1">
            <x-icon-button icon="mdi-eye" title="Voir les articles"
                           wire:click="$dispatch('open-invoice-items', { salesInvoiceId: {{ $invoice['id'] }} })" />
            <x-icon-button icon="mdi-cash" title="Voir les paiements"
                           wire:click="$dispatch('open-invoice-payments', { salesInvoiceId: {{ $invoice['id'] }} })" />
            <x-icon-button icon="mdi-file-pdf-box" title="Télécharger PDF" color="text-blue-600"
                           :href="route('sales-invoices.pdf', $invoice['id'])" target="_blank" />
            <x-icon-button icon="mdi-close-circle-outline" title="Radier (créance perdue)" color="text-yellow-600"
                           wire:click="askToWriteOffInvoice({{ $invoice['id'] }})" />
            <x-icon-button icon="mdi-delete" title="Supprimer" color="text-red-600"
                           wire:click="askToDeleteInvoice({{ $invoice['id'] }})" />
        </div>
        @break
@endswitch
