<script>
// Module-scope definition so defineProps() (which is hoisted) can reference it.
// Single source of truth for the stats object shape — reused by all four period props.
const emptyStats = () => ({
    total_customers: 0,
    total_prospects: 0,
    total_confirmed_customers: 0,
    sales_invoices_count: 0,
    fully_paid_sales_invoices_count: 0,
    partially_paid_sales_invoices_count: 0,
    unpaid_sales_invoices_count: 0,
    total_sales: 0,
    total_estimated_profit: 0,
    total_realized_profit: 0,
    total_payments_received: 0,
    total_expenses: 0,
});
</script>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const activePeriodTab = ref('daily');
const menu = ref(false);
const datePickerKey = ref(0);

const props = defineProps({
    dailyStats:   { type: Object, default: emptyStats },
    weeklyStats:  { type: Object, default: emptyStats },
    monthlyStats: { type: Object, default: emptyStats },
    overallStats: { type: Object, default: emptyStats },
    selectedDate: { type: String, required: true },
});

const date = ref(props.selectedDate);

const dateAsObject = computed(() => {
    const [year, month, day] = date.value.split('-').map(Number);
    return new Date(year, month - 1, day);
});

const formattedDate = computed(() => {
    try {
        return new Date(date.value).toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    } catch (e) {
        return new Date().toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    }
});

const today = computed(() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
});

const currentPeriodStats = computed(() => {
    if (activePeriodTab.value === 'daily') return props.dailyStats;
    if (activePeriodTab.value === 'weekly') return props.weeklyStats;
    return props.monthlyStats;
});

const currentPeriodLabel = computed(() => {
    if (activePeriodTab.value === 'daily') {
        return `Aujourd'hui — ${formattedDate.value}`;
    }
    if (activePeriodTab.value === 'weekly') {
        return `Semaine du ${formattedDate.value}`;
    }
    return new Date(date.value).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
});

const handleDateChange = (newDate) => {
    if (newDate) {
        const formattedNewDate = new Date(newDate).toISOString().split('T')[0];
        date.value = formattedNewDate;
        menu.value = false;
        router.get(route('dashboard'), { date: formattedNewDate }, {
            preserveState: true,
            preserveScroll: true,
            only: ['dailyStats', 'weeklyStats', 'monthlyStats', 'selectedDate'],
        });
    }
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
    }).format(amount);
};

