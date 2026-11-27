@extends('layouts.app')

@section('title', 'Performance — ' . $beat->name)

@section('header')
    Performance — {{ $beat->name }}
@endsection

@section('content')

@php
    use Carbon\Carbon;
    $roundLabel = ucfirst(Carbon::parse($round->planned_at)->locale('fr')->isoFormat('dddd D MMMM YYYY'));

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

    <x-page-banner :title="$beat->name" :subtitle="$roundLabel">
        <x-slot:above>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('beats.show', $beat) }}"
                   class="inline-flex items-center gap-1 text-blue-300 hover:text-white text-xs transition-colors">
                    <i class="mdi mdi-chevron-left"></i>
                    Retour au beat
                </a>
            </div>
        </x-slot:above>
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
    </x-page-banner>

    <x-section-heading title="Analyse de performance de tournée" />

    {{-- ══════════════════════════════════════════════════════
         KPI CARDS — 6 metrics
         ══════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">

        <x-kpi-card label="Créances à encaisser" :value="\App\Support\XofFormatter::format($performance->totalDebtToCollect)" unit="XOF"
                    hint="Factures antérieures impayées" border-class="border-t-blue-800" value-class="text-blue-800" unit-class="text-blue-400" />

        <x-kpi-card label="Créances encaissées" :value="\App\Support\XofFormatter::format($performance->totalDebtCollected)" unit="XOF"
                    hint="Dettes récupérées ce jour" border-class="border-t-emerald-600" value-class="text-emerald-600" unit-class="text-emerald-400" />

        <x-kpi-card label="Nouvelles ventes" :value="\App\Support\XofFormatter::format($performance->totalNewInvoices)" unit="XOF"
                    hint="Factures créées ce jour" border-class="border-t-blue-500" value-class="text-blue-600" unit-class="text-blue-400" />

        <x-kpi-card label="Total encaissements" :value="\App\Support\XofFormatter::format($performance->totalPayments)" unit="XOF"
                    hint="Tous paiements reçus" border-class="border-t-gray-400" value-class="text-gray-700" unit-class="text-gray-400" />

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

    <x-ar-aging.report
        :buckets="$performance->arBuckets"
        :total-amount="$performance->totalDebtToCollect"
        :columns="['customer', 'total_amount', 'total_payments', 'remaining', 'age', 'created_at']"
        empty-message="Aucune créance antérieure pour cette tournée"
    />

    {{-- Footer --}}
    <p class="text-center text-xs text-gray-400 pb-6">
        Bayal Distribution &nbsp;·&nbsp; Rapport généré le {{ ucfirst(now()->locale('fr')->isoFormat('D MMMM YYYY à HH:mm')) }}
    </p>

</div>
@endsection
