<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    carLoad: { type: Object, required: true },
    profitability: { type: Object, required: true },
    salesInvoices: { type: Array, required: true },
    fuelEntries: { type: Array, required: true },
});

// ─── Formatting helpers ───────────────────────────────────────────────────────

const formatCurrency = (amount) =>
    new Intl.NumberFormat('fr-FR', { style: 'decimal', maximumFractionDigits: 0 }).format(amount) + ' XOF';

const formatDate = (dateStr) =>
    dateStr
        ? new Date(dateStr).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
        : '—';

// ─── Status helpers ───────────────────────────────────────────────────────────

const carLoadStatusColor = computed(() => {
    const statusColorMap = {
        LOADING: 'warning',
        SELLING: 'success',
        ONGOING_INVENTORY: 'orange',
        FULL_INVENTORY: 'purple',
        TERMINATED_AND_TRANSFERRED: 'default',
    };
    return statusColorMap[props.carLoad.status] ?? 'default';
});

const carLoadStatusLabel = computed(() => {
    const statusLabelMap = {
        LOADING: 'En chargement',
        SELLING: 'En vente',
        ONGOING_INVENTORY: 'Inventaire en cours',
        FULL_INVENTORY: 'Inventaire terminé',
        TERMINATED_AND_TRANSFERRED: 'Terminé',
    };
    return statusLabelMap[props.carLoad.status] ?? 'Inconnu';
});

const invoiceStatusColor = (status) => {
    const invoiceStatusColorMap = {
        DRAFT: 'default',
        ISSUED: 'info',
        PARTIALLY_PAID: 'warning',
        FULLY_PAID: 'success',
    };
    return invoiceStatusColorMap[status] ?? 'default';
};

const invoiceStatusLabel = (status) => {
    const invoiceStatusLabelMap = {
        DRAFT: 'Brouillon',
        ISSUED: 'Émise',
        PARTIALLY_PAID: 'Partiellement payée',
        FULLY_PAID: 'Payée',
    };
    return invoiceStatusLabelMap[status] ?? status;
};

// ─── Computed totals for invoices ─────────────────────────────────────────────

const totalInvoicesRevenue = computed(() =>
    props.salesInvoices.reduce((sum, invoice) => sum + invoice.total_amount, 0),
);

const totalInvoicesPayments = computed(() =>
    props.salesInvoices.reduce((sum, invoice) => sum + invoice.total_payments, 0),
);

const totalInvoicesRemaining = computed(() =>
    props.salesInvoices.reduce((sum, invoice) => sum + invoice.total_remaining, 0),
);

const totalFuelCost = computed(() =>
    props.fuelEntries.reduce((sum, entry) => sum + entry.amount, 0),
);

// ─── Profit card color ────────────────────────────────────────────────────────

const netProfitColor = computed(() => (props.profitability.isDeficit ? 'error' : 'success'));

// ─── Invoices table headers ───────────────────────────────────────────────────

const invoiceTableHeaders = [
    { title: 'Client', key: 'customer_name' },
    { title: 'Date', key: 'created_at' },
    { title: 'Montant', key: 'total_amount', align: 'end' },
    { title: 'Payé', key: 'total_payments', align: 'end' },
    { title: 'Restant', key: 'total_remaining', align: 'end' },
    { title: 'Profit estimé', key: 'total_estimated_profit', align: 'end' },
    { title: 'Statut', key: 'status', align: 'center' },
];

const fuelTableHeaders = [
    { title: 'Date', key: 'filled_at' },
    { title: 'Montant', key: 'amount', align: 'end' },
    { title: 'Litres', key: 'liters', align: 'end' },
    { title: 'Notes', key: 'notes' },
];
</script>

