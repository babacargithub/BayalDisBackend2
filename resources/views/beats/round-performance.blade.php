@extends('layouts.app')

@section('title', 'Performance — ' . $beat->name)

@section('header')
    Performance — {{ $beat->name }}
@endsection

@section('content')

@php
    use Carbon\Carbon;
    $roundLabel = ucfirst(Carbon::parse($round->planned_at)->locale('fr')->isoFormat('dddd D MMMM YYYY'));

    $bucketPalette = [
        ['bg' => 'bg-emerald-50',  'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-800', 'header' => 'bg-emerald-700'],
        ['bg' => 'bg-yellow-50',   'text' => 'text-yellow-700',  'badge' => 'bg-yellow-100 text-yellow-800',   'header' => 'bg-yellow-600'],
        ['bg' => 'bg-orange-50',   'text' => 'text-orange-700',  'badge' => 'bg-orange-100 text-orange-800',   'header' => 'bg-orange-600'],
        ['bg' => 'bg-orange-100',  'text' => 'text-orange-800',  'badge' => 'bg-orange-200 text-orange-900',   'header' => 'bg-orange-700'],
        ['bg' => 'bg-red-50',      'text' => 'text-red-700',     'badge' => 'bg-red-100 text-red-800',         'header' => 'bg-red-700'],
    ];

    $strikeClass = $performance->strikeRate >= 75
        ? ['text' => 'text-emerald-600', 'bar' => 'bg-emerald-500', 'top' => 'border-t-emerald-600']
        : ($performance->strikeRate >= 50
            ? ['text' => 'text-yellow-600', 'bar' => 'bg-yellow-500', 'top' => 'border-t-yellow-500']
            : ['text' => 'text-red-600',    'bar' => 'bg-red-500',    'top' => 'border-t-red-600']);

    $ceiClass = $performance->ceiRate >= 75
        ? ['text' => 'text-emerald-600', 'bar' => 'bg-emerald-500', 'top' => 'border-t-emerald-600']
        : ($performance->ceiRate >= 50
            ? ['text' => 'text-yellow-600', 'bar' => 'bg-yellow-500', 'top' => 'border-t-yellow-500']
            : ['text' => 'text-red-600',    'bar' => 'bg-red-500',    'top' => 'border-t-red-600']);
@endphp

