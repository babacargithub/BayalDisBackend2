<template>
    <v-dialog v-model="isOpen" max-width="1100px" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center justify-space-between pa-4 border-b">
                <div>
                    <span class="text-h6">Historique des ventes</span>
                    <span v-if="beat" class="text-body-2 text-grey ml-3">
                        {{ beat.name }} — chaque {{ beat.day_of_week_label }}
                    </span>
                </div>
                <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
            </v-card-title>

            <!-- Date interval filter bar -->
            <div class="d-flex align-center gap-3 pa-3 border-b bg-grey-lighten-5 flex-wrap">
                <v-text-field
                    v-model="filterStartDate"
                    type="date"
                    label="Du"
                    density="compact"
                    variant="outlined"
                    hide-details
                    clearable
                    style="max-width: 180px"
                />
                <v-text-field
                    v-model="filterEndDate"
                    type="date"
                    label="Au"
                    density="compact"
                    variant="outlined"
                    hide-details
                    clearable
                    style="max-width: 180px"
                />
                <v-btn
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-magnify"
                    :loading="loading"
                    @click="loadHistory"
                >
                    Filtrer
                </v-btn>
                <v-btn
                    variant="text"
                    size="small"
                    prepend-icon="mdi-refresh"
                    :disabled="loading"
                    @click="resetDateFilter"
                >
                    Réinitialiser
                </v-btn>
                <v-divider vertical class="mx-1" style="height: 28px" />
                <v-checkbox
                    v-model="includeAllDaySales"
                    label="Inclure toutes les ventes du jour"
                    density="compact"
                    hide-details
                    color="primary"
                    class="flex-shrink-0"
                />
                <span v-if="isDateFilterActive" class="text-caption text-primary">
                    <v-icon icon="mdi-filter" size="14" /> Filtre actif
                </span>
            </div>

            <v-card-text class="pa-0">
                <!-- Loading -->
                <div v-if="loading" class="d-flex justify-center align-center py-12">
                    <v-progress-circular indeterminate color="primary" />
                </div>

                <!-- Error -->
                <div v-else-if="error" class="pa-6 text-center text-error">
                    <v-icon icon="mdi-alert-circle" size="40" class="mb-2" />
                    <p>Impossible de charger l'historique.</p>
                </div>

                <!-- Empty -->
                <div v-else-if="history.length === 0" class="pa-12 text-center text-grey">
                    <v-icon icon="mdi-calendar-blank" size="48" class="mb-3" />
                    <p>Aucun historique disponible pour ce beat.</p>
                </div>

                <!-- Content -->
                <div v-else>
                    <!-- Summary totals bar -->
                    <div class="d-flex flex-wrap gap-3 pa-4 bg-grey-lighten-5 border-b">
                        <div v-for="card in summaryCards" :key="card.label" class="summary-card">
                            <div class="summary-value" :style="{ color: card.color }">
                                {{ formatAmount(card.value) }}
                            </div>
                            <div class="summary-label">{{ card.label }}</div>
                        </div>
                    </div>

                    <!-- View tabs -->
                    <v-tabs v-model="activeTab" density="compact" class="border-b">
                        <v-tab value="table" prepend-icon="mdi-table">Tableau</v-tab>
                        <v-tab value="chart" prepend-icon="mdi-chart-line">Graphique</v-tab>
                    </v-tabs>

                    <!-- Table view -->
                    <v-window v-model="activeTab">
                        <v-window-item value="table">
                            <v-table density="compact" fixed-header height="420px">
                                <thead>
                                    <tr>
                                        <th class="text-left" style="min-width:190px">Date</th>
                                        <th class="text-center" style="min-width:80px">Factures</th>
                                        <th class="text-right" style="min-width:130px">Ventes</th>
                                        <th class="text-right" style="min-width:130px">Profit estimé</th>
                                        <th class="text-right" style="min-width:130px">Profit réalisé</th>
                                        <th class="text-right" style="min-width:120px">Commissions</th>
                                        <th class="text-right" style="min-width:110px">Livraison</th>
                                        <th class="text-center" style="min-width:60px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in history"
                                        :key="row.date"
                                        :class="{ 'row-empty': row.invoices_count === 0 }"
                                    >
                                        <td class="font-weight-medium">
                                            <a
                                                :href="buildVentesUrl(row.date)"
                                                target="_blank"
                                                rel="noopener"
                                                class="date-link"
                                            >
                                                {{ row.label }}
                                                <v-icon icon="mdi-open-in-new" size="12" class="ml-1 opacity-50" />
                                            </a>
                                        </td>
                                        <td class="text-center">
                                            <v-chip
                                                :color="row.invoices_count > 0 ? 'primary' : 'default'"
                                                size="x-small"
                                                variant="tonal"
                                            >
                                                {{ row.invoices_count }}
                                            </v-chip>
                                        </td>
                                        <td class="text-right font-weight-bold">
                                            <span :class="row.total_sales > 0 ? 'text-primary' : 'text-grey'">
                                                {{ formatAmount(row.total_sales) }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span :class="row.total_estimated_profit > 0 ? 'text-success' : 'text-grey'">
                                                {{ formatAmount(row.total_estimated_profit) }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span :class="row.total_realized_profit > 0 ? 'text-success' : 'text-grey'">
                                                {{ formatAmount(row.total_realized_profit) }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span :class="row.total_commissions > 0 ? 'text-orange' : 'text-grey'">
                                                {{ formatAmount(row.total_commissions) }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span :class="row.total_delivery_cost > 0 ? 'text-orange' : 'text-grey'">
                                                {{ formatAmount(row.total_delivery_cost) }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <v-tooltip text="Clients sans achat ce jour" location="left">
                                                <template #activator="{ props: tooltipProps }">
                                                    <v-btn
                                                        v-bind="tooltipProps"
                                                        icon="mdi-account-off-outline"
                                                        size="x-small"
                                                        variant="text"
                                                        color="warning"
                                                        @click="openLeftOutDialog(row)"
                                                    />
                                                </template>
                                            </v-tooltip>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-window-item>

                        <!-- Chart view -->
                        <v-window-item value="chart">
                            <div class="pa-4">
                                <!-- Series toggle chips -->
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <v-chip
                                        v-for="series in availableSeries"
                                        :key="series.key"
                                        :color="series.color"
                                        :variant="activeSeries.includes(series.key) ? 'flat' : 'outlined'"
                                        size="small"
                                        class="cursor-pointer"
                                        @click="toggleSeries(series.key)"
                                    >
                                        {{ series.label }}
                                    </v-chip>
                                </div>

                                <apexchart
                                    type="line"
                                    height="380"
                                    :options="chartOptions"
                                    :series="chartSeries"
                                />
                            </div>
                        </v-window-item>
                    </v-window>
                </div>

                <!-- Left-out customers dialog (nested) -->
                <v-dialog v-model="leftOutDialogOpen" max-width="520px" scrollable>
                    <v-card>
                        <v-card-title class="d-flex align-center justify-space-between pa-4 border-b">
                            <div>
                                <div class="text-subtitle-1 font-weight-bold">Clients sans achat</div>
                                <div v-if="leftOutDialogLabel" class="text-caption text-grey mt-1">
                                    {{ leftOutDialogLabel }}
                                </div>
                            </div>
                            <v-btn icon="mdi-close" variant="text" size="small" @click="leftOutDialogOpen = false" />
                        </v-card-title>

                        <v-card-text class="pa-0">
                            <div v-if="leftOutDialogLoading" class="d-flex justify-center align-center py-10">
                                <v-progress-circular indeterminate color="warning" />
                            </div>

                            <div v-else-if="leftOutDialogError" class="pa-6 text-center text-error">
                                <v-icon icon="mdi-alert-circle" size="36" class="mb-2" />
                                <p>Impossible de charger les données.</p>
                            </div>

                            <template v-else>
                                <div v-if="leftOutCustomers.length === 0" class="pa-10 text-center text-grey">
                                    <v-icon icon="mdi-account-check" size="40" class="mb-3" color="success" />
                                    <p>Tous les clients du beat ont acheté ce jour.</p>
                                </div>

                                <div v-else>
                                    <div class="pa-3 border-b d-flex align-center gap-2 bg-warning-lighten-5">
                                        <v-icon icon="mdi-account-off-outline" color="warning" size="18" />
                                        <span class="text-caption">
                                            {{ leftOutCustomers.length }} / {{ leftOutDialogTotalCustomers }} client(s) sans achat
                                        </span>
                                    </div>

                                    <v-list density="compact" border >
                                        <v-list-item
                                            v-for="customer in leftOutCustomers"
                                            :key="customer.id"
                                            :subtitle="customer.phone_number ?? 'Pas de téléphone'"
                                        >
                                            <template #title>
                                                <span class="font-weight-medium">{{ customer.name }}</span><br>
                                                <span class="font-weight-light">{{ customer.address }}</span>
                                            </template>
                                            <template #prepend>
                                                <v-icon icon="mdi-account-outline" color="grey" size="20" />
                                            </template>
                                        </v-list-item>
                                    </v-list>
                                </div>
                            </template>
                        </v-card-text>

                        <v-card-actions class="pa-3 border-t">
                            <v-btn
                                v-if="!leftOutDialogLoading && !leftOutDialogError"
                                color="warning"
                                variant="tonal"
                                size="small"
                                prepend-icon="mdi-file-pdf-box"
                                :href="leftOutPdfUrl"
                                target="_blank"
                                rel="noopener"
                            >
                                Exporter PDF
                            </v-btn>
                            <v-spacer />
                            <v-btn variant="text" size="small" @click="leftOutDialogOpen = false">Fermer</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
            </v-card-text>

            <v-card-actions class="pa-4 border-t">
                <span class="text-caption text-grey">
                    {{ history.length }} occurrence(s) affichée(s)
                </span>
                <v-spacer />
                <v-btn variant="text" @click="close">Fermer</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const apexchart = VueApexCharts;

const props = defineProps({
    modelValue: {
        type: Boolean,
        required: true,
    },
    beatId: {
        type: Number,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const loading = ref(false);
const error = ref(false);
const beat = ref(null);
const history = ref([]);
const activeTab = ref('table');

// ─── Left-out customers dialog ────────────────────────────────────────────────
const leftOutDialogOpen = ref(false);
const leftOutDialogLoading = ref(false);
const leftOutDialogError = ref(false);
const leftOutDialogLabel = ref('');
const leftOutDialogTotalCustomers = ref(0);
const leftOutCustomers = ref([]);

const leftOutSelectedDate = ref(null);

const leftOutPdfUrl = computed(() => {
    if (!leftOutSelectedDate.value) {
        return '#';
    }

    return route('beats.left-out-customers.pdf', props.beatId) + '?date=' + leftOutSelectedDate.value;
});

const openLeftOutDialog = async (row) => {
    leftOutDialogOpen.value = true;
    leftOutDialogLoading.value = true;
    leftOutDialogError.value = false;
    leftOutDialogLabel.value = row.label;
    leftOutSelectedDate.value = row.date;
    leftOutCustomers.value = [];
    leftOutDialogTotalCustomers.value = 0;

    try {
        const response = await axios.get(route('beats.left-out-customers', props.beatId), {
            params: { date: row.date },
        });
        leftOutCustomers.value = response.data.left_out_customers;
        leftOutDialogTotalCustomers.value = response.data.total_customers;
    } catch {
        leftOutDialogError.value = true;
    } finally {
        leftOutDialogLoading.value = false;
    }
};

const filterStartDate = ref(null);
const filterEndDate = ref(null);
const includeAllDaySales = ref(false);

const isDateFilterActive = computed(() => filterStartDate.value || filterEndDate.value);

// ─── Series toggle ────────────────────────────────────────────────────────────

const availableSeries = [
    { key: 'total_sales', label: 'Ventes', color: '#1565C0' },
    { key: 'total_estimated_profit', label: 'Profit estimé', color: '#2E7D32' },
    { key: 'total_realized_profit', label: 'Profit réalisé', color: '#1B5E20' },
    { key: 'total_commissions', label: 'Commissions', color: '#E65100' },
    { key: 'total_delivery_cost', label: 'Livraison', color: '#BF360C' },
];

const activeSeries = ref(['total_sales', 'total_estimated_profit', 'total_realized_profit']);

const toggleSeries = (seriesKey) => {
    if (activeSeries.value.includes(seriesKey)) {
        if (activeSeries.value.length > 1) {
            activeSeries.value = activeSeries.value.filter((k) => k !== seriesKey);
        }
    } else {
        activeSeries.value = [...activeSeries.value, seriesKey];
    }
};

// ─── Chart data ───────────────────────────────────────────────────────────────

// Dates in chronological order (oldest → newest) for the X-axis
const chronologicalHistory = computed(() => [...history.value].reverse());

const chartSeries = computed(() =>
    availableSeries
        .filter((s) => activeSeries.value.includes(s.key))
        .map((s) => ({
            name: s.label,
            color: s.color,
            data: chronologicalHistory.value.map((row) => ({
                x: row.date,
                y: row[s.key],
            })),
        }))
);

const chartOptions = computed(() => ({
    chart: {
        id: 'beat-history-chart',
        type: 'line',
        toolbar: { show: true, tools: { download: true, zoom: true, pan: true, reset: true } },
        animations: { enabled: true, speed: 400 },
        fontFamily: 'inherit',
    },
    stroke: {
        curve: 'smooth',
        width: 2.5,
    },
    markers: {
        size: 4,
        hover: { size: 6 },
    },
    xaxis: {
        type: 'datetime',
        labels: {
            format: 'dd MMM yy',
            style: { fontSize: '11px' },
        },
        tooltip: { enabled: false },
    },
    yaxis: {
        labels: {
            formatter: (value) => formatAmountShort(value),
            style: { fontSize: '11px' },
        },
    },
    tooltip: {
        shared: true,
        intersect: false,
        x: {
            formatter: (timestamp) => {
                const date = new Date(timestamp);
                return date.toLocaleDateString('fr-FR', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric',
                });
            },
        },
        y: {
            formatter: (value) => formatAmount(value),
        },
    },
    legend: {
        position: 'top',
        horizontalAlign: 'left',
        fontSize: '12px',
    },
    grid: {
        borderColor: '#e0e0e0',
        strokeDashArray: 4,
    },
    noData: {
        text: 'Aucune donnée',
        style: { fontSize: '14px', color: '#9e9e9e' },
    },
}));

// ─── Summaries ────────────────────────────────────────────────────────────────

const summaryCards = computed(() => {
    if (history.value.length === 0) {
        return [];
    }

    return [
        {
            label: 'Total ventes',
            value: history.value.reduce((sum, row) => sum + row.total_sales, 0),
            color: '#1565C0',
        },
        {
            label: 'Profit estimé',
            value: history.value.reduce((sum, row) => sum + row.total_estimated_profit, 0),
            color: '#2E7D32',
        },
        {
            label: 'Profit réalisé',
            value: history.value.reduce((sum, row) => sum + row.total_realized_profit, 0),
            color: '#1B5E20',
        },
        {
            label: 'Commissions',
            value: history.value.reduce((sum, row) => sum + row.total_commissions, 0),
            color: '#E65100',
        },
        {
            label: 'Coût livraison',
            value: history.value.reduce((sum, row) => sum + row.total_delivery_cost, 0),
            color: '#BF360C',
        },
    ];
});

// ─── Navigation ───────────────────────────────────────────────────────────────

const buildVentesUrl = (date) => {
    const params = new URLSearchParams({
        date,
        beat_id: String(props.beatId),
    });

    return route('ventes.index') + '?' + params.toString();
};

// ─── Formatters ───────────────────────────────────────────────────────────────

const formatAmount = (amount) => {
    if (!amount || amount === 0) {
        return '—';
    }

    return new Intl.NumberFormat('fr-FR').format(amount) + ' XOF';
};

const formatAmountShort = (amount) => {
    if (!amount || amount === 0) {
        return '0';
    }

    if (amount >= 1_000_000) {
        return (amount / 1_000_000).toFixed(1).replace('.0', '') + 'M';
    }

    if (amount >= 1_000) {
        return (amount / 1_000).toFixed(0) + 'k';
    }

    return String(amount);
};

// ─── Data loading ─────────────────────────────────────────────────────────────

const loadHistory = async () => {
    if (!props.beatId) {
        return;
    }

    loading.value = true;
    error.value = false;
    beat.value = null;
    history.value = [];

    try {
        const queryParams = {};
        if (filterStartDate.value) {
            queryParams.start_date = filterStartDate.value;
        }
        if (filterEndDate.value) {
            queryParams.end_date = filterEndDate.value;
        }
        if (includeAllDaySales.value) {
            queryParams.include_all_day_sales = 1;
        }

        const response = await axios.get(route('beats.history', props.beatId), { params: queryParams });
        beat.value = response.data.beat;
        history.value = response.data.history;
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
};

const resetDateFilter = () => {
    filterStartDate.value = buildDefaultStartDate();
    filterEndDate.value = buildDefaultEndDate();
    includeAllDaySales.value = false;
    loadHistory();
};

const close = () => {
    isOpen.value = false;
};

const buildDefaultStartDate = () => {
    const date = new Date();
    date.setDate(date.getDate() - 30);
    return date.toISOString().slice(0, 10);
};

const buildDefaultEndDate = () => {
    return new Date().toISOString().slice(0, 10);
};

watch(
    () => props.modelValue,
    (opened) => {
        if (opened) {
            activeTab.value = 'table';
            filterStartDate.value = buildDefaultStartDate();
            filterEndDate.value = buildDefaultEndDate();
            includeAllDaySales.value = false;
            loadHistory();
        }
    }
);
</script>

<style scoped>
.summary-card {
    min-width: 140px;
    padding: 8px 16px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    flex: 1;
}
.summary-value {
    font-size: 15px;
    font-weight: 700;
}
.summary-label {
    font-size: 11px;
    color: #9e9e9e;
    margin-top: 2px;
}
.row-empty td {
    opacity: 0.45;
}
.cursor-pointer {
    cursor: pointer;
}
.date-link {
    color: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}
.date-link:hover {
    color: #1565c0;
    text-decoration: underline;
}
</style>
