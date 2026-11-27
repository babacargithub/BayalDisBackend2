@props([
    'buckets',
    'totalAmount',
    'columns',
    'emptyMessage',
    'title' => 'Analyse des créances par ancienneté',
])

@php
    use App\Support\XofFormatter;

    // Keyed by the bucket's lower bound in days.
    $bucketPalette = [
        0  => ['bg' => 'bg-blue-50',    'text' => 'text-blue-700',    'badge' => 'bg-blue-100 text-blue-800',       'header' => 'bg-blue-700'],
        1  => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-800', 'header' => 'bg-emerald-700'],
        8  => ['bg' => 'bg-yellow-50',  'text' => 'text-yellow-700',  'badge' => 'bg-yellow-100 text-yellow-800',   'header' => 'bg-yellow-600'],
        15 => ['bg' => 'bg-orange-50',  'text' => 'text-orange-700',  'badge' => 'bg-orange-100 text-orange-800',   'header' => 'bg-orange-600'],
        22 => ['bg' => 'bg-orange-100', 'text' => 'text-orange-800',  'badge' => 'bg-orange-200 text-orange-900',   'header' => 'bg-orange-700'],
        31 => ['bg' => 'bg-red-50',     'text' => 'text-red-700',     'badge' => 'bg-red-100 text-red-800',         'header' => 'bg-red-700'],
    ];

    $columnDefinitions = [
        'select'                 => ['label' => '',                'align' => 'text-center'],
        'created_at'             => ['label' => 'Date facture',    'align' => 'text-center'],
        'due_date'               => ['label' => 'Échéance',        'align' => 'text-center'],
        'customer'               => ['label' => 'Client',          'align' => 'text-left'],
        'customer_phone_number'  => ['label' => 'Téléphone',       'align' => 'text-left'],
        'total_amount'           => ['label' => 'Montant facture', 'align' => 'text-right'],
        'total_payments'         => ['label' => 'Déjà payé',       'align' => 'text-right'],
        'remaining'              => ['label' => 'Solde dû',        'align' => 'text-right'],
        'total_estimated_profit' => ['label' => 'Bénéfice',        'align' => 'text-right'],
        'total_realized_profit'  => ['label' => 'Bénéfice reçu',   'align' => 'text-right'],
        'age'                    => ['label' => 'Âge',             'align' => 'text-center'],
        'actions'                => ['label' => 'Actions',         'align' => 'text-center'],
    ];

    $gridColumnsClass = count($buckets) > 5 ? 'sm:grid-cols-6' : 'sm:grid-cols-5';
    $hasAnyInvoice = collect($buckets)->sum('invoiceCount') > 0;
@endphp

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

    {{-- Section header --}}
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-3">
            <span class="block h-5 w-1 rounded-full bg-red-600"></span>
            <h3 class="text-base font-bold text-gray-900">{{ $title }}</h3>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="text-gray-500">Total :</span>
            <span class="font-bold text-blue-800">{{ XofFormatter::format($totalAmount) }} XOF</span>
        </div>
    </div>

    {{-- Bucket summary chips --}}
    <div class="grid grid-cols-2 {{ $gridColumnsClass }} divide-x divide-y sm:divide-y-0 divide-gray-100 border-b border-gray-100">
        @foreach ($buckets as $bucket)
            @php $palette = $bucketPalette[$bucket->fromDays]; @endphp
            <div class="px-4 py-5 text-center {{ $palette['bg'] }}">
                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold mb-3 {{ $palette['badge'] }}">
                    {{ $bucket->labelFr }}
                </span>
                <p class="text-2xl font-bold {{ $palette['text'] }}">{{ $bucket->invoiceCount }}</p>
                <p class="text-xs text-gray-500 mb-2">facture{{ $bucket->invoiceCount !== 1 ? 's' : '' }}</p>
                <p class="text-xs font-semibold {{ $bucket->totalAmount > 0 ? $palette['text'] : 'text-gray-400' }}">
                    {{ $bucket->totalAmount > 0 ? XofFormatter::format($bucket->totalAmount).' XOF' : '—' }}
                </p>
            </div>
        @endforeach
    </div>

    {{-- Per-bucket detail tables --}}
    @if(! $hasAnyInvoice)
        <div class="py-16 text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 bg-emerald-50 rounded-full mb-4">
                <i class="mdi mdi-check text-2xl text-emerald-600"></i>
            </div>
            <p class="text-gray-500 font-medium">{{ $emptyMessage }}</p>
        </div>
    @else
        @foreach ($buckets as $bucket)
            @continue($bucket->invoiceCount === 0)
            @php $palette = $bucketPalette[$bucket->fromDays]; @endphp
            <div class="border-t border-gray-100" wire:key="bucket-{{ $bucket->fromDays }}">

                <div class="px-6 py-3 {{ $palette['header'] }} flex items-center justify-between">
                    <span class="text-white font-semibold text-sm">{{ $bucket->labelFr }}</span>
                    <span class="text-white/80 text-xs">
                        {{ $bucket->invoiceCount }} facture{{ $bucket->invoiceCount !== 1 ? 's' : '' }}
                        &nbsp;·&nbsp;
                        {{ XofFormatter::format($bucket->totalAmount) }} XOF
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide border-b border-gray-100">
                                @foreach ($columns as $column)
                                    <th class="px-4 py-2.5 font-semibold {{ $columnDefinitions[$column]['align'] }}">
                                        {{ $columnDefinitions[$column]['label'] }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($bucket->invoices as $invoice)
                                <tr class="hover:bg-gray-50 transition-colors" wire:key="invoice-{{ $invoice['id'] }}">
                                    @foreach ($columns as $column)
                                        <td class="px-4 py-3.5 {{ $columnDefinitions[$column]['align'] }}">
                                            <x-ar-aging.cell :column="$column" :invoice="$invoice" :badge-class="$palette['badge']" />
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 border-t border-gray-200">
                                @foreach ($columns as $column)
                                    <td class="px-4 py-2.5 text-xs font-semibold tabular-nums {{ $columnDefinitions[$column]['align'] }}">
                                        @if($loop->index === ($columns[0] === 'select' ? 1 : 0))
                                            <span class="text-gray-500 uppercase tracking-wide">Sous-total</span>
                                        @elseif($column === 'total_amount')
                                            <span class="text-gray-600">{{ XofFormatter::format($bucket->totalInvoicedAmount) }}</span>
                                        @elseif($column === 'total_payments')
                                            <span class="text-gray-400">{{ XofFormatter::format($bucket->totalPaidAmount) }}</span>
                                        @elseif($column === 'remaining')
                                            <span class="font-bold text-red-600">{{ XofFormatter::format($bucket->totalAmount) }} XOF</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endforeach
    @endif

</div>
