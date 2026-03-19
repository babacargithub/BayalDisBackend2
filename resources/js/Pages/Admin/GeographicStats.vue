<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps({
    periodType: { type: String, required: true },
    year: { type: Number, required: true },
    month: { type: Number, required: true },
    view: { type: String, required: true },           // 'lignes' | 'sectors'
    selectedLigneId: { type: Number, required: true },
    availableZones: { type: Array, required: true },
    availableLignes: { type: Array, required: true },
    geographicActivity: { type: Object, default: null },
    sectorActivity: { type: Object, default: null },
});

// ─── Filter state ─────────────────────────────────────────────────────────────

const selectedPeriodType = ref(props.periodType);
const selectedYear = ref(props.year);
const selectedMonth = ref(props.month);
const currentView = ref(props.view);

// Sector selector state
const selectedZoneId = ref(
    props.availableLignes.find((l) => l.id === props.selectedLigneId)?.zone_id ?? 0,
);
const selectedLigneId = ref(props.selectedLigneId);

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

const periodTypeOptions = [
    { label: 'Tout le temps', value: 'all', icon: 'mdi-infinity' },
    { label: 'Annuel', value: 'yearly', icon: 'mdi-calendar-year' },
    { label: 'Mensuel', value: 'monthly', icon: 'mdi-calendar-month-outline' },
];

const viewOptions = [
    { label: 'Lignes', value: 'lignes', icon: 'mdi-map-marker-path' },
    { label: 'Secteurs', value: 'sectors', icon: 'mdi-map-marker-multiple' },
];

// Lignes filtered by the selected zone (for the sector drilldown selector)
const lignesForSelectedZone = computed(() =>
    selectedZoneId.value > 0
        ? props.availableLignes.filter((l) => l.zone_id === selectedZoneId.value)
        : props.availableLignes,
);

// ─── Page title ───────────────────────────────────────────────────────────────

const pageTitle = computed(() => {
    const isSectors = currentView.value === 'sectors';
    const activeData = isSectors ? props.sectorActivity : props.geographicActivity;
    const label = activeData?.period_label ?? null;
    const suffix = label ? ` — ${label}` : ' — Tout le temps';

    if (isSectors && props.sectorActivity) {
        return `Secteurs — ${props.sectorActivity.ligne_name}${suffix}`;
    }

    return `Zones & Lignes${suffix}`;
});

// ─── Navigation helpers ───────────────────────────────────────────────────────

function buildQueryParams(overrides = {}) {
    return {
        period_type: selectedPeriodType.value,
        year: selectedYear.value,
        month: selectedMonth.value,
        view: currentView.value,
        ligne_id: selectedLigneId.value,
        ...overrides,
    };
}

function applyFilters() {
    router.get(route('admin.geo-stats'), buildQueryParams(), { preserveScroll: false });
}

function onPeriodTypeChange(newType) {
    selectedPeriodType.value = newType;
    applyFilters();
}

function switchView(newView) {
    currentView.value = newView;
    router.get(
        route('admin.geo-stats'),
        buildQueryParams({ view: newView, ligne_id: newView === 'lignes' ? 0 : selectedLigneId.value }),
        { preserveScroll: false },
    );
}

function applyLigneSelection() {
    router.get(
        route('admin.geo-stats'),
        buildQueryParams({ view: 'sectors', ligne_id: selectedLigneId.value }),
        { preserveScroll: false },
    );
}

function onZoneChange() {
    selectedLigneId.value = 0;
}

// ─── Formatting ───────────────────────────────────────────────────────────────

function formatCurrency(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' F';
}

function formatRate(rate) {
    return Number(rate).toFixed(1) + ' %';
}

// ─── Active data (KPI cards pull from this) ───────────────────────────────────

const activeGrandTotals = computed(() =>
    currentView.value === 'sectors' ? props.sectorActivity : props.geographicActivity,
);

// ─── Profitability grade helpers ─────────────────────────────────────────────

const gradeConfig = {
    A: { color: '#16a34a', bg: '#dcfce7', label: 'A — Excellent' },
    B: { color: '#0284c7', bg: '#e0f2fe', label: 'B — Bon' },
    C: { color: '#d97706', bg: '#fef3c7', label: 'C — Moyen' },
    D: { color: '#9333ea', bg: '#f3e8ff', label: 'D — Faible' },
    F: { color: '#dc2626', bg: '#fee2e2', label: 'F — Déficit' },
};

function gradeStyle(grade) {
    const cfg = gradeConfig[grade] ?? gradeConfig['D'];
    return { color: cfg.color, background: cfg.bg };
}

function gradeLabel(grade) {
    return gradeConfig[grade]?.label ?? grade;
}

// ─── KPI cards (pluggable — work for both lignes and sectors mode) ─────────────

const kpiCards = computed(() => {
    const totals = activeGrandTotals.value;
    if (!totals) {
        return [];
    }

    const isDeficit = totals.net_profit < 0;
    const isSectors = currentView.value === 'sectors';
    const recurringCount = totals.recurring_customers_count ?? 0;

    return [
        {
            key: 'total_customers',
            label: 'Clients totaux',
            value: String(totals.total_customers_count),
            sub: `${totals.confirmed_customers_count} confirmés · ${totals.prospect_customers_count} prospects`,
            icon: 'mdi-account-group',
            color: '#6366f1',
        },
        ...(isSectors
            ? [
                  {
                      key: 'recurring_customers',
                      label: 'Clients récurrents',
                      value: String(recurringCount),
                      sub: `Ont commandé ≥ 2 fois`,
                      icon: 'mdi-account-reactivate',
                      color: '#8b5cf6',
                  },
              ]
            : []),
        {
            key: 'total_sales',
            label: "Chiffre d'affaires",
            value: formatCurrency(totals.total_sales),
            icon: 'mdi-cash-register',
            color: '#0ea5e9',
        },
        {
            key: 'invoices_count',
            label: 'Factures émises',
            value: String(totals.invoices_count),
            icon: 'mdi-file-document-multiple-outline',
            color: '#64748b',
        },
        {
            key: 'estimated_profit',
            label: 'Profit estimé',
            value: formatCurrency(totals.total_estimated_profit),
            icon: 'mdi-chart-areaspline',
            color: '#8b5cf6',
        },
        {
            key: 'realized_profit',
            label: 'Profit réalisé',
            value: formatCurrency(totals.total_realized_profit),
            icon: 'mdi-cash-check',
            color: '#10b981',
        },
        {
            key: 'collected',
            label: 'Encaissé',
            value: formatCurrency(totals.total_payments_collected),
            icon: 'mdi-bank-transfer-in',
            color: '#14b8a6',
            sub: `Taux : ${formatRate(totals.overall_collection_rate)}`,
        },
        {
            key: 'commissions',
            label: 'Commissions',
            value: formatCurrency(totals.total_commissions),
            icon: 'mdi-account-cash',
            color: '#f59e0b',
        },
        {
            key: 'delivery_cost',
            label: 'Coûts livraison',
            value: formatCurrency(totals.total_delivery_cost),
            icon: 'mdi-truck-delivery',
            color: '#f97316',
        },
        {
            key: 'net_profit',
            label: 'Bénéfice net',
            value: formatCurrency(totals.net_profit),
            icon: isDeficit ? 'mdi-trending-down' : 'mdi-trending-up',
            color: isDeficit ? '#dc2626' : '#16a34a',
            badge: formatRate(totals.overall_profitability_rate),
            badgeSub: 'taux net',
        },
    ];
});