const periodTabs = [
    { value: 'daily',   label: "Aujourd'hui", icon: 'mdi-calendar-today' },
    { value: 'weekly',  label: 'Semaine',      icon: 'mdi-calendar-week'  },
    { value: 'monthly', label: 'Mois',         icon: 'mdi-calendar-month' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 leading-tight">Tableau de bord</h2>
                    <p class="text-sm text-gray-500 mt-0.5">Vue globale des performances commerciales</p>
                </div>

                <v-menu
                    v-model="menu"
                    :close-on-content-click="false"
                    min-width="auto"
                    transition="scale-transition"
                >
                    <template v-slot:activator="{ props: menuActivatorProps }">
                        <v-btn
                            color="primary"
                            v-bind="menuActivatorProps"
                            prepend-icon="mdi-calendar"
                            variant="elevated"
                            class="w-full sm:w-auto date-picker-btn"
                        >
                            {{ formattedDate }}
                        </v-btn>
                    </template>

                    <v-card elevation="8" class="rounded-xl overflow-hidden">
                        <v-card-text class="pa-2">
                            <v-date-picker
                                :key="datePickerKey"
                                :model-value="dateAsObject"
                                :max="today"
                                :first-day-of-week="1"
                                locale="fr"
                                @update:model-value="handleDateChange"
                                color="primary"
                                elevation="0"
                            />
                        </v-card-text>
                    </v-card>
                </v-menu>
            </div>
        </template>

        <div class="py-8 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto space-y-10">

                <!-- ===== OVERALL STATS SECTION ===== -->
                <section>
                    <div class="section-heading">
                        <span class="section-heading-accent"></span>
                        <span class="section-heading-text">Vue d'ensemble</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">

                        <!-- Clients -->
                        <div class="kpi-card kpi-card--blue">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--blue">
                                    <v-icon size="22">mdi-account-group</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">CLIENTS</div>
                                    <div class="kpi-value">{{ overallStats.total_customers }}</div>
                                    <div class="kpi-badges">
                                        <span class="kpi-badge kpi-badge--green">
                                            {{ overallStats.total_confirmed_customers }} confirmés
                                        </span>
                                        <span class="kpi-badge kpi-badge--amber">
                                            {{ overallStats.total_prospects }} prospects
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Factures -->
                        <div class="kpi-card kpi-card--indigo">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--indigo">
                                    <v-icon size="22">mdi-file-document-outline</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">FACTURES</div>
                                    <div class="kpi-value">{{ overallStats.sales_invoices_count }}</div>
                                    <div class="kpi-badges">
                                        <span class="kpi-badge kpi-badge--green">✓ {{ overallStats.fully_paid_sales_invoices_count }}</span>
                                        <span class="kpi-badge kpi-badge--amber">⚠ {{ overallStats.partially_paid_sales_invoices_count }}</span>
                                        <span class="kpi-badge kpi-badge--red">✗ {{ overallStats.unpaid_sales_invoices_count }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Chiffre d'affaires -->
                        <div class="kpi-card kpi-card--emerald">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--emerald">
                                    <v-icon size="22">mdi-cart-outline</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">CHIFFRE D'AFFAIRES</div>
                                    <div class="kpi-value kpi-value--currency kpi-value--emerald">
                                        {{ formatCurrency(overallStats.total_sales) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bénéfice estimé -->
                        <div class="kpi-card kpi-card--green">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--green">
                                    <v-icon size="22">mdi-trending-up</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">BÉNÉFICE ESTIMÉ</div>
                                    <div class="kpi-value kpi-value--currency kpi-value--green">
                                        {{ formatCurrency(overallStats.total_estimated_profit) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bénéfice réalisé -->
                        <div class="kpi-card kpi-card--teal">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--teal">
                                    <v-icon size="22">mdi-check-circle-outline</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">BÉNÉFICE RÉALISÉ</div>
                                    <div class="kpi-value kpi-value--currency kpi-value--teal">
                                        {{ formatCurrency(overallStats.total_realized_profit) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Encaissements -->
                        <div class="kpi-card kpi-card--amber">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--amber">
                                    <v-icon size="22">mdi-cash-multiple</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">ENCAISSEMENTS</div>
                                    <div class="kpi-value kpi-value--currency kpi-value--amber">
                                        {{ formatCurrency(overallStats.total_payments_received) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Dépenses -->
                        <div class="kpi-card kpi-card--red">
                            <div class="kpi-card-body">
                                <div class="kpi-icon kpi-icon--red">
                                    <v-icon size="22">mdi-cash-minus</v-icon>
                                </div>
                                <div class="kpi-content">
                                    <div class="kpi-label">DÉPENSES</div>
                                    <div class="kpi-value kpi-value--currency kpi-value--red">
                                        {{ formatCurrency(overallStats.total_expenses) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </section>

                <!-- ===== PERIOD STATS SECTION ===== -->
                <section>
                    <div class="section-heading">
                        <span class="section-heading-accent"></span>
                        <span class="section-heading-text">Analyse par période</span>
                    </div>

                    <!-- Period tab switcher -->
                    <div class="period-tabs-wrapper">
                        <div class="period-tabs">
                            <button
                                v-for="periodTab in periodTabs"
                                :key="periodTab.value"
                                class="period-tab"
                                :class="{ 'period-tab--active': activePeriodTab === periodTab.value }"
                                @click="activePeriodTab = periodTab.value"
                            >
                                <v-icon size="15" class="period-tab-icon">{{ periodTab.icon }}</v-icon>
                                {{ periodTab.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Period stats card -->
                    <div class="period-card">

                        <!-- Period card header -->
                        <div class="period-card-header">
                            <v-icon size="16" class="mr-2" style="color: #6366f1">mdi-chart-bar</v-icon>
                            <span class="period-card-header-label">{{ currentPeriodLabel }}</span>
                        </div>

                        <!-- Period stats content -->
                        <div class="period-card-content">

                            <!-- Row 1: Clients & Invoices -->
                            <div class="period-row">

                                <!-- Customers -->
                                <div class="period-group">
                                    <div class="period-group-title">
                                        <v-icon size="16" style="color: #3b82f6">mdi-account-group</v-icon>
                                        Clients
                                    </div>
                                    <div class="period-metrics-grid period-metrics-grid--3">
                                        <div class="period-metric">
                                            <div class="period-metric-value">{{ currentPeriodStats.total_customers }}</div>
                                            <div class="period-metric-label">Total</div>
                                        </div>
                                        <div class="period-metric">
                                            <div class="period-metric-value period-metric-value--green">{{ currentPeriodStats.total_confirmed_customers }}</div>
                                            <div class="period-metric-label">Confirmés</div>
                                        </div>
                                        <div class="period-metric">
                                            <div class="period-metric-value period-metric-value--amber">{{ currentPeriodStats.total_prospects }}</div>
                                            <div class="period-metric-label">Prospects</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Invoices -->
                                <div class="period-group">
                                    <div class="period-group-title">
                                        <v-icon size="16" style="color: #6366f1">mdi-file-document-outline</v-icon>
                                        Factures
                                    </div>
                                    <div class="period-metrics-grid period-metrics-grid--4">
                                        <div class="period-metric">
                                            <div class="period-metric-value">{{ currentPeriodStats.sales_invoices_count }}</div>
                                            <div class="period-metric-label">Total</div>
                                        </div>
                                        <div class="period-metric">
                                            <div class="period-metric-value period-metric-value--green">{{ currentPeriodStats.fully_paid_sales_invoices_count }}</div>
                                            <div class="period-metric-label">Soldées</div>
                                        </div>
                                        <div class="period-metric">
                                            <div class="period-metric-value period-metric-value--amber">{{ currentPeriodStats.partially_paid_sales_invoices_count }}</div>
                                            <div class="period-metric-label">Partielles</div>
                                        </div>
                                        <div class="period-metric">
                                            <div class="period-metric-value period-metric-value--red">{{ currentPeriodStats.unpaid_sales_invoices_count }}</div>
                                            <div class="period-metric-label">Impayées</div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="period-divider"></div>

                            <!-- Row 2: Financials -->
                            <div class="period-group">
                                <div class="period-group-title">
                                    <v-icon size="16" style="color: #10b981">mdi-cash</v-icon>
                                    Finances
                                </div>
                                <div class="period-metrics-grid period-metrics-grid--5">
                                    <div class="period-metric">
                                        <div class="period-metric-value period-metric-value--currency">{{ formatCurrency(currentPeriodStats.total_sales) }}</div>
                                        <div class="period-metric-label">Chiffre d'affaires</div>
                                    </div>
                                    <div class="period-metric">
                                        <div class="period-metric-value period-metric-value--currency period-metric-value--amber">{{ formatCurrency(currentPeriodStats.total_payments_received) }}</div>
                                        <div class="period-metric-label">Encaissements</div>
                                    </div>
                                    <div class="period-metric">
                                        <div class="period-metric-value period-metric-value--currency period-metric-value--green">{{ formatCurrency(currentPeriodStats.total_estimated_profit) }}</div>
                                        <div class="period-metric-label">Bénéfice estimé</div>
                                    </div>
                                    <div class="period-metric">
                                        <div class="period-metric-value period-metric-value--currency period-metric-value--teal">{{ formatCurrency(currentPeriodStats.total_realized_profit) }}</div>
                                        <div class="period-metric-label">Bénéfice réalisé</div>
                                    </div>
                                    <div class="period-metric">
                                        <div class="period-metric-value period-metric-value--currency period-metric-value--red">{{ formatCurrency(currentPeriodStats.total_expenses) }}</div>
                                        <div class="period-metric-label">Dépenses</div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* =====================
   SECTION HEADINGS
   ===================== */
.section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
}

.section-heading-accent {
    width: 4px;
    height: 22px;
    background: linear-gradient(180deg, #6366f1, #8b5cf6);
    border-radius: 4px;
    flex-shrink: 0;
}

.section-heading-text {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 1px;
    color: #6b7280;
    text-transform: uppercase;
}

/* =====================
   KPI CARDS (OVERALL)
   ===================== */
.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 0;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    border-left: 4px solid transparent;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
    overflow: hidden;
}

.kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

.kpi-card--blue    { border-left-color: #3b82f6; }
.kpi-card--indigo  { border-left-color: #6366f1; }
.kpi-card--emerald { border-left-color: #10b981; }
.kpi-card--green   { border-left-color: #22c55e; }
.kpi-card--teal    { border-left-color: #14b8a6; }
.kpi-card--amber   { border-left-color: #f59e0b; }
.kpi-card--red     { border-left-color: #ef4444; }

.kpi-card-body {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 18px 18px 18px 16px;
}

/* KPI Icons */
.kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.kpi-icon--blue    { background: #eff6ff; color: #2563eb; }
.kpi-icon--indigo  { background: #eef2ff; color: #4f46e5; }
.kpi-icon--emerald { background: #ecfdf5; color: #059669; }
.kpi-icon--green   { background: #f0fdf4; color: #16a34a; }
.kpi-icon--teal    { background: #f0fdfa; color: #0d9488; }
.kpi-icon--amber   { background: #fffbeb; color: #d97706; }
.kpi-icon--red     { background: #fef2f2; color: #dc2626; }

/* KPI Content */
.kpi-content {
    flex: 1;
    min-width: 0;
}

.kpi-label {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.8px;
    color: #9ca3af;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.kpi-value {
    font-size: 2rem;
    font-weight: 800;
    color: #1f2937;
    line-height: 1.1;
    margin-bottom: 8px;
}

.kpi-value--currency {
    font-size: 1.15rem;
    font-weight: 700;
}

.kpi-value--emerald { color: #047857; }
.kpi-value--green   { color: #15803d; }
.kpi-value--teal    { color: #0f766e; }
.kpi-value--amber   { color: #b45309; }
.kpi-value--red     { color: #b91c1c; }

/* KPI Badges */
.kpi-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.kpi-badge {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
    white-space: nowrap;
}

.kpi-badge--green { background: #dcfce7; color: #166534; }
.kpi-badge--amber { background: #fef3c7; color: #92400e; }
.kpi-badge--red   { background: #fee2e2; color: #991b1b; }

/* =====================
   PERIOD TAB SWITCHER
   ===================== */
.period-tabs-wrapper {
    margin-bottom: 18px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.period-tabs {
    display: inline-flex;
    gap: 3px;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 10px;
}

.period-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border-radius: 7px;
    font-size: 13.5px;
    font-weight: 500;
    color: #6b7280;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: all 0.18s ease;
    white-space: nowrap;
    flex-shrink: 0;
}

.period-tab:hover:not(.period-tab--active) {
    color: #374151;
    background: rgba(255, 255, 255, 0.6);
}

.period-tab--active {
    background: #ffffff;
    color: #4f46e5;
    font-weight: 600;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.period-tab-icon {
    opacity: 0.8;
}

/* =====================
   PERIOD STATS CARD
   ===================== */
.period-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.period-card-header {
    display: flex;
    align-items: center;
    padding: 14px 24px;
    background: linear-gradient(135deg, #f8f9ff 0%, #eef2ff 100%);
    border-bottom: 1px solid #e0e7ff;
}

.period-card-header-label {
    font-size: 13.5px;
    font-weight: 600;
    color: #4f46e5;
    text-transform: capitalize;
}

.period-card-content {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.period-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .period-row {
        grid-template-columns: 1fr 1.5fr;
    }
}

.period-divider {
    height: 1px;
    background: #f1f5f9;
    margin: 22px 0;
}

/* Period Groups */
.period-group-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f3f4f6;
}

/* Period Metrics Grid */
.period-metrics-grid {
    display: grid;
    gap: 8px;
}

.period-metrics-grid--3 {
    grid-template-columns: repeat(3, 1fr);
}

.period-metrics-grid--4 {
    grid-template-columns: repeat(2, 1fr);
}

.period-metrics-grid--5 {
    grid-template-columns: repeat(2, 1fr);
}

@media (min-width: 480px) {
    .period-metrics-grid--4 {
        grid-template-columns: repeat(4, 1fr);
    }
    .period-metrics-grid--5 {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 900px) {
    .period-metrics-grid--5 {
        grid-template-columns: repeat(5, 1fr);
    }
}

/* Period Metric Cell */
.period-metric {
    text-align: center;
    padding: 12px 8px;
    border-radius: 8px;
    background: #f9fafb;
    transition: background 0.15s ease;
}

.period-metric:hover {
    background: #f1f5f9;
}

.period-metric-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #1f2937;
    line-height: 1.2;
    margin-bottom: 4px;
}

.period-metric-value--currency {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f2937;
}

.period-metric-value--green  { color: #16a34a; }
.period-metric-value--amber  { color: #d97706; }
.period-metric-value--red    { color: #dc2626; }
.period-metric-value--teal   { color: #0d9488; }

.period-metric-label {
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #9ca3af;
}

/* =====================
   RESPONSIVE
   ===================== */
@media (max-width: 639px) {
    .period-metrics-grid--3 {
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
    }

    .period-metric {
        padding: 10px 4px;
    }

    .period-metric-value {
        font-size: 1.25rem;
    }

    .kpi-value {
        font-size: 1.6rem;
    }

    .kpi-value--currency {
        font-size: 1rem;
    }

    .period-card-content {
        padding: 16px;
    }
}

/* =====================
   DATE PICKER BUTTON
   ===================== */
.date-picker-btn {
    letter-spacing: 0;
}
</style>
