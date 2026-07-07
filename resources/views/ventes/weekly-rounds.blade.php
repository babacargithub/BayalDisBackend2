@extends('layouts.app')

@section('title', 'Tournées de la semaine')

@section('header')
    Tournées de la semaine
@endsection

@section('content')

@php
    $statusLabel = fn (string $status): string => match ($status) {
        'done'        => 'Terminée',
        'in_progress' => 'En cours',
        'upcoming'    => 'À venir',
        default       => $status,
    };

    $statusClasses = fn (string $status): string => match ($status) {
        'done'        => 'bg-emerald-100 text-emerald-800',
        'in_progress' => 'bg-yellow-100 text-yellow-800',
        'upcoming'    => 'bg-blue-100 text-blue-800',
        default       => 'bg-gray-100 text-gray-700',
    };
@endphp

<div class="space-y-8">

    {{-- ── Page sub-header ────────────────────────────────── --}}
    <div class="bg-blue-800 rounded-xl shadow-sm px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Tournées de la semaine</h2>
                <p class="text-blue-200 text-sm mt-0.5">{{ $weekStartLabel }} – {{ $weekEndLabel }}</p>
            </div>
            <div class="inline-flex items-center gap-1.5 bg-blue-700 rounded-lg px-3 py-1.5 self-start sm:self-auto">
                <i class="mdi mdi-map-marker-path text-blue-300"></i>
                <span class="text-white text-sm font-medium">
                    {{ $weekSummary['total_rounds'] }} tournée{{ $weekSummary['total_rounds'] !== 1 ? 's' : '' }}
                </span>
            </div>
        </div>
    </div>

    {{-- ── Section heading ──────────────────────────────────── --}}
    <div class="flex items-center gap-3">
        <span class="block h-7 w-1 rounded-full bg-red-600"></span>
        <h2 class="text-xl font-bold text-gray-900">Bilan de la semaine</h2>
    </div>

    {{-- ══════════════════════════════════════════════════════
         SUMMARY CARDS
         ══════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-blue-800 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Tournées planifiées</p>
            <p class="text-3xl font-bold text-blue-800 tabular-nums">{{ $weekSummary['total_rounds'] }}</p>
            <p class="text-xs text-gray-400 mt-2">Cette semaine</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 {{ $weekSummary['avg_cei_top_class'] }} p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">CEI moyen</p>
            <p class="text-3xl font-bold {{ $weekSummary['avg_cei_text_class'] }} tabular-nums">
                {{ number_format($weekSummary['average_cei_rate'], 1) }}%
            </p>
            <div class="mt-3 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full {{ $weekSummary['avg_cei_bar_class'] }}"
                     style="width: {{ $weekSummary['avg_cei_bar_width'] }}%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-2">Taux d'encaissement moyen</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 {{ $weekSummary['avg_strike_top_class'] }} p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Taux de frappe moyen</p>
            <p class="text-3xl font-bold {{ $weekSummary['avg_strike_text_class'] }} tabular-nums">
                {{ number_format($weekSummary['average_strike_rate'], 1) }}%
            </p>
            <div class="mt-3 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full {{ $weekSummary['avg_strike_bar_class'] }}"
                     style="width: {{ $weekSummary['avg_strike_bar_width'] }}%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-2">Clients ayant acheté ou payé</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-emerald-600 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Créances collectées</p>
            <p class="text-2xl font-bold text-emerald-600 tabular-nums">
                {{ number_format($weekSummary['total_debt_collected'], 0, ',', ' ') }}
                <span class="text-sm font-normal text-emerald-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">
                sur {{ number_format($weekSummary['total_debt_to_collect'], 0, ',', ' ') }} XOF à encaisser
            </p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-blue-500 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Nouvelles ventes</p>
            <p class="text-2xl font-bold text-blue-600 tabular-nums">
                {{ number_format($weekSummary['total_new_invoices'], 0, ',', ' ') }}
                <span class="text-sm font-normal text-blue-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">Factures créées cette semaine</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-gray-400 p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Total encaissements</p>
            <p class="text-2xl font-bold text-gray-700 tabular-nums">
                {{ number_format($weekSummary['total_payments'], 0, ',', ' ') }}
                <span class="text-sm font-normal text-gray-400">XOF</span>
            </p>
            <p class="text-xs text-gray-400 mt-2">Tous paiements confondus</p>
        </div>

    </div>

    {{-- ── Section heading ──────────────────────────────────── --}}
    <div class="flex items-center gap-3">
        <span class="block h-7 w-1 rounded-full bg-red-600"></span>
        <h2 class="text-xl font-bold text-gray-900">Détail des tournées</h2>
    </div>

    {{-- ══════════════════════════════════════════════════════
         ROUNDS TABLE
         ══════════════════════════════════════════════════════ --}}
    @if($weekSummary['total_rounds'] === 0)
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-16 text-center">
        <i class="mdi mdi-calendar-blank-outline text-5xl text-gray-300 mb-4 block"></i>
        <p class="text-gray-500 font-medium">Aucune tournée planifiée cette semaine</p>
    </div>
    @else
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Beat</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Commercial</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Véhicule</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Clients</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Frappe</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Dette à enc.</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Collecté</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">CEI</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Nv. ventes</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Paiements</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Statut</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($rounds as $round)
                    <tr class="hover:bg-blue-50 transition-colors group">

                        <td class="px-4 py-4 whitespace-nowrap">
                            <p class="text-sm font-semibold text-gray-900">{{ $round['date_display'] }}</p>
                            <p class="text-xs text-gray-400">{{ $round['date_year'] }}</p>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap">
                            <p class="text-sm font-medium text-gray-900">{{ $round['beat_name'] ?? '—' }}</p>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap">
                            @if($round['commercial'])
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                                    <span class="text-xs font-bold text-blue-700">
                                        {{ strtoupper(substr($round['commercial']['name'], 0, 1)) }}
                                    </span>
                                </div>
                                <span class="text-sm text-gray-800">{{ $round['commercial']['name'] }}</span>
                            </div>
                            @else
                            <span class="text-gray-400 text-sm">—</span>
                            @endif
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap">
                            @if($round['vehicle'])
                            <p class="text-sm text-gray-800">{{ $round['vehicle']['name'] }}</p>
                            @if($round['vehicle']['plate_number'])
                            <p class="text-xs text-gray-400">{{ $round['vehicle']['plate_number'] }}</p>
                            @endif
                            @else
                            <span class="text-gray-400 text-sm">—</span>
                            @endif
                        </td>

                        <td class="px-4 py-4 text-center whitespace-nowrap">
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $round['completed'] }} / {{ $round['total_customers'] }}
                            </p>
                            @if($round['no_sale'] > 0)
                            <p class="text-xs text-gray-400">{{ $round['no_sale'] }} sans achat</p>
                            @endif
                        </td>

                        <td class="px-4 py-4 text-center whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $round['strike_badge_class'] }}">
                                {{ number_format($round['strike_rate'], 1) }}%
                            </span>
                        </td>

                        <td class="px-4 py-4 text-right whitespace-nowrap">
                            <span class="text-sm text-gray-700 tabular-nums">
                                {{ number_format($round['total_debt_to_collect'], 0, ',', ' ') }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-right whitespace-nowrap">
                            <span class="text-sm font-semibold text-emerald-600 tabular-nums">
                                {{ number_format($round['total_debt_collected'], 0, ',', ' ') }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-center whitespace-nowrap">
                            <div class="inline-flex flex-col items-center gap-1">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $round['cei_badge_class'] }}">
                                    {{ number_format($round['cei_rate'], 1) }}%
                                </span>
                                <div class="w-16 bg-gray-200 rounded-full h-1 overflow-hidden">
                                    <div class="h-1 rounded-full {{ $round['cei_bar_class'] }}"
                                         style="width: {{ $round['cei_bar_width'] }}%"></div>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-4 text-right whitespace-nowrap">
                            <span class="text-sm text-blue-600 tabular-nums">
                                {{ number_format($round['total_new_invoices'], 0, ',', ' ') }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-right whitespace-nowrap">
                            <span class="text-sm text-gray-700 tabular-nums">
                                {{ number_format($round['total_payments'], 0, ',', ' ') }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-center whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusClasses($round['status']) }}">
                                {{ $statusLabel($round['status']) }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-center whitespace-nowrap">
                            <a href="{{ route('beats.rounds.performance', [$round['beat_id'], $round['id']]) }}"
                               class="inline-flex items-center gap-1 text-blue-700 hover:text-blue-900 text-xs font-medium
                                      opacity-0 group-hover:opacity-100 transition-opacity"
                               title="Voir la performance">
                                <i class="mdi mdi-chart-bar"></i>
                                Performance
                            </a>
                        </td>

                    </tr>
                    @endforeach
                </tbody>

                <tfoot class="bg-blue-800">
                    <tr>
                        <td colspan="6" class="px-4 py-3 text-xs font-bold text-blue-200 uppercase tracking-wider">
                            Totaux de la semaine
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-white tabular-nums">
                            {{ number_format($weekSummary['total_debt_to_collect'], 0, ',', ' ') }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-emerald-300 tabular-nums">
                            {{ number_format($weekSummary['total_debt_collected'], 0, ',', ' ') }}
                        </td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-blue-200 tabular-nums">
                            {{ number_format($weekSummary['total_new_invoices'], 0, ',', ' ') }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-white tabular-nums">
                            {{ number_format($weekSummary['total_payments'], 0, ',', ' ') }}
                        </td>
                        <td colspan="2" class="px-4 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

</div>
@endsection