// ─── LIGNES VIEW — Expand / collapse / sort ───────────────────────────────────

const zoneStats = computed(() => props.geographicActivity?.zone_stats ?? []);
const hasLignesData = computed(() => zoneStats.value.length > 0);

const expandedZones = ref(new Set(zoneStats.value.map((z) => z.zone_id)));

function toggleZone(zoneId) {
    if (expandedZones.value.has(zoneId)) {
        expandedZones.value.delete(zoneId);
    } else {
        expandedZones.value.add(zoneId);
    }
}

function expandAll() {
    expandedZones.value = new Set(zoneStats.value.map((z) => z.zone_id));
}

function collapseAll() {
    expandedZones.value = new Set();
}

const ligneSortKey = ref('total_sales');
const ligneSortDesc = ref(true);

const ligneSortOptions = [
    { label: 'CA', value: 'total_sales' },
    { label: 'Bénéfice net', value: 'net_profit' },
    { label: 'Rentabilité', value: 'profitability_rate' },
    { label: 'Encaissement', value: 'collection_rate' },
    { label: 'Clients', value: 'total_customers_count' },
    { label: 'Factures', value: 'invoices_count' },
    { label: 'Nom', value: 'ligne_name' },
];

function setLigneSort(key) {
    if (ligneSortKey.value === key) {
        ligneSortDesc.value = !ligneSortDesc.value;
    } else {
        ligneSortKey.value = key;
        ligneSortDesc.value = true;
    }
}

function sortedLignes(ligneStats) {
    return [...ligneStats].sort((ligneA, ligneB) => {
        const valueA = ligneA[ligneSortKey.value];
        const valueB = ligneB[ligneSortKey.value];
        if (typeof valueA === 'string') {
            return ligneSortDesc.value ? valueB.localeCompare(valueA) : valueA.localeCompare(valueB);
        }
        return ligneSortDesc.value ? valueB - valueA : valueA - valueB;
    });
}

// ─── SECTORS VIEW — Sort, expand, insights ────────────────────────────────────

const sectorStats = computed(() => props.sectorActivity?.sector_stats ?? []);
const hasSectorsData = computed(() => sectorStats.value.length > 0);

const sectorSortKey = ref('total_sales');
const sectorSortDesc = ref(true);

const sectorSortOptions = [
    { label: 'CA', value: 'total_sales' },
    { label: 'Bénéfice net', value: 'net_profit' },
    { label: 'Rentabilité', value: 'profitability_rate' },
    { label: 'Encaissement', value: 'collection_rate' },
    { label: 'Récurrents', value: 'recurring_customers_rate' },
    { label: 'Clients', value: 'total_customers_count' },
    { label: 'Factures', value: 'invoices_count' },
    { label: 'Nom', value: 'sector_name' },
];

function setSectorSort(key) {
    if (sectorSortKey.value === key) {
        sectorSortDesc.value = !sectorSortDesc.value;
    } else {
        sectorSortKey.value = key;
        sectorSortDesc.value = true;
    }
}

const sortedSectors = computed(() =>
    [...sectorStats.value].sort((sectorA, sectorB) => {
        const valueA = sectorA[sectorSortKey.value];
        const valueB = sectorB[sectorSortKey.value];
        if (typeof valueA === 'string') {
            return sectorSortDesc.value ? valueB.localeCompare(valueA) : valueA.localeCompare(valueB);
        }
        return sectorSortDesc.value ? valueB - valueA : valueA - valueB;
    }),
);

// Sector insight highlights — which sector leads in each key metric
const insightBestProfitability = computed(() =>
    sectorStats.value.length === 0
        ? null
        : sectorStats.value.reduce((best, s) =>
              s.profitability_rate > best.profitability_rate ? s : best,
          ),
);

const insightBestCollection = computed(() =>
    sectorStats.value.length === 0
        ? null
        : sectorStats.value.reduce((best, s) =>
              s.collection_rate > best.collection_rate ? s : best,
          ),
);

const insightMostRecurring = computed(() =>
    sectorStats.value.length === 0
        ? null
        : sectorStats.value.reduce((best, s) =>
              s.recurring_customers_rate > best.recurring_customers_rate ? s : best,
          ),
);

// Expandable sector rows to show top customers
const expandedSectors = ref(new Set());

function toggleSector(sectorId) {
    if (expandedSectors.value.has(sectorId)) {
        expandedSectors.value.delete(sectorId);
    } else {
        expandedSectors.value.add(sectorId);
    }
}

// Top-customer sub-view toggle within each sector ('volume' | 'frequency')
const customerViewBySectorId = ref({});

function getCustomerView(sectorId) {
    return customerViewBySectorId.value[sectorId] ?? 'volume';
}

function setCustomerView(sectorId, mode) {
    customerViewBySectorId.value = { ...customerViewBySectorId.value, [sectorId]: mode };
}

