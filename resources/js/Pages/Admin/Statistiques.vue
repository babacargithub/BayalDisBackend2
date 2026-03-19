<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatChart from '@/Components/Statistics/StatChart.vue';

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps({
    /** 'monthly' | 'yearly' — set by the controller based on the view_type query param. */
    viewType: { type: String, required: true },
    year: { type: Number, required: true },
    month: { type: Number, required: true },
    /** Present when viewType === 'monthly', null otherwise. */
    monthlyActivity: { type: Object, default: null },
    /** Present when viewType === 'yearly', null otherwise. */
    yearlyActivity: { type: Object, default: null },
});

// ─── Filter state ─────────────────────────────────────────────────────────────

const selectedPeriodType = ref(props.viewType); // 'monthly' | 'yearly'
const selectedYear = ref(props.year);
const selectedMonth = ref(props.month);

const yearOptions = computed(() => {
    const current = new Date().getFullYear();
    return Array.from({ length: 6 }, (_, i) => ({
        title: String(current - 2 + i),
        value: current - 2 + i,
    }));
});

const monthOptions = [
    { title: 'Janvier', value: 1 },
    { title: 'Février', value: 2 },
    { title: 'Mars', value: 3 },
    { title: 'Avril', value: 4 },
    { title: 'Mai', value: 5 },
    { title: 'Juin', value: 6 },
    { title: 'Juillet', value: 7 },
    { title: 'Août', value: 8 },
    { title: 'Septembre', value: 9 },
    { title: 'Octobre', value: 10 },
    { title: 'Novembre', value: 11 },
    { title: 'Décembre', value: 12 },
];

const monthName = (monthNumber) => monthOptions.find((m) => m.value === monthNumber)?.title ?? '';

const currentPeriodLabel = computed(() =>
    props.viewType === 'yearly'
        ? String(props.year)
        : `${monthName(props.month)} ${props.year}`,
);

function applyFilters() {
    router.get(
        route('admin.statistiques'),
        {
            view_type: selectedPeriodType.value,
            year: selectedYear.value,
            month: selectedMonth.value,
        },
        { preserveScroll: false },
    );
}

function onPeriodTypeChange(newType) {
    selectedPeriodType.value = newType;
    applyFilters();
}

// ─── View mode toggle (list / charts / both) ─────────────────────────────────

const viewMode = ref('list');

const viewModeOptions = [
    { label: 'Liste', value: 'list', icon: 'mdi-table' },
    { label: 'Graphiques', value: 'charts', icon: 'mdi-chart-line' },
    { label: 'Les deux', value: 'both', icon: 'mdi-view-split-vertical' },
];

// ─── Formatting helpers ───────────────────────────────────────────────────────

function formatCurrency(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' F';
}