<template>
    <Head :title="`Finances — ${carLoad.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link
                    :href="route('car-loads.show', carLoad.id)"
                    class="text-gray-500 hover:text-gray-700"
                >
                    <v-icon size="20">mdi-arrow-left</v-icon>
                </Link>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Finances — {{ carLoad.name }}
                </h2>
                <v-chip :color="carLoadStatusColor" size="small" variant="flat">
                    {{ carLoadStatusLabel }}
                </v-chip>
            </div>
        </template>

        <div class="py-4 sm:py-8">
            <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8 space-y-6">

                <!-- Car load context info -->
                <v-card variant="outlined">
                    <v-card-text class="pa-4">
                        <div class="flex flex-wrap gap-6 text-sm text-gray-600">
                            <div class="flex items-center gap-1">
                                <v-icon size="16" color="grey">mdi-account-group</v-icon>
                                <span>{{ carLoad.team?.name ?? '—' }}</span>
                            </div>
                            <div v-if="carLoad.vehicle" class="flex items-center gap-1">
                                <v-icon size="16" color="grey">mdi-truck</v-icon>
                                <span>
                                    {{ carLoad.vehicle.name }}
                                    <span v-if="carLoad.vehicle.plate_number" class="text-gray-400">
                                        ({{ carLoad.vehicle.plate_number }})
                                    </span>
                                </span>
                            </div>
                            <div class="flex items-center gap-1">
                                <v-icon size="16" color="grey">mdi-calendar-range</v-icon>
                                <span>
                                    {{ formatDate(carLoad.load_date) }} → {{ formatDate(carLoad.return_date) }}
                                </span>
                            </div>
                            <div v-if="!profitability.isMonthFinalized" class="flex items-center gap-1 text-orange-600">
                                <v-icon size="16" color="orange">mdi-alert-circle-outline</v-icon>
                                <span>Coûts fixes du mois non encore finalisés (estimations)</span>
                            </div>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- ── Deficit alert ── -->
                <v-alert
                    v-if="profitability.isDeficit"
                    type="error"
                    variant="tonal"
                    icon="mdi-alert"
                    prominent
                >
                    Ce chargement est en <strong>déficit</strong>. Le bénéfice net est négatif après déduction de tous les coûts.
                </v-alert>

                <!-- ── Revenue & Profit summary cards ── -->
                <div>
                    <div class="text-subtitle-1 font-semibold text-gray-700 mb-3">Revenus & Profits</div>
                    <v-row dense>
                        <v-col cols="12" sm="6" lg="3">
                            <v-card variant="tonal" color="primary" rounded="lg">
                                <v-card-text class="pa-4">
                                    <div class="text-caption text-medium-emphasis mb-1">Chiffre d'affaires</div>
                                    <div class="text-h6 font-weight-bold">{{ formatCurrency(profitability.totalRevenue) }}</div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="12" sm="6" lg="3">
                            <v-card variant="tonal" color="teal" rounded="lg">
                                <v-card-text class="pa-4">
                                    <div class="text-caption text-medium-emphasis mb-1">Encaissements reçus</div>
                                    <div class="text-h6 font-weight-bold">{{ formatCurrency(totalInvoicesPayments) }}</div>
                                    <div v-if="totalInvoicesRemaining > 0" class="text-caption text-medium-emphasis mt-1">
                                        Reste à percevoir : {{ formatCurrency(totalInvoicesRemaining) }}
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="12" sm="6" lg="3">
                            <v-card variant="tonal" color="success" rounded="lg">
                                <v-card-text class="pa-4">
                                    <div class="text-caption text-medium-emphasis mb-1">Marge brute</div>
                                    <div class="text-h6 font-weight-bold">{{ formatCurrency(profitability.totalGrossProfit) }}</div>
                                    <div class="text-caption text-medium-emphasis mt-1">
                                        {{ profitability.grossMarginPercent }}%
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="12" sm="6" lg="3">
                            <v-card variant="tonal" :color="netProfitColor" rounded="lg">
                                <v-card-text class="pa-4">
                                    <div class="text-caption text-medium-emphasis mb-1">Bénéfice net</div>
                                    <div class="text-h6 font-weight-bold">{{ formatCurrency(profitability.netProfit) }}</div>
                                    <div class="text-caption text-medium-emphasis mt-1">
                                        {{ profitability.netMarginPercent }}%
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </div>

                <!-- ── Cost breakdown ── -->
                <div>
                    <div class="text-subtitle-1 font-semibold text-gray-700 mb-3">Décomposition des coûts</div>
                    <v-row dense>
                        <!-- Vehicle costs -->
                        <v-col cols="12" md="6">
                            <v-card rounded="lg">
                                <v-card-title class="text-body-1 pa-4 pb-2">
                                    <v-icon class="mr-2" color="orange">mdi-truck</v-icon>
                                    Coûts véhicule
                                </v-card-title>
                                <v-divider />
                                <v-list density="compact" class="pa-0">
                                    <v-list-item>
                                        <template #title>
                                            <div>
                                                <span class="text-body-2">Coûts fixes véhicule</span>
                                                <div v-if="carLoad.fixed_daily_cost" class="text-caption text-medium-emphasis">
                                                    {{ formatCurrency(carLoad.fixed_daily_cost) }}/j × {{ profitability.tripDurationDays }} jours
                                                </div>
                                            </div>
                                        </template>
                                        <template #append>
                                            <span class="text-body-2 font-weight-medium">{{ formatCurrency(profitability.vehicleFixedCost) }}</span>
                                        </template>
                                    </v-list-item>
                                    <v-divider />
                                    <v-list-item>
                                        <template #title>
                                            <span class="text-body-2">Carburant réel</span>
                                        </template>
                                        <template #append>
                                            <span class="text-body-2 font-weight-medium">{{ formatCurrency(profitability.vehicleFuelCost) }}</span>
                                        </template>
                                    </v-list-item>
                                    <v-divider />
                                    <v-list-item color="orange">
                                        <template #title>
                                            <span class="text-body-2 font-weight-bold">Total coûts véhicule</span>
                                        </template>
                                        <template #append>
                                            <span class="text-body-2 font-weight-bold">{{ formatCurrency(profitability.totalVehicleCost) }}</span>
                                        </template>
                                    </v-list-item>
                                </v-list>
                            </v-card>
                        </v-col>

                        <!-- Fixed overhead allocations -->
                        <v-col cols="12" md="6">
                            <v-card rounded="lg">
                                <v-card-title class="text-body-1 pa-4 pb-2">
                                    <v-icon class="mr-2" color="deep-purple">mdi-office-building</v-icon>
                                    Charges fixes allouées
                                    <v-chip
                                        v-if="!profitability.isMonthFinalized"
                                        size="x-small"
                                        color="orange"
                                        variant="tonal"
                                        class="ml-2"
                                    >
                                        Estimées
                                    </v-chip>
                                </v-card-title>
                                <v-divider />
                                <v-list density="compact" class="pa-0">
                                    <v-list-item>
                                        <template #title>
                                            <span class="text-body-2">Stockage</span>
                                        </template>
                                        <template #append>
                                            <span class="text-body-2 font-weight-medium">{{ formatCurrency(profitability.storageAllocation) }}</span>
                                        </template>
                                    </v-list-item>
                                    <v-divider />
                                    <v-list-item>
                                        <template #title>
                                            <span class="text-body-2">Frais généraux</span>
                                        </template>
                                        <template #append>
                                            <span class="text-body-2 font-weight-medium">{{ formatCurrency(profitability.overheadAllocation) }}</span>
                                        </template>
                                    </v-list-item>
                                    <v-divider />
                                    <v-list-item>
                                        <template #title>
                                            <span class="text-body-2 font-weight-bold">Total charges fixes</span>
                                        </template>
                                        <template #append>
                                            <span class="text-body-2 font-weight-bold">
                                                {{ formatCurrency(profitability.storageAllocation + profitability.overheadAllocation) }}
                                            </span>
                                        </template>
                                    </v-list-item>
                                </v-list>
                            </v-card>
                        </v-col>

                        <!-- Total burden summary -->
                        <v-col cols="12">
                            <v-card rounded="lg" color="grey-lighten-4" variant="flat">
                                <v-card-text class="pa-4">
                                    <div class="flex flex-wrap justify-between items-center gap-4">
                                        <div>
                                            <div class="text-caption text-medium-emphasis">Total charges à absorber</div>
                                            <div class="text-h6 font-weight-bold">{{ formatCurrency(profitability.totalFixedCostBurden) }}</div>
                                        </div>
                                        <div class="text-center">
                                            <div class="text-caption text-medium-emphasis">Marge brute</div>
                                            <div class="text-h6 font-weight-bold text-success">{{ formatCurrency(profitability.totalGrossProfit) }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-caption text-medium-emphasis">Bénéfice net</div>
                                            <div
                                                class="text-h6 font-weight-bold"
                                                :class="profitability.isDeficit ? 'text-error' : 'text-success'"
                                            >
                                                {{ formatCurrency(profitability.netProfit) }}
                                            </div>
                                        </div>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </div>

                <!-- ── Break-even analysis ── -->
                <div v-if="profitability.totalRevenue > 0">
                    <div class="text-subtitle-1 font-semibold text-gray-700 mb-3">Seuil de rentabilité</div>
                    <v-card rounded="lg">
                        <v-card-text class="pa-4">
                            <v-row dense>
                                <v-col cols="12" sm="4">
                                    <div class="text-caption text-medium-emphasis mb-1">Chiffre d'affaires seuil</div>
                                    <div class="text-body-1 font-weight-bold">{{ formatCurrency(profitability.breakEvenRevenue) }}</div>
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <div class="text-caption text-medium-emphasis mb-1">CA réalisé</div>
                                    <div class="text-body-1 font-weight-bold">{{ formatCurrency(profitability.totalRevenue) }}</div>
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <div class="text-caption text-medium-emphasis mb-1">
                                        {{ profitability.remainingRevenueToBreakEven > 0 ? 'Manque à vendre' : 'Excédent au-dessus du seuil' }}
                                    </div>
                                    <div
                                        class="text-body-1 font-weight-bold"
                                        :class="profitability.remainingRevenueToBreakEven > 0 ? 'text-error' : 'text-success'"
                                    >
                                        {{ formatCurrency(profitability.remainingRevenueToBreakEven > 0 ? profitability.remainingRevenueToBreakEven : profitability.totalRevenue - profitability.breakEvenRevenue) }}
                                    </div>
                                </v-col>
                            </v-row>

                            <!-- Progress bar towards break-even -->
                            <div class="mt-4">
                                <div class="text-caption text-medium-emphasis mb-1">
                                    Progression vers le seuil de rentabilité
                                </div>
                                <v-progress-linear
                                    :model-value="Math.min(100, Math.round(profitability.totalRevenue / profitability.breakEvenRevenue * 100))"
                                    :color="profitability.isDeficit ? 'error' : 'success'"
                                    height="12"
                                    rounded
                                    bg-color="grey-lighten-3"
                                >
                                    <template #default="{ value }">
                                        <span class="text-caption font-weight-bold">{{ value }}%</span>
                                    </template>
                                </v-progress-linear>
                            </div>
                        </v-card-text>
                    </v-card>
                </div>

                <!-- ── Sales invoices ── -->
                <div>
                    <div class="text-subtitle-1 font-semibold text-gray-700 mb-3">
                        Factures de vente
                        <span class="text-caption font-normal text-gray-400 ml-2">({{ salesInvoices.length }})</span>
                    </div>

                    <v-card v-if="salesInvoices.length === 0" variant="outlined" rounded="lg">
                        <v-card-text class="text-center py-8 text-grey">
                            <v-icon size="40" color="grey-lighten-2">mdi-receipt-text-outline</v-icon>
                            <div class="mt-2">Aucune facture pour ce chargement</div>
                        </v-card-text>
                    </v-card>

                    <v-card v-else rounded="lg">
                        <v-data-table
                            :headers="invoiceTableHeaders"
                            :items="salesInvoices"
                            :items-per-page="25"
                            density="comfortable"
                            class="elevation-0"
                        >
                            <template #item.created_at="{ item }">
                                {{ formatDate(item.created_at) }}
                            </template>
                            <template #item.total_amount="{ item }">
                                {{ formatCurrency(item.total_amount) }}
                            </template>
                            <template #item.total_payments="{ item }">
                                {{ formatCurrency(item.total_payments) }}
                            </template>
                            <template #item.total_remaining="{ item }">
                                <span :class="item.total_remaining > 0 ? 'text-error' : 'text-success'">
                                    {{ formatCurrency(item.total_remaining) }}
                                </span>
                            </template>
                            <template #item.total_estimated_profit="{ item }">
                                {{ formatCurrency(item.total_estimated_profit) }}
                            </template>
                            <template #item.status="{ item }">
                                <v-chip
                                    :color="invoiceStatusColor(item.status)"
                                    size="small"
                                    variant="flat"
                                >
                                    {{ invoiceStatusLabel(item.status) }}
                                </v-chip>
                            </template>

                            <!-- Summary footer row -->
                            <template #bottom>
                                <div class="pa-3 border-t">
                                    <div class="flex flex-wrap gap-6 text-sm font-medium">
                                        <span>Total CA : <strong>{{ formatCurrency(totalInvoicesRevenue) }}</strong></span>
                                        <span>Encaissé : <strong class="text-success">{{ formatCurrency(totalInvoicesPayments) }}</strong></span>
                                        <span v-if="totalInvoicesRemaining > 0">
                                            Restant : <strong class="text-error">{{ formatCurrency(totalInvoicesRemaining) }}</strong>
                                        </span>
                                    </div>
                                </div>
                            </template>
                        </v-data-table>
                    </v-card>
                </div>

                <!-- ── Fuel entries ── -->
                <div v-if="fuelEntries.length > 0">
                    <div class="text-subtitle-1 font-semibold text-gray-700 mb-3">
                        Dépenses carburant
                        <span class="text-caption font-normal text-gray-400 ml-2">({{ fuelEntries.length }} entrées)</span>
                    </div>
                    <v-card rounded="lg">
                        <v-data-table
                            :headers="fuelTableHeaders"
                            :items="fuelEntries"
                            :items-per-page="-1"
                            density="comfortable"
                            class="elevation-0"
                            hide-default-footer
                        >
                            <template #item.filled_at="{ item }">
                                {{ formatDate(item.filled_at) }}
                            </template>
                            <template #item.amount="{ item }">
                                {{ formatCurrency(item.amount) }}
                            </template>
                            <template #item.liters="{ item }">
                                {{ item.liters != null ? `${item.liters} L` : '—' }}
                            </template>
                            <template #item.notes="{ item }">
                                <span class="text-grey">{{ item.notes || '—' }}</span>
                            </template>

                            <template #bottom>
                                <div class="pa-3 border-t">
                                    <span class="text-sm font-medium">
                                        Total carburant : <strong>{{ formatCurrency(totalFuelCost) }}</strong>
                                    </span>
                                </div>
                            </template>
                        </v-data-table>
                    </v-card>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