<div class="space-y-8">

    {{-- ── Page sub-header ────────────────────────────────── --}}
    <div class="bg-blue-800 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <a href="{{ route('beats.show', $beat) }}"
                           class="inline-flex items-center gap-1 text-blue-300 hover:text-white text-xs transition-colors">
                            <i class="mdi mdi-chevron-left"></i>
                            Retour au beat
                        </a>
                    </div>
                    <h2 class="text-xl font-bold text-white leading-tight">{{ $beat->name }}</h2>
                    <p class="text-blue-200 text-sm mt-0.5">{{ $roundLabel }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($round->commercial)
                    <div class="inline-flex items-center gap-1.5 bg-blue-700 rounded-lg px-3 py-1.5">
                        <i class="mdi mdi-account text-blue-300"></i>
                        <span class="text-white text-sm font-medium">{{ $round->commercial->name }}</span>
                    </div>
                    @endif
                    @if($round->vehicle)
                    <div class="inline-flex items-center gap-1.5 bg-blue-700 rounded-lg px-3 py-1.5">
                        <i class="mdi mdi-truck text-blue-300"></i>
                        <span class="text-white text-sm font-medium">{{ $round->vehicle->name }}</span>
                        @if($round->vehicle->plate_number)
                        <span class="text-blue-300 text-xs">· {{ $round->vehicle->plate_number }}</span>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── Section heading ──────────────────────────────────── --}}
    <div class="flex items-center gap-3">
        <span class="block h-7 w-1 rounded-full bg-red-600"></span>
        <h2 class="text-xl font-bold text-gray-900">Analyse de performance de tournée</h2>
    </div>

    {{-- ══════════════════════════════════════════════════════
         KPI CARDS — 6 metrics
         ══════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">

        {{-- Créances à encaisser --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-blue-800 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Créances à encaisser</p>
            <p class="text-2xl font-bold text-blue-800 tabular-nums">
                {{ number_format($performance->totalDebtToCollect, 0, ',', ' ') }}
                <span class="text-sm font-normal text-blue-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">Factures antérieures impayées</p>
        </div>

        {{-- Créances encaissées --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-emerald-600 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Créances encaissées</p>
            <p class="text-2xl font-bold text-emerald-600 tabular-nums">
                {{ number_format($performance->totalDebtCollected, 0, ',', ' ') }}
                <span class="text-sm font-normal text-emerald-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">Dettes récupérées ce jour</p>
        </div>

        {{-- Nouvelles ventes --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-blue-500 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Nouvelles ventes</p>
            <p class="text-2xl font-bold text-blue-600 tabular-nums">
                {{ number_format($performance->totalNewInvoices, 0, ',', ' ') }}
                <span class="text-sm font-normal text-blue-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">Factures créées ce jour</p>
        </div>

        {{-- Total encaissements --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-gray-400 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Total encaissements</p>
            <p class="text-2xl font-bold text-gray-700 tabular-nums">
                {{ number_format($performance->totalPayments, 0, ',', ' ') }}
                <span class="text-sm font-normal text-gray-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">Tous paiements reçus</p>
        </div>

        {{-- Taux de réussite --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 {{ $strikeClass['top'] }} p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Taux de réussite</p>
            <p class="text-2xl font-bold {{ $strikeClass['text'] }} tabular-nums">
                {{ number_format($performance->strikeRate, 1) }}%
            </p>
            <div class="mt-3 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full {{ $strikeClass['bar'] }} transition-all"
                     style="width: {{ min($performance->strikeRate, 100) }}%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-2">Clients ayant acheté ou payé</p>
        </div>

        {{-- CEI --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 {{ $ceiClass['top'] }} p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Taux d'encaissement (CEI)</p>
            <p class="text-2xl font-bold {{ $ceiClass['text'] }} tabular-nums">
                {{ number_format($performance->ceiRate, 1) }}%
            </p>
            <div class="mt-3 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full {{ $ceiClass['bar'] }} transition-all"
                     style="width: {{ min($performance->ceiRate, 100) }}%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-2">Créances récupérées / à encaisser</p>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════
         COLLECTION BREAKDOWN — visual split bar
         ══════════════════════════════════════════════════════ --}}
    @if($performance->totalPayments > 0)
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center gap-3 mb-5">
            <span class="block h-5 w-1 rounded-full bg-red-600"></span>
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide">Composition des encaissements</h3>
        </div>

        @php
            $debtShare    = $performance->totalPayments > 0 ? round($performance->totalDebtCollected / $performance->totalPayments * 100, 1) : 0;
            $instantShare = 100 - $debtShare;
        @endphp

        <div class="flex rounded-full overflow-hidden h-4 mb-4">
            @if($debtShare > 0)
            <div class="bg-blue-800 h-full" style="width: {{ $debtShare }}%"
                 title="Dettes récupérées: {{ $debtShare }}%"></div>
            @endif
            @if($instantShare > 0)
            <div class="bg-blue-300 h-full" style="width: {{ $instantShare }}%"
                 title="Paiements immédiats: {{ $instantShare }}%"></div>
            @endif
        </div>

        <div class="flex flex-wrap gap-6">
            <div class="flex items-center gap-2">
                <span class="block w-3 h-3 rounded-sm bg-blue-800"></span>
                <span class="text-sm text-gray-600">
                    Récupération de dettes
                    <span class="font-semibold text-gray-900">{{ number_format($performance->totalDebtCollected, 0, ',', ' ') }} XOF</span>
                    <span class="text-gray-400">({{ $debtShare }}%)</span>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="block w-3 h-3 rounded-sm bg-blue-300"></span>
                <span class="text-sm text-gray-600">
                    Paiements immédiats ventes du jour
                    <span class="font-semibold text-gray-900">{{ number_format($performance->totalPayments - $performance->totalDebtCollected, 0, ',', ' ') }} XOF</span>
                    <span class="text-gray-400">({{ $instantShare }}%)</span>
                </span>
            </div>
        </div>
    </div>
    @endif

    {{-- ══════════════════════════════════════════════════════
         AR AGING ANALYSIS
         ══════════════════════════════════════════════════════ --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        {{-- Section header --}}
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-3">
                <span class="block h-5 w-1 rounded-full bg-red-600"></span>
                <h3 class="text-base font-bold text-gray-900">Analyse des créances par ancienneté</h3>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-gray-500">Total :</span>
                <span class="font-bold text-blue-800">
                    {{ number_format($performance->totalDebtToCollect, 0, ',', ' ') }} XOF
                </span>
            </div>
        </div>

        {{-- Bucket summary chips --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 divide-x divide-y sm:divide-y-0 divide-gray-100 border-b border-gray-100">
            @foreach ($performance->arBuckets as $i => $bucket)
            @php $pal = $bucketPalette[$i]; @endphp
            <div class="px-4 py-5 text-center {{ $pal['bg'] }}">
                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold mb-3 {{ $pal['badge'] }}">
                    {{ $bucket->labelFr }}
                </span>
                <p class="text-2xl font-bold {{ $pal['text'] }}">{{ $bucket->invoiceCount }}</p>
                <p class="text-xs text-gray-500 mb-2">facture{{ $bucket->invoiceCount !== 1 ? 's' : '' }}</p>
                <p class="text-xs font-semibold {{ $bucket->totalAmount > 0 ? $pal['text'] : 'text-gray-400' }}">
                    {{ $bucket->totalAmount > 0 ? number_format($bucket->totalAmount, 0, ',', ' ') . ' XOF' : '—' }}
                </p>
            </div>
            @endforeach
        </div>

        {{-- Per-bucket detail tables --}}
        @php $hasAnyInvoice = collect($performance->arBuckets)->sum('invoiceCount') > 0; @endphp

        @if(!$hasAnyInvoice)
        <div class="py-16 text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 bg-emerald-50 rounded-full mb-4">
                <i class="mdi mdi-check text-2xl text-emerald-600"></i>
            </div>
            <p class="text-gray-500 font-medium">Aucune créance antérieure pour cette tournée</p>
        </div>
        @else

        @foreach ($performance->arBuckets as $i => $bucket)
        @if($bucket->invoiceCount > 0)
        @php $pal = $bucketPalette[$i]; @endphp
        <div class="border-t border-gray-100">

            {{-- Bucket table header --}}
            <div class="px-6 py-3 {{ $pal['header'] }} flex items-center justify-between">
                <span class="text-white font-semibold text-sm">{{ $bucket->labelFr }}</span>
                <span class="text-white/80 text-xs">
                    {{ $bucket->invoiceCount }} facture{{ $bucket->invoiceCount !== 1 ? 's' : '' }}
                    &nbsp;·&nbsp;
                    {{ number_format($bucket->totalAmount, 0, ',', ' ') }} XOF
                </span>
            </div>

            {{-- Invoice table --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide border-b border-gray-100">
                            <th class="px-6 py-2.5 text-left font-semibold">Client</th>
                            <th class="px-6 py-2.5 text-right font-semibold">Montant facture</th>
                            <th class="px-6 py-2.5 text-right font-semibold">Déjà payé</th>
                            <th class="px-6 py-2.5 text-right font-semibold">Solde dû</th>
                            <th class="px-6 py-2.5 text-center font-semibold">Âge</th>
                            <th class="px-6 py-2.5 text-center font-semibold">Date facture</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach ($bucket->invoices as $invoice)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-3.5 font-medium text-gray-900">{{ $invoice['customer_name'] }}</td>
                            <td class="px-6 py-3.5 text-right text-gray-500 tabular-nums">
                                {{ number_format($invoice['total_amount'], 0, ',', ' ') }}
                            </td>
                            <td class="px-6 py-3.5 text-right text-gray-400 tabular-nums">
                                {{ $invoice['total_payments'] > 0 ? number_format($invoice['total_payments'], 0, ',', ' ') : '—' }}
                            </td>
                            <td class="px-6 py-3.5 text-right tabular-nums">
                                <span class="font-bold text-red-600">{{ number_format($invoice['remaining'], 0, ',', ' ') }}</span>
                                <span class="text-red-400 text-xs ml-0.5">XOF</span>
                            </td>
                            <td class="px-6 py-3.5 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $pal['badge'] }}">
                                    {{ $invoice['days_overdue'] }}j
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-center text-xs text-gray-400 tabular-nums">
                                {{ $invoice['created_at'] }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50 border-t border-gray-200">
                            <td class="px-6 py-2.5 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                Sous-total
                            </td>
                            <td class="px-6 py-2.5 text-right text-xs font-semibold text-gray-600 tabular-nums">
                                {{ number_format(array_sum(array_column($bucket->invoices, 'total_amount')), 0, ',', ' ') }}
                            </td>
                            <td class="px-6 py-2.5 text-right text-xs font-semibold text-gray-400 tabular-nums">
                                {{ number_format(array_sum(array_column($bucket->invoices, 'total_payments')), 0, ',', ' ') }}
                            </td>
                            <td class="px-6 py-2.5 text-right text-xs font-bold text-red-600 tabular-nums">
                                {{ number_format($bucket->totalAmount, 0, ',', ' ') }} XOF
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        @endif
        @endforeach

        @endif {{-- hasAnyInvoice --}}

    </div>

    {{-- Footer --}}
    <p class="text-center text-xs text-gray-400 pb-6">
        Bayal Distribution &nbsp;·&nbsp; Rapport généré le {{ ucfirst(now()->locale('fr')->isoFormat('D MMMM YYYY à HH:mm')) }}
    </p>

</div>
@endsection