function topCustomersSorted(sector, mode) {
    const list = [...(sector.top_customers ?? [])];
    if (mode === 'frequency') {
        return list.sort((a, b) => b.invoices_count - a.invoices_count);
    }
    return list.sort((a, b) => b.total_sales - a.total_sales);
}
</script>

<template>
    <Head title="Zones & Lignes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ pageTitle }}</h2>
        </template>

        <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-6">

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- FILTER SECTION                                                  -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <v-card class="filter-card" elevation="0">
                <v-card-text>

                    <!-- Row 1 : view toggle + period type + date selectors -->
                    <div class="filter-row">

                        <!-- View toggle: Lignes / Secteurs -->
                        <div class="view-type-group">
                            <button
                                v-for="opt in viewOptions"
                                :key="opt.value"
                                class="view-type-btn"
                                :class="{ active: currentView === opt.value }"
                                @click="switchView(opt.value)"
                            >
                                <v-icon size="14" class="mr-1">{{ opt.icon }}</v-icon>
                                {{ opt.label }}
                            </button>
                        </div>

                        <v-divider vertical class="mx-3" style="height:36px;align-self:center" />

                        <!-- Period type toggle -->
                        <div class="period-type-group">
                            <button
                                v-for="opt in periodTypeOptions"
                                :key="opt.value"
                                class="period-type-btn"
                                :class="{ active: selectedPeriodType === opt.value }"
                                @click="onPeriodTypeChange(opt.value)"
                            >
                                <v-icon size="14" class="mr-1">{{ opt.icon }}</v-icon>
                                {{ opt.label }}
                            </button>
                        </div>

                        <v-divider vertical class="mx-3" style="height:36px;align-self:center" />

                        <div class="filter-controls">
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
                                v-if="selectedPeriodType !== 'all'"
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
                                v-if="selectedPeriodType !== 'all'"
                                color="indigo"
                                variant="flat"
                                prepend-icon="mdi-magnify"
                                @click="applyFilters"
                            >
                                Afficher
                            </v-btn>
                        </div>
                    </div>

                    <!-- Row 2 : Zone + Ligne selector (sectors mode only) -->
                    <div v-if="currentView === 'sectors'" class="sector-selector-row">
                        <v-icon size="16" color="indigo" class="mr-1">mdi-map-marker-multiple</v-icon>
                        <span class="sector-selector-label">Sélectionner une ligne :</span>

                        <v-select
                            v-model="selectedZoneId"
                            :items="availableZones"
                            item-title="name"
                            item-value="id"
                            label="Zone"
                            density="compact"
                            variant="outlined"
                            hide-details
                            clearable
                            class="filter-select"
                            @update:modelValue="onZoneChange"
                        />

                        <v-select
                            v-model="selectedLigneId"
                            :items="lignesForSelectedZone"
                            item-title="name"
                            item-value="id"
                            label="Ligne"
                            density="compact"
                            variant="outlined"
                            hide-details
                            class="filter-select"
                        />

                        <v-btn
                            color="indigo"
                            variant="flat"
                            prepend-icon="mdi-map-marker-multiple"
                            :disabled="!selectedLigneId"
                            @click="applyLigneSelection"
                        >
                            Voir les secteurs
                        </v-btn>
                    </div>

                </v-card-text>
            </v-card>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- KPI SUMMARY CARDS                                               -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div v-if="activeGrandTotals" class="kpi-grid">
                <div v-for="card in kpiCards" :key="card.key" class="kpi-card">
                    <div class="kpi-icon-wrap" :style="{ background: card.color + '20' }">
                        <v-icon :color="card.color" size="22">{{ card.icon }}</v-icon>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-label">{{ card.label }}</div>
                        <div class="kpi-value" :style="{ color: card.color }">{{ card.value }}</div>
                        <div v-if="card.sub" class="kpi-sub">{{ card.sub }}</div>
                    </div>
                    <div v-if="card.badge" class="kpi-badge-wrap">
                        <span class="kpi-rate-badge" :style="{ color: card.color, background: card.color + '18' }">
                            {{ card.badge }}
                        </span>
                        <span class="kpi-rate-label">{{ card.badgeSub }}</span>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- SECTORS VIEW                                                    -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <template v-if="currentView === 'sectors'">

                <!-- Prompt when no ligne selected yet -->
                <v-card v-if="!sectorActivity" elevation="0" class="empty-card">
                    <v-card-text class="text-center py-16">
                        <v-icon size="64" color="grey-lighten-2">mdi-map-marker-multiple</v-icon>
                        <p class="text-h6 text-grey mt-4">Sélectionnez une ligne</p>
                        <p class="text-body-2 text-grey-lighten-1">
                            Choisissez une zone et une ligne pour analyser ses secteurs.
                        </p>
                    </v-card-text>
                </v-card>

                <template v-if="sectorActivity">

                    <!-- ── Insight highlights ──────────────────────────────── -->
                    <div v-if="hasSectorsData" class="insights-row">
                        <div v-if="insightBestProfitability" class="insight-card insight-profitability">
                            <div class="insight-icon-wrap">
                                <v-icon size="20" color="#16a34a">mdi-trophy</v-icon>
                            </div>
                            <div class="insight-body">
                                <div class="insight-label">Plus rentable</div>
                                <div class="insight-name">{{ insightBestProfitability.sector_name }}</div>
                                <div class="insight-value">{{ formatRate(insightBestProfitability.profitability_rate) }}</div>
                            </div>
                        </div>

                        <div v-if="insightBestCollection" class="insight-card insight-collection">
                            <div class="insight-icon-wrap">
                                <v-icon size="20" color="#0d9488">mdi-bank-transfer-in</v-icon>
                            </div>
                            <div class="insight-body">
                                <div class="insight-label">Meilleur encaissement</div>
                                <div class="insight-name">{{ insightBestCollection.sector_name }}</div>
                                <div class="insight-value">{{ formatRate(insightBestCollection.collection_rate) }}</div>
                            </div>
                        </div>

                        <div v-if="insightMostRecurring" class="insight-card insight-recurring">
                            <div class="insight-icon-wrap">
                                <v-icon size="20" color="#8b5cf6">mdi-account-reactivate</v-icon>
                            </div>
                            <div class="insight-body">
                                <div class="insight-label">Plus de clients fidèles</div>
                                <div class="insight-name">{{ insightMostRecurring.sector_name }}</div>
                                <div class="insight-value">
                                    {{ insightMostRecurring.recurring_customers_count }} clients récurrents
                                    ({{ formatRate(insightMostRecurring.recurring_customers_rate) }})
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Empty state (sectors exist but no invoices) ─────── -->
                    <v-card v-if="!hasSectorsData" elevation="0" class="empty-card">
                        <v-card-text class="text-center py-16">
                            <v-icon size="64" color="grey-lighten-2">mdi-map-search-outline</v-icon>
                            <p class="text-h6 text-grey mt-4">Aucun secteur configuré</p>
                            <p class="text-body-2 text-grey-lighten-1">
                                Créez des secteurs pour cette ligne pour voir l'analyse.
                            </p>
                        </v-card-text>
                    </v-card>

                    <!-- ── Sector sort toolbar ─────────────────────────────── -->
                    <div v-if="hasSectorsData" class="zones-toolbar">
                        <div class="sort-row">
                            <span class="sort-label">Trier par :</span>
                            <button
                                v-for="opt in sectorSortOptions"
                                :key="opt.value"
                                class="sort-btn"
                                :class="{ active: sectorSortKey === opt.value }"
                                @click="setSectorSort(opt.value)"
                            >
                                {{ opt.label }}
                                <v-icon v-if="sectorSortKey === opt.value" size="13" class="ml-1">
                                    {{ sectorSortDesc ? 'mdi-arrow-down' : 'mdi-arrow-up' }}
                                </v-icon>
                            </button>
                        </div>
                        <div class="expand-controls">
                            <button class="expand-btn" @click="expandedSectors = new Set(sortedSectors.map(s => s.sector_id))">
                                Voir tous les clients
                            </button>
                            <button class="expand-btn" @click="expandedSectors = new Set()">
                                Masquer
                            </button>
                        </div>
                    </div>

                    <!-- ── Sector table ────────────────────────────────────── -->
                    <div v-if="hasSectorsData" class="zone-panel">
                        <div class="ligne-table-wrapper">
                            <table class="ligne-table">
                                <thead>
                                    <tr>
                                        <th class="col-name">Secteur</th>
                                        <th class="col-num text-center">Confirmés</th>
                                        <th class="col-num text-center">Prospects</th>
                                        <th class="col-num text-center">Récurrents</th>
                                        <th class="col-num text-center">Factures</th>
                                        <th class="col-money text-right">CA</th>
                                        <th class="col-money text-right">Profit estimé</th>
                                        <th class="col-money text-right">Profit réalisé</th>
                                        <th class="col-money text-right">Encaissé</th>
                                        <th class="col-money text-right">Commissions</th>
                                        <th class="col-money text-right">Livraison</th>
                                        <th class="col-money text-right">Bénéfice net</th>
                                        <th class="col-rate text-right">Taux encaiss.</th>
                                        <th class="col-score text-center">Rentabilité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="sector in sortedSectors" :key="sector.sector_id">
                                        <!-- Sector row (clickable to expand top customers) -->
                                        <tr
                                            class="sector-row"
                                            :class="{
                                                'row-deficit': sector.net_profit < 0 && sector.invoices_count > 0,
                                                'row-inactive': sector.invoices_count === 0,
                                                'row-expanded': expandedSectors.has(sector.sector_id),
                                            }"
                                            @click="toggleSector(sector.sector_id)"
                                        >
                                            <td class="cell-name">
                                                <div class="sector-name-cell">
                                                    <v-icon
                                                        size="13"
                                                        color="grey"
                                                        class="mr-1"
                                                    >
                                                        {{ expandedSectors.has(sector.sector_id) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                                                    </v-icon>
                                                    {{ sector.sector_name }}
                                                    <!-- Insight badges -->
                                                    <span
                                                        v-if="insightBestProfitability?.sector_id === sector.sector_id"
                                                        class="insight-badge profitability"
                                                        title="Secteur le plus rentable"
                                                    >🏆</span>
                                                    <span
                                                        v-if="insightBestCollection?.sector_id === sector.sector_id"
                                                        class="insight-badge collection"
                                                        title="Meilleur taux d'encaissement"
                                                    >💰</span>
                                                    <span
                                                        v-if="insightMostRecurring?.sector_id === sector.sector_id"
                                                        class="insight-badge recurring"
                                                        title="Plus de clients fidèles"
                                                    >🔄</span>
                                                </div>
                                            </td>

                                            <td class="text-center">
                                                <span v-if="sector.confirmed_customers_count > 0" class="customer-chip confirmed">
                                                    {{ sector.confirmed_customers_count }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-center">
                                                <span v-if="sector.prospect_customers_count > 0" class="customer-chip prospect">
                                                    {{ sector.prospect_customers_count }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-center">
                                                <div v-if="sector.total_customers_count > 0" class="recurring-cell">
                                                    <span class="customer-chip recurring">
                                                        {{ sector.recurring_customers_count }}
                                                    </span>
                                                    <span class="recurring-rate">
                                                        {{ formatRate(sector.recurring_customers_rate) }}
                                                    </span>
                                                </div>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-center">
                                                <span v-if="sector.invoices_count > 0" class="invoice-chip">
                                                    {{ sector.invoices_count }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right font-medium">
                                                <span v-if="sector.total_sales > 0">{{ formatCurrency(sector.total_sales) }}</span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right text-purple">
                                                <span v-if="sector.total_estimated_profit > 0">
                                                    {{ formatCurrency(sector.total_estimated_profit) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right text-emerald">
                                                <span v-if="sector.total_realized_profit > 0">
                                                    {{ formatCurrency(sector.total_realized_profit) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right text-teal">
                                                <span v-if="sector.total_payments_collected > 0">
                                                    {{ formatCurrency(sector.total_payments_collected) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right text-amber">
                                                <span v-if="sector.total_commissions > 0">
                                                    {{ formatCurrency(sector.total_commissions) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right text-orange">
                                                <span v-if="sector.total_delivery_cost > 0">
                                                    {{ formatCurrency(sector.total_delivery_cost) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right">
                                                <span
                                                    v-if="sector.invoices_count > 0"
                                                    class="net-profit-cell"
                                                    :class="sector.net_profit < 0 ? 'deficit' : 'excedent'"
                                                >
                                                    {{ formatCurrency(sector.net_profit) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-right">
                                                <span v-if="sector.invoices_count > 0" class="rate-text">
                                                    {{ formatRate(sector.collection_rate) }}
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                            </td>

                                            <td class="text-center">
                                                <div v-if="sector.invoices_count > 0" class="score-cell">
                                                    <span
                                                        class="grade-badge"
                                                        :style="gradeStyle(sector.profitability_grade)"
                                                        :title="gradeLabel(sector.profitability_grade)"
                                                    >
                                                        {{ sector.profitability_grade }}
                                                    </span>
                                                    <span class="score-rate">{{ formatRate(sector.profitability_rate) }}</span>
                                                </div>
                                                <span v-else class="text-muted">—</span>
                                            </td>
                                        </tr>

                                        <!-- Expandable: top customers for this sector -->
                                        <tr
                                            v-if="expandedSectors.has(sector.sector_id)"
                                            class="top-customers-row"
                                        >
                                            <td colspan="14" class="top-customers-cell">
                                                <div v-if="sector.top_customers.length === 0" class="top-customers-empty">
                                                    <v-icon size="16" color="grey-lighten-2" class="mr-1">mdi-account-off</v-icon>
                                                    Aucun client avec des factures dans cette période.
                                                </div>

                                                <div v-else class="top-customers-panel">
                                                    <div class="top-customers-header">
                                                        <v-icon size="15" color="indigo" class="mr-1">mdi-account-star</v-icon>
                                                        <span class="top-customers-title">Meilleurs clients — {{ sector.sector_name }}</span>

                                                        <!-- View toggle: volume / frequency -->
                                                        <div class="customer-view-toggle">
                                                            <button
                                                                class="cview-btn"
                                                                :class="{ active: getCustomerView(sector.sector_id) === 'volume' }"
                                                                @click.stop="setCustomerView(sector.sector_id, 'volume')"
                                                            >
                                                                Par volume
                                                            </button>
                                                            <button
                                                                class="cview-btn"
                                                                :class="{ active: getCustomerView(sector.sector_id) === 'frequency' }"
                                                                @click.stop="setCustomerView(sector.sector_id, 'frequency')"
                                                            >
                                                                Par fréquence
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <table class="customer-table">
                                                        <thead>
                                                            <tr>
                                                                <th class="cth-rank">#</th>
                                                                <th class="cth-name">Client</th>
                                                                <th class="cth-invoices text-center">Factures</th>
                                                                <th class="cth-sales text-right">CA total</th>
                                                                <th class="cth-status text-center">Fidélité</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr
                                                                v-for="(customer, index) in topCustomersSorted(sector, getCustomerView(sector.sector_id))"
                                                                :key="customer.customer_id"
                                                                class="customer-row"
                                                            >
                                                                <td class="cth-rank">
                                                                    <span class="rank-badge" :class="`rank-${index + 1}`">
                                                                        {{ index + 1 }}
                                                                    </span>
                                                                </td>
                                                                <td class="cth-name font-medium">{{ customer.customer_name }}</td>
                                                                <td class="cth-invoices text-center">
                                                                    <span class="invoice-chip">{{ customer.invoices_count }}</span>
                                                                </td>
                                                                <td class="cth-sales text-right font-medium">
                                                                    {{ formatCurrency(customer.total_sales) }}
                                                                </td>
                                                                <td class="cth-status text-center">
                                                                    <span v-if="customer.is_recurring" class="recurring-badge">
                                                                        <v-icon size="11" class="mr-1">mdi-account-reactivate</v-icon>
                                                                        Récurrent
                                                                    </span>
                                                                    <span v-else class="new-badge">
                                                                        <v-icon size="11" class="mr-1">mdi-account-plus</v-icon>
                                                                        Nouveau
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>

                                <!-- Sector totals footer -->
                                <tfoot>
                                    <tr class="zone-total-row">
                                        <td class="font-bold">Total ligne</td>
                                        <td class="text-center font-bold">{{ sectorActivity.confirmed_customers_count }}</td>
                                        <td class="text-center font-bold">{{ sectorActivity.prospect_customers_count }}</td>
                                        <td class="text-center font-bold">{{ sectorActivity.recurring_customers_count }}</td>
                                        <td class="text-center font-bold">{{ sectorActivity.invoices_count }}</td>
                                        <td class="text-right font-bold">{{ formatCurrency(sectorActivity.total_sales) }}</td>
                                        <td class="text-right font-bold text-purple">{{ formatCurrency(sectorActivity.total_estimated_profit) }}</td>
                                        <td class="text-right font-bold text-emerald">{{ formatCurrency(sectorActivity.total_realized_profit) }}</td>
                                        <td class="text-right font-bold text-teal">{{ formatCurrency(sectorActivity.total_payments_collected) }}</td>
                                        <td class="text-right font-bold text-amber">{{ formatCurrency(sectorActivity.total_commissions) }}</td>
                                        <td class="text-right font-bold text-orange">{{ formatCurrency(sectorActivity.total_delivery_cost) }}</td>
                                        <td class="text-right">
                                            <span
                                                class="net-profit-cell"
                                                :class="sectorActivity.net_profit < 0 ? 'deficit' : 'excedent'"
                                            >
                                                {{ formatCurrency(sectorActivity.net_profit) }}
                                            </span>
                                        </td>
                                        <td class="text-right font-bold">{{ formatRate(sectorActivity.overall_collection_rate) }}</td>
                                        <td class="text-center">—</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </template>
            </template>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- LIGNES VIEW                                                     -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <template v-if="currentView === 'lignes'">

                <v-card v-if="!hasLignesData" elevation="0" class="empty-card">
                    <v-card-text class="text-center py-16">
                        <v-icon size="64" color="grey-lighten-2">mdi-map-search-outline</v-icon>
                        <p class="text-h6 text-grey mt-4">Aucune ligne configurée</p>
                        <p class="text-body-2 text-grey-lighten-1">
                            Créez des zones et des lignes pour voir l'analyse géographique.
                        </p>
                    </v-card-text>
                </v-card>

                <!-- Sort toolbar -->
                <div v-if="hasLignesData" class="zones-toolbar">
                    <div class="sort-row">
                        <span class="sort-label">Trier par :</span>
                        <button
                            v-for="opt in ligneSortOptions"
                            :key="opt.value"
                            class="sort-btn"
                            :class="{ active: ligneSortKey === opt.value }"
                            @click="setLigneSort(opt.value)"
                        >
                            {{ opt.label }}
                            <v-icon v-if="ligneSortKey === opt.value" size="13" class="ml-1">
                                {{ ligneSortDesc ? 'mdi-arrow-down' : 'mdi-arrow-up' }}
                            </v-icon>
                        </button>
                    </div>
                    <div class="expand-controls">
                        <button class="expand-btn" @click="expandAll">Tout ouvrir</button>
                        <button class="expand-btn" @click="collapseAll">Tout fermer</button>
                    </div>
                </div>

                <!-- Zone panels -->
                <div v-if="hasLignesData" class="space-y-4">
                    <div v-for="zone in zoneStats" :key="zone.zone_id" class="zone-panel">

                        <div class="zone-header" @click="toggleZone(zone.zone_id)">
                            <div class="zone-header-left">
                                <v-icon size="18" class="mr-2" color="indigo">mdi-map-marker-radius</v-icon>
                                <span class="zone-name">{{ zone.zone_name }}</span>
                                <span class="zone-ligne-count">{{ zone.ligne_stats.length }} ligne(s)</span>
                            </div>

                            <div class="zone-header-metrics">
                                <div class="zone-metric">
                                    <span class="zone-metric-label">CA</span>
                                    <span class="zone-metric-value">{{ formatCurrency(zone.total_sales) }}</span>
                                </div>
                                <div class="zone-metric">
                                    <span class="zone-metric-label">Bénéfice net</span>
                                    <span
                                        class="zone-metric-value"
                                        :style="{ color: zone.net_profit < 0 ? '#dc2626' : '#16a34a' }"
                                    >
                                        {{ formatCurrency(zone.net_profit) }}
                                    </span>
                                </div>
                                <div class="zone-metric">
                                    <span class="zone-metric-label">Clients</span>
                                    <span class="zone-metric-value">{{ zone.total_customers_count }}</span>
                                </div>
                                <span
                                    class="grade-badge"
                                    :style="gradeStyle(zone.profitability_grade)"
                                    :title="gradeLabel(zone.profitability_grade)"
                                >
                                    {{ zone.profitability_grade }}
                                    <span class="grade-rate">&nbsp;{{ formatRate(zone.profitability_rate) }}</span>
                                </span>
                            </div>

                            <v-icon size="18" color="grey">
                                {{ expandedZones.has(zone.zone_id) ? 'mdi-chevron-up' : 'mdi-chevron-down' }}
                            </v-icon>
                        </div>

                        <div v-if="expandedZones.has(zone.zone_id)" class="ligne-table-wrapper">
                            <table class="ligne-table">
                                <thead>
                                    <tr>
                                        <th class="col-name">Ligne</th>
                                        <th class="col-num text-center">Confirmés</th>
                                        <th class="col-num text-center">Prospects</th>
                                        <th class="col-num text-center">Factures</th>
                                        <th class="col-money text-right">CA</th>
                                        <th class="col-money text-right">Profit estimé</th>
                                        <th class="col-money text-right">Profit réalisé</th>
                                        <th class="col-money text-right">Encaissé</th>
                                        <th class="col-money text-right">Commissions</th>
                                        <th class="col-money text-right">Livraison</th>
                                        <th class="col-money text-right">Bénéfice net</th>
                                        <th class="col-rate text-right">Taux encaiss.</th>
                                        <th class="col-score text-center">Rentabilité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="ligne in sortedLignes(zone.ligne_stats)"
                                        :key="ligne.ligne_id"
                                        :class="{
                                            'row-deficit': ligne.net_profit < 0 && ligne.invoices_count > 0,
                                            'row-inactive': ligne.invoices_count === 0,
                                        }"
                                    >
                                        <td class="cell-name">{{ ligne.ligne_name }}</td>

                                        <td class="text-center">
                                            <span v-if="ligne.confirmed_customers_count > 0" class="customer-chip confirmed">
                                                {{ ligne.confirmed_customers_count }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-center">
                                            <span v-if="ligne.prospect_customers_count > 0" class="customer-chip prospect">
                                                {{ ligne.prospect_customers_count }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-center">
                                            <span v-if="ligne.invoices_count > 0" class="invoice-chip">
                                                {{ ligne.invoices_count }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right font-medium">
                                            <span v-if="ligne.total_sales > 0">{{ formatCurrency(ligne.total_sales) }}</span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right text-purple">
                                            <span v-if="ligne.total_estimated_profit > 0">
                                                {{ formatCurrency(ligne.total_estimated_profit) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right text-emerald">
                                            <span v-if="ligne.total_realized_profit > 0">
                                                {{ formatCurrency(ligne.total_realized_profit) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right text-teal">
                                            <span v-if="ligne.total_payments_collected > 0">
                                                {{ formatCurrency(ligne.total_payments_collected) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right text-amber">
                                            <span v-if="ligne.total_commissions > 0">
                                                {{ formatCurrency(ligne.total_commissions) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right text-orange">
                                            <span v-if="ligne.total_delivery_cost > 0">
                                                {{ formatCurrency(ligne.total_delivery_cost) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right">
                                            <span
                                                v-if="ligne.invoices_count > 0"
                                                class="net-profit-cell"
                                                :class="ligne.net_profit < 0 ? 'deficit' : 'excedent'"
                                            >
                                                {{ formatCurrency(ligne.net_profit) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-right">
                                            <span v-if="ligne.invoices_count > 0" class="rate-text">
                                                {{ formatRate(ligne.collection_rate) }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td class="text-center">
                                            <div v-if="ligne.invoices_count > 0" class="score-cell">
                                                <span
                                                    class="grade-badge"
                                                    :style="gradeStyle(ligne.profitability_grade)"
                                                    :title="gradeLabel(ligne.profitability_grade)"
                                                >
                                                    {{ ligne.profitability_grade }}
                                                </span>
                                                <span class="score-rate">{{ formatRate(ligne.profitability_rate) }}</span>
                                            </div>
                                            <span v-else class="text-muted">—</span>
                                        </td>
                                    </tr>
                                </tbody>

                                <tfoot>
                                    <tr class="zone-total-row">
                                        <td class="font-bold">Sous-total</td>
                                        <td class="text-center font-bold">{{ zone.confirmed_customers_count }}</td>
                                        <td class="text-center font-bold">{{ zone.prospect_customers_count }}</td>
                                        <td class="text-center font-bold">{{ zone.invoices_count }}</td>
                                        <td class="text-right font-bold">{{ formatCurrency(zone.total_sales) }}</td>
                                        <td class="text-right font-bold text-purple">{{ formatCurrency(zone.total_estimated_profit) }}</td>
                                        <td class="text-right font-bold text-emerald">{{ formatCurrency(zone.total_realized_profit) }}</td>
                                        <td class="text-right font-bold text-teal">{{ formatCurrency(zone.total_payments_collected) }}</td>
                                        <td class="text-right font-bold text-amber">{{ formatCurrency(zone.total_commissions) }}</td>
                                        <td class="text-right font-bold text-orange">{{ formatCurrency(zone.total_delivery_cost) }}</td>
                                        <td class="text-right">
                                            <span
                                                class="net-profit-cell"
                                                :class="zone.net_profit < 0 ? 'deficit' : 'excedent'"
                                            >
                                                {{ formatCurrency(zone.net_profit) }}
                                            </span>
                                        </td>
                                        <td class="text-right font-bold">{{ formatRate(zone.collection_rate) }}</td>
                                        <td class="text-center">
                                            <div class="score-cell">
                                                <span class="grade-badge" :style="gradeStyle(zone.profitability_grade)">
                                                    {{ zone.profitability_grade }}
                                                </span>
                                                <span class="score-rate">{{ formatRate(zone.profitability_rate) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- GRADE LEGEND                                                    -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div v-if="activeGrandTotals" class="legend-card">
                <span class="legend-title">Score de rentabilité :</span>
                <span v-for="(cfg, grade) in gradeConfig" :key="grade" class="legend-item">
                    <span class="grade-badge small" :style="{ color: cfg.color, background: cfg.bg }">{{ grade }}</span>
                    {{ cfg.label.split('—')[1].trim() }}
                </span>
                <span class="legend-note">
                    = (Bénéfice net / CA) × 100 &nbsp;·&nbsp; seuils : A ≥ 15 % · B ≥ 8 % · C ≥ 3 % · D ≥ 0 % · F &lt; 0 %
                </span>
            </div>

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
}

.filter-select {
    max-width: 160px;
    min-width: 130px;
}

/* Sector selector row */
.sector-selector-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}

.sector-selector-label {
    font-size: 13px;
    font-weight: 500;
    color: #64748b;
    white-space: nowrap;
}

/* View type toggle */
.view-type-group {
    display: flex;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    background: white;
    flex-shrink: 0;
}

.view-type-btn {
    display: flex;
    align-items: center;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}

.view-type-btn:not(:last-child) { border-right: 1px solid #e2e8f0; }

.view-type-btn.active {
    background: #4f46e5;
    color: white;
}

/* Period type toggle */
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
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 500;
    color: #64748b;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}

.period-type-btn:not(:last-child) { border-right: 1px solid #e2e8f0; }

.period-type-btn.active {
    background: #6366f1;
    color: white;
}

/* ── KPI grid ─────────────────────────────────────────────────────────────── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.kpi-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    transition: transform 0.15s, box-shadow 0.15s;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.07);
}

.kpi-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    flex-shrink: 0;
}

.kpi-body { flex: 1; min-width: 0; }

.kpi-label {
    font-size: 11px;
    font-weight: 500;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
}

.kpi-value { font-size: 17px; font-weight: 700; line-height: 1.2; }
.kpi-sub { font-size: 11px; color: #94a3b8; margin-top: 3px; }

.kpi-badge-wrap {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    flex-shrink: 0;
}

.kpi-rate-badge {
    font-size: 13px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 6px;
}

.kpi-rate-label { font-size: 10px; color: #94a3b8; margin-top: 2px; }

/* ── Insight highlights ───────────────────────────────────────────────────── */
.insights-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 12px;
}

.insight-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    border: 1px solid;
}

.insight-profitability { border-color: #bbf7d0; background: #f0fdf4; }
.insight-collection     { border-color: #99f6e4; background: #f0fdfa; }
.insight-recurring      { border-color: #ddd6fe; background: #faf5ff; }

.insight-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: white;
    flex-shrink: 0;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

.insight-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
}

.insight-name {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-top: 1px;
}

.insight-value {
    font-size: 12px;
    font-weight: 500;
    color: #475569;
    margin-top: 1px;
}

/* ── Zones toolbar ────────────────────────────────────────────────────────── */
.zones-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

.sort-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.sort-label {
    font-size: 12px;
    color: #94a3b8;
    font-weight: 500;
    white-space: nowrap;
}

.sort-btn {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 500;
    color: #64748b;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}

.sort-btn.active {
    background: #6366f1;
    color: white;
    border-color: #6366f1;
}

.expand-controls { display: flex; gap: 8px; }

.expand-btn {
    font-size: 12px;
    color: #6366f1;
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px 4px;
    text-decoration: underline;
    text-underline-offset: 2px;
}

/* ── Zone panel ───────────────────────────────────────────────────────────── */
.zone-panel {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    background: white;
}

.zone-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: #f8fafc;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;
    flex-wrap: wrap;
}

.zone-header:hover { background: #f1f5f9; }

.zone-header-left {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 0;
}

.zone-name { font-size: 15px; font-weight: 700; color: #1e293b; }

.zone-ligne-count {
    font-size: 12px;
    color: #94a3b8;
    background: #e2e8f0;
    padding: 2px 7px;
    border-radius: 99px;
}

.zone-header-metrics {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.zone-metric { display: flex; flex-direction: column; align-items: flex-end; }
.zone-metric-label { font-size: 10px; color: #94a3b8; text-transform: uppercase; }
.zone-metric-value { font-size: 14px; font-weight: 600; color: #1e293b; }

/* ── Grade badge ──────────────────────────────────────────────────────────── */
.grade-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.5px;
}

.grade-badge.small {
    font-size: 11px;
    padding: 2px 7px;
}

.grade-rate {
    font-size: 11px;
    font-weight: 500;
    opacity: 0.8;
}

/* ── Ligne / Sector table ─────────────────────────────────────────────────── */
.ligne-table-wrapper { overflow-x: auto; }

.ligne-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
}

.ligne-table thead th {
    padding: 9px 12px;
    font-size: 10.5px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    background: #fafafa;
    border-bottom: 1px solid #e2e8f0;
}

.ligne-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s;
}

.ligne-table tbody tr:hover { background: #f8fafc; }
.ligne-table tbody tr.row-deficit { background: #fff8f8; }
.ligne-table tbody tr.row-inactive { opacity: 0.45; }

.ligne-table td { padding: 9px 12px; white-space: nowrap; color: #334155; }

.col-name { min-width: 140px; }
.col-num { width: 76px; }
.col-money { min-width: 130px; }
.col-rate { min-width: 100px; }
.col-score { min-width: 110px; }

.cell-name { font-weight: 600; color: #475569; }

/* Sector name cell with insight badges */
.sector-name-cell {
    display: flex;
    align-items: center;
    gap: 4px;
}

.sector-row {
    cursor: pointer;
}

.sector-row.row-expanded {
    background: #f8fafc;
}

.insight-badge {
    font-size: 13px;
    line-height: 1;
}

/* Customer chips */
.customer-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 22px;
    padding: 0 6px;
    border-radius: 11px;
    font-size: 11px;
    font-weight: 700;
}

.customer-chip.confirmed { background: #dcfce7; color: #166534; }
.customer-chip.prospect  { background: #fef3c7; color: #92400e; }
.customer-chip.recurring { background: #ede9fe; color: #5b21b6; }

.recurring-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.recurring-rate {
    font-size: 10px;
    color: #7c3aed;
    font-weight: 600;
}

.invoice-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 22px;
    padding: 0 6px;
    border-radius: 11px;
    background: #e0e7ff;
    color: #4338ca;
    font-size: 11px;
    font-weight: 700;
}

.net-profit-cell {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 5px;
    font-weight: 700;
    font-size: 12px;
}

.net-profit-cell.excedent { background: #dcfce7; color: #166534; }
.net-profit-cell.deficit  { background: #fee2e2; color: #991b1b; }

.rate-text { font-weight: 600; color: #475569; }

.score-cell { display: flex; align-items: center; justify-content: center; gap: 5px; }
.score-rate { font-size: 11px; color: #64748b; font-weight: 500; }

/* ── Zone total row ───────────────────────────────────────────────────────── */
.zone-total-row {
    background: #f1f5f9;
    border-top: 2px solid #e2e8f0;
}

.zone-total-row td { padding: 10px 12px; font-size: 12.5px; }

/* ── Top customers panel ──────────────────────────────────────────────────── */
.top-customers-row { background: #fafbff; }

.top-customers-cell {
    padding: 0 !important;
}

.top-customers-empty {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    font-size: 12px;
    color: #94a3b8;
}

.top-customers-panel {
    padding: 14px 20px 16px;
}

.top-customers-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.top-customers-title {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
}

.customer-view-toggle {
    display: flex;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
    background: white;
    margin-left: auto;
}

.cview-btn {
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 500;
    color: #64748b;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: background 0.12s, color 0.12s;
}

.cview-btn:not(:last-child) { border-right: 1px solid #e2e8f0; }

.cview-btn.active {
    background: #6366f1;
    color: white;
}

/* Customer sub-table */
.customer-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    max-width: 640px;
}

.customer-table thead th {
    padding: 6px 10px;
    font-size: 10px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}

.customer-row {
    border-bottom: 1px solid #f1f5f9;
}

.customer-row:hover { background: #f8fafc; }

.customer-table td { padding: 7px 10px; color: #334155; }

.cth-rank { width: 36px; }
.cth-name { min-width: 160px; }
.cth-invoices { width: 80px; }
.cth-sales { min-width: 120px; }
.cth-status { min-width: 100px; }

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 700;
    background: #e2e8f0;
    color: #475569;
}

.rank-badge.rank-1 { background: #fef3c7; color: #92400e; }
.rank-badge.rank-2 { background: #f1f5f9; color: #334155; }
.rank-badge.rank-3 { background: #fde8d8; color: #7c2d12; }

.recurring-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 7px;
    border-radius: 99px;
    background: #ede9fe;
    color: #5b21b6;
    font-size: 10px;
    font-weight: 600;
}

.new-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 7px;
    border-radius: 99px;
    background: #f0fdf4;
    color: #166534;
    font-size: 10px;
    font-weight: 600;
}

/* ── Legend ───────────────────────────────────────────────────────────────── */
.legend-card {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 12px 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-size: 12px;
    color: #64748b;
}

.legend-title { font-weight: 600; color: #475569; }

.legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

.legend-note {
    font-size: 11px;
    color: #94a3b8;
    margin-left: auto;
}

/* ── Empty state ──────────────────────────────────────────────────────────── */
.empty-card { border: 1px solid #e2e8f0; border-radius: 12px; }

/* ── Colour helpers ───────────────────────────────────────────────────────── */
.text-center { text-align: center; }
.text-right { text-align: right; }
.font-medium { font-weight: 500; }
.font-bold { font-weight: 700; }
.text-muted { color: #cbd5e1; }
.text-purple { color: #9333ea; }
.text-emerald { color: #059669; }
.text-teal { color: #0d9488; }
.text-amber { color: #d97706; }
.text-orange { color: #ea580c; }

/* ── Spacing utility ──────────────────────────────────────────────────────── */
.space-y-4 > * + * { margin-top: 16px; }
.mr-1 { margin-right: 4px; }
.mr-2 { margin-right: 8px; }
.ml-1 { margin-left: 4px; }
.mt-4 { margin-top: 16px; }
</style>