function formatDayLabel(dateString) {
    const date = new Date(dateString + 'T00:00:00');
    return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

function formatDayNumber(dateString) {
    return dateString.split('-')[2];
}

// ─── Data unified behind a common interface ───────────────────────────────────
// Both monthly (day rows) and yearly (month rows) expose the same field names
// so the table and chart templates don't need to branch on viewType.

const isYearly = computed(() => props.viewType === 'yearly');

/**
 * The array of period rows — either DailyActivityDTOs (monthly mode)
 * or MonthlyTotalsDTOs (yearly mode). Both share the same financial fields.
 */
const periodRows = computed(() => {
    if (isYearly.value) {
        return props.yearlyActivity?.monthly_totals ?? [];
    }
    return props.monthlyActivity?.daily_activity ?? [];
});

/**
 * Human-readable row label used in the table's first column.
 * Monthly mode → "15 mars", yearly mode → "Janvier".
 */
function rowLabel(row) {
    if (isYearly.value) {
        return monthName(row.month_number);
    }
    return formatDayLabel(row.date);
}

/**
 * Short label used for chart X-axis ticks.
 * Monthly mode → day number ("15"), yearly mode → 3-letter month abbreviation.
 */
const MONTH_SHORT = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

function rowShortLabel(row) {
    if (isYearly.value) {
        return MONTH_SHORT[row.month_number - 1];
    }
    return formatDayNumber(row.date);
}

/** Aggregated period summary (monthly totals or yearly totals). */
const summary = computed(() => {
    const source = isYearly.value ? props.yearlyActivity : props.monthlyActivity;
    if (!source) {
        return null;
    }
    return {
        invoicesCount: source.total_invoices_count,
        totalSales: source.total_sales,
        totalEstimatedProfit: source.total_estimated_profit,
        totalRealizedProfit: source.total_realized_profit,
        totalDeliveryCost: source.total_delivery_cost,
        totalCommissions: source.total_commissions,
        netProfit: source.net_profit,
        // 'average_daily_sales' in monthly, 'average_monthly_sales' in yearly
        averagePeriodSales: source.average_daily_sales ?? source.average_monthly_sales ?? 0,
        averageInvoiceTotal: source.average_invoice_total,
        activePeriodCount: source.active_days_count ?? source.active_months_count ?? 0,
        totalPeriodCount: source.days_in_month ?? 12,
    };
});

const hasSales = computed(() => (summary.value?.invoicesCount ?? 0) > 0);
const isOverallDeficit = computed(() => (summary.value?.netProfit ?? 0) < 0);

// ─── KPI cards (pluggable — add new cards here) ───────────────────────────────

const kpiCards = computed(() => {
    if (!summary.value) {
        return [];
    }
    const s = summary.value;
    const periodWord = isYearly.value ? 'mois actifs' : 'jours actifs';
    return [
        {
            key: 'total_sales',
            label: "Chiffre d'affaires",
            value: formatCurrency(s.totalSales),
            icon: 'mdi-cash-register',
            color: '#6366f1',
        },
        {
            key: 'net_profit',
            label: 'Bénéfice net',
            value: formatCurrency(s.netProfit),
            icon: isOverallDeficit.value ? 'mdi-trending-down' : 'mdi-trending-up',
            color: isOverallDeficit.value ? '#ef4444' : '#10b981',
            badge: isOverallDeficit.value ? 'Déficit' : 'Excédent',
            badgeColor: isOverallDeficit.value ? 'error' : 'success',
        },
        {
            key: 'estimated_profit',
            label: 'Profit estimé',
            value: formatCurrency(s.totalEstimatedProfit),
            icon: 'mdi-chart-areaspline',
            color: '#8b5cf6',
        },
        {
            key: 'realized_profit',
            label: 'Profit réalisé',
            value: formatCurrency(s.totalRealizedProfit),
            icon: 'mdi-cash-check',
            color: '#0ea5e9',
        },
        {
            key: 'commissions',
            label: 'Commissions',
            value: formatCurrency(s.totalCommissions),
            icon: 'mdi-account-cash',
            color: '#f59e0b',
        },
        {
            key: 'delivery_cost',
            label: 'Coûts livraison',
            value: formatCurrency(s.totalDeliveryCost),
            icon: 'mdi-truck-delivery',
            color: '#f97316',
        },
        {
            key: 'invoices_count',
            label: 'Factures émises',
            value: String(s.invoicesCount),
            icon: 'mdi-file-document-multiple-outline',
            color: '#64748b',
            sub: `${s.activePeriodCount} ${periodWord} / ${s.totalPeriodCount}`,
        },
        {
            key: 'average_period_sales',
            label: isYearly.value ? 'Moy. ventes/mois actif' : 'Moy. ventes/jour actif',
            value: formatCurrency(s.averagePeriodSales),
            icon: isYearly.value ? 'mdi-calendar-month' : 'mdi-calendar-today',
            color: '#14b8a6',
        },
        {
            key: 'average_invoice_total',
            label: 'Moy. par facture',
            value: formatCurrency(s.averageInvoiceTotal),
            icon: 'mdi-receipt-text-outline',
            color: '#3b82f6',
        },
    ];
});

// ─── Chart series (pluggable — add new chart panels here) ─────────────────────

function buildChartSeries(field) {
    return periodRows.value.map((row) => ({
        label: rowShortLabel(row),
        value: row[field] ?? 0,
    }));
}

const chartPanels = computed(() => [
    {
        key: 'sales',
        title: isYearly.value ? "Chiffre d'affaires par mois" : "Chiffre d'affaires par jour",
        icon: 'mdi-chart-bar',
        data: buildChartSeries('total_sales'),
        color: '#6366f1',
        label: 'CA',
        secondaryData: buildChartSeries('total_estimated_profit'),
        secondaryLabel: 'Profit estimé',
        secondaryColor: '#8b5cf6',
        formatValue: formatCurrency,
        allowNegative: false,
    },
    {
        key: 'net_profit',
        title: isYearly.value ? 'Bénéfice net par mois' : 'Bénéfice net par jour',
        icon: 'mdi-trending-up',
        data: buildChartSeries('net_profit'),
        color: '#10b981',
        label: 'Bénéfice net',
        secondaryData: null,
        secondaryLabel: '',
        secondaryColor: '',
        formatValue: formatCurrency,
        allowNegative: true,
    },
    {
        key: 'costs',
        title: 'Coûts (commissions + livraison)',
        icon: 'mdi-cash-minus',
        data: buildChartSeries('total_commissions'),
        color: '#f59e0b',
        label: 'Commissions',
        secondaryData: buildChartSeries('total_delivery_cost'),
        secondaryLabel: 'Livraison',
        secondaryColor: '#f97316',
        formatValue: formatCurrency,
        allowNegative: false,
    },
    {
        key: 'invoices',
        title: isYearly.value ? 'Nombre de factures par mois' : 'Nombre de factures par jour',
        icon: 'mdi-file-document-multiple-outline',
        data: buildChartSeries('invoices_count'),
        color: '#0ea5e9',
        label: 'Factures',
        secondaryData: null,
        secondaryLabel: '',
        secondaryColor: '',
        formatValue: (v) => String(v),
        allowNegative: false,
    },
]);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function isZeroRow(row) {
    return row.invoices_count === 0;
}
</script>

<template>
    <Head title="Statistiques" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Statistiques — {{ currentPeriodLabel }}
            </h2>
        </template>

        <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-6">

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- FILTER SECTION                                                  -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <v-card class="filter-card" elevation="0">
                <v-card-text>
                    <div class="filter-row">
                        <!-- Period type toggle -->
                        <div class="period-type-group">
                            <button
                                class="period-type-btn"
                                :class="{ active: selectedPeriodType === 'monthly' }"
                                @click="onPeriodTypeChange('monthly')"
                            >
                                <v-icon size="15" class="mr-1">mdi-calendar-month-outline</v-icon>
                                Mensuel
                            </button>
                            <button
                                class="period-type-btn"
                                :class="{ active: selectedPeriodType === 'yearly' }"
                                @click="onPeriodTypeChange('yearly')"
                            >
                                <v-icon size="15" class="mr-1">mdi-calendar-year</v-icon>
                                Annuel
                            </button>
                        </div>

                        <v-divider vertical class="mx-3 hidden sm:block" style="height:36px" />

                        <!-- Selectors -->
                        <div class="filter-controls">
                            <!-- Month selector — only shown in monthly mode -->
                            <v-select
                                v-if="selectedPeriodType === 'monthly'"
                                v-model="selectedMonth"
                                :items="monthOptions"
                                item-title="title"
                                item-value="value"
                                label="Mois"
                                density="compact"
                                variant="outlined"
                                hide-details
                                class="filter-select"
                            />
                            <v-select
                                v-model="selectedYear"
                                :items="yearOptions"
                                item-title="title"
                                item-value="value"
                                label="Année"
                                density="compact"
                                variant="outlined"
                                hide-details
                                class="filter-select"
                            />
                            <v-btn
                                color="indigo"
                                variant="flat"
                                prepend-icon="mdi-magnify"
                                @click="applyFilters"
                            >
                                Afficher
                            </v-btn>
                        </div>
                    </div>
                </v-card-text>
            </v-card>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- KPI SUMMARY CARDS                                               -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div class="kpi-grid">
                <div
                    v-for="card in kpiCards"
                    :key="card.key"
                    class="kpi-card"
                >
                    <div class="kpi-icon-wrap" :style="{ background: card.color + '20' }">
                        <v-icon :color="card.color" size="22">{{ card.icon }}</v-icon>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-label">{{ card.label }}</div>
                        <div class="kpi-value" :style="{ color: card.color }">{{ card.value }}</div>
                        <div v-if="card.sub" class="kpi-sub">{{ card.sub }}</div>
                    </div>
                    <v-chip
                        v-if="card.badge"
                        :color="card.badgeColor"
                        size="x-small"
                        class="kpi-badge"
                        label
                    >
                        {{ card.badge }}
                    </v-chip>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- VIEW MODE TOGGLE (list / charts / both)                         -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div v-if="hasSales" class="view-toggle-row">
                <div class="view-toggle-group">
                    <button
                        v-for="option in viewModeOptions"
                        :key="option.value"
                        class="view-toggle-btn"
                        :class="{ active: viewMode === option.value }"
                        @click="viewMode = option.value"
                    >
                        <v-icon size="16" class="mr-1">{{ option.icon }}</v-icon>
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- EMPTY STATE                                                     -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <v-card v-if="!hasSales" elevation="0" class="empty-card">
                <v-card-text class="text-center py-16">
                    <v-icon size="64" color="grey-lighten-2">mdi-chart-line-variant</v-icon>
                    <p class="text-h6 text-grey mt-4">Aucune activité pour {{ currentPeriodLabel }}</p>
                    <p class="text-body-2 text-grey-lighten-1">
                        Sélectionnez une autre période pour voir les données.
                    </p>
                </v-card-text>
            </v-card>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- CHART VIEW                                                      -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div v-if="hasSales && (viewMode === 'charts' || viewMode === 'both')" class="charts-grid">
                <v-card
                    v-for="panel in chartPanels"
                    :key="panel.key"
                    elevation="0"
                    class="chart-card"
                >
                    <v-card-title class="chart-card-title">
                        <v-icon class="mr-2" size="18">{{ panel.icon }}</v-icon>
                        {{ panel.title }}
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="pb-4">
                        <StatChart
                            :data="panel.data"
                            :label="panel.label"
                            :color="panel.color"
                            :secondary-data="panel.secondaryData"
                            :secondary-label="panel.secondaryLabel"
                            :secondary-color="panel.secondaryColor"
                            :format-value="panel.formatValue"
                            :allow-negative="panel.allowNegative"
                        />
                    </v-card-text>
                </v-card>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- LIST / TABLE VIEW                                               -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <v-card
                v-if="hasSales && (viewMode === 'list' || viewMode === 'both')"
                elevation="0"
                class="table-card"
            >
                <v-card-title class="table-card-title">
                    <v-icon class="mr-2" size="18">mdi-table</v-icon>
                    <span v-if="isYearly">Activité mensuelle — {{ year }}</span>
                    <span v-else>Activité journalière — {{ monthName(month) }} {{ year }}</span>
                </v-card-title>
                <v-divider />

                <div class="table-scroll-wrapper">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th class="text-left">{{ isYearly ? 'Mois' : 'Date' }}</th>
                                <th class="text-center">Factures</th>
                                <th class="text-right">CA</th>
                                <th class="text-right">Profit estimé</th>
                                <th class="text-right">Profit réalisé</th>
                                <th class="text-right">Livraison</th>
                                <th class="text-right">Commissions</th>
                                <th class="text-right">Bénéfice net</th>
                                <th class="text-right">Moy/Facture</th>
                                <th class="text-center">Statut</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="row in periodRows"
                                :key="isYearly ? row.month_number : row.date"
                                :class="{
                                    'row-zero': isZeroRow(row),
                                    'row-deficit': !isZeroRow(row) && row.is_deficit,
                                    'row-active': !isZeroRow(row) && !row.is_deficit,
                                }"
                            >
                                <td class="cell-date">{{ rowLabel(row) }}</td>

                                <td class="text-center">
                                    <span v-if="row.invoices_count > 0" class="invoice-badge">
                                        {{ row.invoices_count }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right font-medium">
                                    <span v-if="row.total_sales > 0">{{ formatCurrency(row.total_sales) }}</span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right text-purple-600">
                                    <span v-if="row.total_estimated_profit > 0">
                                        {{ formatCurrency(row.total_estimated_profit) }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right text-sky-600">
                                    <span v-if="row.total_realized_profit > 0">
                                        {{ formatCurrency(row.total_realized_profit) }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right text-orange-500">
                                    <span v-if="row.total_delivery_cost > 0">
                                        {{ formatCurrency(row.total_delivery_cost) }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right text-amber-600">
                                    <span v-if="row.total_commissions > 0">
                                        {{ formatCurrency(row.total_commissions) }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right">
                                    <span
                                        v-if="!isZeroRow(row)"
                                        class="net-profit-cell"
                                        :class="row.is_deficit ? 'deficit' : 'excedent'"
                                    >
                                        {{ formatCurrency(row.net_profit) }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-right text-slate-500">
                                    <span v-if="row.invoice_average_total > 0">
                                        {{ formatCurrency(row.invoice_average_total) }}
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>

                                <td class="text-center">
                                    <span
                                        v-if="!isZeroRow(row)"
                                        class="status-badge"
                                        :class="row.is_deficit ? 'badge-deficit' : 'badge-excedent'"
                                    >
                                        {{ row.is_deficit ? 'Déficit' : 'Excédent' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>

                        <!-- Summary footer -->
                        <tfoot v-if="summary">
                            <tr class="summary-row">
                                <td class="font-bold">Total</td>
                                <td class="text-center font-bold">{{ summary.invoicesCount }}</td>
                                <td class="text-right font-bold text-indigo-700">
                                    {{ formatCurrency(summary.totalSales) }}
                                </td>
                                <td class="text-right font-bold text-purple-700">
                                    {{ formatCurrency(summary.totalEstimatedProfit) }}
                                </td>
                                <td class="text-right font-bold text-sky-700">
                                    {{ formatCurrency(summary.totalRealizedProfit) }}
                                </td>
                                <td class="text-right font-bold text-orange-600">
                                    {{ formatCurrency(summary.totalDeliveryCost) }}
                                </td>
                                <td class="text-right font-bold text-amber-700">
                                    {{ formatCurrency(summary.totalCommissions) }}
                                </td>
                                <td class="text-right">
                                    <span
                                        class="net-profit-cell"
                                        :class="isOverallDeficit ? 'deficit' : 'excedent'"
                                    >
                                        {{ formatCurrency(summary.netProfit) }}
                                    </span>
                                </td>
                                <td class="text-right font-bold text-slate-600">
                                    {{ formatCurrency(summary.averageInvoiceTotal) }}
                                </td>
                                <td class="text-center">
                                    <span
                                        class="status-badge"
                                        :class="isOverallDeficit ? 'badge-deficit' : 'badge-excedent'"
                                    >
                                        {{ isOverallDeficit ? 'Déficit' : 'Excédent' }}
                                    </span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </v-card>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Filter card ──────────────────────────────────────────────────────────── */
.filter-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
}

.filter-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.filter-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    flex: 1;
}

.filter-select {
    max-width: 160px;
    min-width: 130px;
}

/* ── Period type toggle (Mensuel / Annuel) ────────────────────────────────── */
.period-type-group {
    display: flex;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    background: white;
    flex-shrink: 0;
}

.period-type-btn {
    display: flex;
    align-items: center;
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #64748b;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}

.period-type-btn:not(:last-child) {
    border-right: 1px solid #e2e8f0;
}

.period-type-btn:hover {
    background: #f1f5f9;
}

.period-type-btn.active {
    background: #6366f1;
    color: white;
}

/* ── KPI grid ─────────────────────────────────────────────────────────────── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 14px;
}

.kpi-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    transition: transform 0.15s, box-shadow 0.15s;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
}

.kpi-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    flex-shrink: 0;
}

.kpi-body {
    flex: 1;
    min-width: 0;
}

.kpi-label {
    font-size: 11px;
    font-weight: 500;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.kpi-value {
    font-size: 18px;
    font-weight: 700;
    line-height: 1.2;
}

.kpi-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
}

.kpi-badge {
    position: absolute;
    top: 10px;
    right: 10px;
}

/* ── View mode toggle ─────────────────────────────────────────────────────── */
.view-toggle-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

.view-toggle-group {
    display: flex;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    background: white;
}

.view-toggle-btn {
    display: flex;
    align-items: center;
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #64748b;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.view-toggle-btn:not(:last-child) {
    border-right: 1px solid #e2e8f0;
}

.view-toggle-btn:hover {
    background: #f1f5f9;
}

.view-toggle-btn.active {
    background: #6366f1;
    color: white;
}

/* ── Charts ───────────────────────────────────────────────────────────────── */
.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

@media (max-width: 900px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
}

.chart-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}

.chart-card-title {
    font-size: 13px !important;
    font-weight: 600;
    color: #475569;
    padding: 12px 16px !important;
}

/* ── Table card ───────────────────────────────────────────────────────────── */
.table-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.table-card-title {
    font-size: 13px !important;
    font-weight: 600;
    color: #475569;
    padding: 12px 16px !important;
}

.table-scroll-wrapper {
    overflow-x: auto;
}

.stats-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.stats-table thead tr {
    background: #f8fafc;
}

.stats-table thead th {
    padding: 10px 14px;
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border-bottom: 1px solid #e2e8f0;
}

.stats-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s;
}

.stats-table tbody tr.row-zero {
    opacity: 0.45;
}

.stats-table tbody tr.row-deficit {
    background: #fff5f5;
}

.stats-table tbody tr.row-active:hover {
    background: #f8fafc;
}

.stats-table td {
    padding: 9px 14px;
    white-space: nowrap;
    color: #334155;
}

.cell-date {
    font-weight: 500;
    color: #475569;
}

.invoice-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 24px;
    padding: 0 6px;
    border-radius: 12px;
    background: #e0e7ff;
    color: #4338ca;
    font-size: 12px;
    font-weight: 700;
}

.net-profit-cell {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 12px;
}

.net-profit-cell.excedent {
    background: #dcfce7;
    color: #166534;
}

.net-profit-cell.deficit {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-excedent {
    background: #dcfce7;
    color: #166534;
}

.badge-deficit {
    background: #fee2e2;
    color: #991b1b;
}

/* ── Summary footer ───────────────────────────────────────────────────────── */
.summary-row {
    background: #f8fafc;
    border-top: 2px solid #e2e8f0;
}

.summary-row td {
    padding: 11px 14px;
    font-size: 13px;
}

/* ── Empty state ──────────────────────────────────────────────────────────── */
.empty-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}

/* ── Text helpers ─────────────────────────────────────────────────────────── */
.text-left { text-align: left; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.font-medium { font-weight: 500; }
.font-bold { font-weight: 700; }
.text-slate-300 { color: #cbd5e1; }
.text-slate-500 { color: #64748b; }
.text-slate-600 { color: #475569; }
.text-purple-600 { color: #9333ea; }
.text-purple-700 { color: #7e22ce; }
.text-sky-600 { color: #0284c7; }
.text-sky-700 { color: #0369a1; }
.text-orange-500 { color: #f97316; }
.text-orange-600 { color: #ea580c; }
.text-amber-600 { color: #d97706; }
.text-amber-700 { color: #b45309; }
.text-indigo-700 { color: #4338ca; }
</style>
