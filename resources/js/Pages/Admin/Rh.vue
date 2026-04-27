<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    commercials: Array,
    selectedCommercial: Object,
    workPeriods: Array,
    inventoryResults: Array,
    overdueInvoices: Array,
    penalties: Array,
    filters: Object,
});

const selectedCommercialId = ref(props.filters?.commercial_id ?? null);
const periodType = ref('month');
const selectedMonth = ref(new Date().toISOString().slice(0, 7));
const selectedWorkPeriodId = ref(null);

// ── Penalty dialog ────────────────────────────────────────────────────────────

const penaltyDialogOpen = ref(false);
const penaltyForm = ref({ amount: 0, reason: '', work_day: '', car_load_inventory_id: null, sales_invoice_id: null });
const penaltyFormErrors = ref({});
const penaltySubmitting = ref(false);

const openPenaltyDialog = (prefilledAmount, defaultWorkDay, sourceIds = {}) => {
    penaltyForm.value = {
        amount: Math.abs(prefilledAmount),
        reason: '',
        work_day: defaultWorkDay ?? new Date().toISOString().slice(0, 10),
        car_load_inventory_id: sourceIds.car_load_inventory_id ?? null,
        sales_invoice_id: sourceIds.sales_invoice_id ?? null,
    };
    penaltyFormErrors.value = {};
    penaltyDialogOpen.value = true;
};

const penaltiesForInvoice = (invoiceId) =>
    (props.penalties ?? []).filter((penalty) => penalty.sales_invoice_id === invoiceId);

const invoiceHasPenalty = (invoiceId) => penaltiesForInvoice(invoiceId).length > 0;

// ── Invoice multi-select ──────────────────────────────────────────────────────

const selectedInvoiceIds = ref([]);

const selectableInvoices = computed(() =>
    (props.overdueInvoices ?? []).filter((invoice) => !invoiceHasPenalty(invoice.id)),
);

const allInvoicesSelected = computed(
    () => selectableInvoices.value.length > 0 && selectedInvoiceIds.value.length === selectableInvoices.value.length,
);

const someInvoicesSelected = computed(
    () => selectedInvoiceIds.value.length > 0 && !allInvoicesSelected.value,
);

const selectedInvoicesTotalRemaining = computed(() =>
    (props.overdueInvoices ?? [])
        .filter((invoice) => selectedInvoiceIds.value.includes(invoice.id))
        .reduce((sum, invoice) => sum + invoice.total_remaining, 0),
);

const toggleInvoiceSelection = (invoiceId) => {
    if (invoiceHasPenalty(invoiceId)) return;
    const index = selectedInvoiceIds.value.indexOf(invoiceId);
    if (index === -1) {
        selectedInvoiceIds.value = [...selectedInvoiceIds.value, invoiceId];
    } else {
        selectedInvoiceIds.value = selectedInvoiceIds.value.filter((id) => id !== invoiceId);
    }
};

const toggleAllInvoices = () => {
    if (allInvoicesSelected.value) {
        selectedInvoiceIds.value = [];
    } else {
        selectedInvoiceIds.value = selectableInvoices.value.map((invoice) => invoice.id);
    }
};

// ── Payroll PDF ───────────────────────────────────────────────────────────────

const payrollDialogOpen = ref(false);
const payrollBaseSalary = ref(0);

const openPayrollDialog = () => {
    payrollBaseSalary.value = props.selectedCommercial?.salary ?? 0;
    payrollDialogOpen.value = true;
};

const generatePayrollPdf = () => {
    const params = new URLSearchParams({
        commercial_id: props.selectedCommercial.id,
        start_date: props.filters.start_date,
        end_date: props.filters.end_date,
        base_salary: payrollBaseSalary.value,
    });
    window.open(route('admin.rh.payroll.pdf') + '?' + params.toString(), '_blank');
    payrollDialogOpen.value = false;
};

// ── Penalty deletion ─────────────────────────────────────────────────────────

const deletePenaltyDialogOpen = ref(false);
const penaltyToDeleteId = ref(null);
const deleteSubmitting = ref(false);

const confirmDeletePenalty = (penaltyId) => {
    penaltyToDeleteId.value = penaltyId;
    deletePenaltyDialogOpen.value = true;
};

const submitDeletePenalty = () => {
    deleteSubmitting.value = true;

    router.delete(
        route('commissions.penalties.destroy', penaltyToDeleteId.value),
        {
            onSuccess: () => {
                deletePenaltyDialogOpen.value = false;
                router.get(route('admin.rh'), {
                    commercial_id: props.filters?.commercial_id,
                    start_date: props.filters?.start_date,
                    end_date: props.filters?.end_date,
                }, { preserveState: false });
            },
            onFinish: () => {
                deleteSubmitting.value = false;
            },
        },
    );
};

// ── Bulk penalty dialog ───────────────────────────────────────────────────────

const bulkPenaltyDialogOpen = ref(false);
const bulkWorkDay = ref('');
const bulkSubmitting = ref(false);
const bulkErrors = ref({});

const selectedInvoicesForBulk = computed(() =>
    (props.overdueInvoices ?? []).filter((invoice) => selectedInvoiceIds.value.includes(invoice.id)),
);

const openBulkPenaltyDialog = () => {
    bulkWorkDay.value = new Date().toISOString().slice(0, 10);
    bulkErrors.value = {};
    bulkPenaltyDialogOpen.value = true;
};

const submitBulkPenalties = () => {
    bulkErrors.value = {};
    bulkSubmitting.value = true;

    router.post(
        route('admin.rh.penalties.bulk'),
        {
            commercial_id: props.selectedCommercial.id,
            work_day: bulkWorkDay.value,
            invoice_ids: selectedInvoiceIds.value,
        },
        {
            onSuccess: () => {
                bulkPenaltyDialogOpen.value = false;
                selectedInvoiceIds.value = [];
                router.get(route('admin.rh'), {
                    commercial_id: props.filters?.commercial_id,
                    start_date: props.filters?.start_date,
                    end_date: props.filters?.end_date,
                }, { preserveState: false });
            },
            onError: (errors) => {
                bulkErrors.value = errors;
            },
            onFinish: () => {
                bulkSubmitting.value = false;
            },
        },
    );
};

const submitPenalty = () => {
    penaltyFormErrors.value = {};

    if (!penaltyForm.value.reason.trim()) {
        penaltyFormErrors.value.reason = 'La raison est obligatoire.';
        return;
    }
    if (penaltyForm.value.amount < 1) {
        penaltyFormErrors.value.amount = 'Le montant doit être supérieur à 0.';
        return;
    }

    penaltySubmitting.value = true;

    router.post(
        route('admin.rh.penalties.store'),
        {
            commercial_id: props.selectedCommercial.id,
            amount: penaltyForm.value.amount,
            reason: penaltyForm.value.reason,
            work_day: penaltyForm.value.work_day,
            car_load_inventory_id: penaltyForm.value.car_load_inventory_id,
            sales_invoice_id: penaltyForm.value.sales_invoice_id,
        },
        {
            onSuccess: () => {
                penaltyDialogOpen.value = false;
                // Reload the page with the same filters to refresh penalties list.
                router.get(route('admin.rh'), {
                    commercial_id: props.filters?.commercial_id,
                    start_date: props.filters?.start_date,
                    end_date: props.filters?.end_date,
                }, { preserveState: false });
            },
            onError: (errors) => {
                penaltyFormErrors.value = errors;
            },
            onFinish: () => {
                penaltySubmitting.value = false;
            },
        },
    );
};

// ── Filters ───────────────────────────────────────────────────────────────────

const formatAmount = (amount) => new Intl.NumberFormat('fr-FR').format(amount ?? 0) + ' F';

const formatDate = (dateString) => {
    if (!dateString) return '—';
    return new Date(dateString + 'T00:00:00').toLocaleDateString('fr-FR');
};

watch(selectedCommercialId, (newCommercialId) => {
    if (newCommercialId) {
        router.get(route('admin.rh'), { commercial_id: newCommercialId }, { preserveState: false });
    }
});

const computedMonthRange = computed(() => {
    if (!selectedMonth.value) return { start: null, end: null };
    const [year, month] = selectedMonth.value.split('-');
    const lastDay = new Date(Number(year), Number(month), 0).getDate();
    return {
        start: `${year}-${month}-01`,
        end: `${year}-${month}-${String(lastDay).padStart(2, '0')}`,
    };
});

const canApplyFilters = computed(() => {
    if (!selectedCommercialId.value) return false;
    if (periodType.value === 'month') return !!selectedMonth.value;
    return !!selectedWorkPeriodId.value;
});

const applyFilters = () => {
    if (!canApplyFilters.value) return;

    let startDate, endDate;

    if (periodType.value === 'month') {
        const range = computedMonthRange.value;
        startDate = range.start;
        endDate = range.end;
    } else {
        const period = props.workPeriods?.find((workPeriod) => workPeriod.id === selectedWorkPeriodId.value);
        if (!period) return;
        startDate = period.period_start_date;
        endDate = period.period_end_date;
    }

    router.get(route('admin.rh'), {
        commercial_id: selectedCommercialId.value,
        start_date: startDate,
        end_date: endDate,
    }, { preserveState: false });
};

const totalInventoryDeficit = computed(() =>
    (props.inventoryResults ?? [])
        .filter((inventoryResult) => inventoryResult.is_deficit)
        .reduce((sum, inventoryResult) => sum + Math.abs(inventoryResult.result_amount), 0)
);

const totalOverdueAmount = computed(() =>
    (props.overdueInvoices ?? []).reduce((sum, invoice) => sum + invoice.total_remaining, 0)
);

const totalPenaltiesAmount = computed(() =>
    (props.penalties ?? []).reduce((sum, penalty) => sum + penalty.amount, 0)
);

const hasData = computed(() => props.inventoryResults !== null);
</script>

<template>
    <Head title="RH — Réconciliation" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ressources Humaines — Réconciliation</h2>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Filters -->
                <v-card class="rounded-xl" elevation="2">
                    <v-card-title class="pa-5 pb-2">
                        <v-icon class="mr-2">mdi-filter-outline</v-icon>
                        Sélection du commercial et de la période
                    </v-card-title>
                    <v-card-text class="pa-5 pt-2">
                        <v-row align="center">
                            <v-col cols="12" md="4">
                                <v-select
                                    v-model="selectedCommercialId"
                                    :items="commercials"
                                    item-title="name"
                                    item-value="id"
                                    label="Commercial"
                                    variant="outlined"
                                    density="comfortable"
                                    clearable
                                />
                            </v-col>

                            <v-col cols="12" md="3">
                                <v-btn-toggle
                                    v-model="periodType"
                                    mandatory
                                    variant="outlined"
                                    color="primary"
                                    class="w-100"
                                    :disabled="!selectedCommercialId"
                                >
                                    <v-btn value="month" class="flex-grow-1">Mois</v-btn>
                                    <v-btn value="work_period" class="flex-grow-1">Période de travail</v-btn>
                                </v-btn-toggle>
                            </v-col>

                            <v-col cols="12" md="3">
                                <v-text-field
                                    v-if="periodType === 'month'"
                                    v-model="selectedMonth"
                                    type="month"
                                    label="Mois"
                                    variant="outlined"
                                    density="comfortable"
                                    :disabled="!selectedCommercialId"
                                />
                                <v-select
                                    v-else
                                    v-model="selectedWorkPeriodId"
                                    :items="workPeriods ?? []"
                                    item-value="id"
                                    :item-title="(workPeriod) => `${formatDate(workPeriod.period_start_date)} → ${formatDate(workPeriod.period_end_date)}`"
                                    label="Période de travail"
                                    variant="outlined"
                                    density="comfortable"
                                    :disabled="!selectedCommercial"
                                    no-data-text="Aucune période disponible"
                                />
                            </v-col>

                            <v-col cols="12" md="2">
                                <v-btn
                                    color="primary"
                                    variant="elevated"
                                    block
                                    :disabled="!canApplyFilters"
                                    @click="applyFilters"
                                >
                                    <v-icon class="mr-1">mdi-magnify</v-icon>
                                    Analyser
                                </v-btn>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <!-- Prompt when no commercial selected -->
                <v-alert v-if="!selectedCommercial" type="info" variant="tonal" class="rounded-xl">
                    Sélectionnez un commercial et une période pour afficher l'analyse de réconciliation.
                </v-alert>

                <!-- Prompt when commercial selected but period not applied yet -->
                <v-alert v-else-if="!hasData" type="info" variant="tonal" class="rounded-xl">
                    Choisissez une période et cliquez sur <strong>Analyser</strong> pour charger les données.
                </v-alert>

                <!-- Summary header -->
                <v-card v-if="selectedCommercial && hasData" class="rounded-xl" color="primary" elevation="3">
                    <v-card-text class="pa-5">
                        <div class="d-flex align-center justify-space-between flex-wrap gap-4">
                            <div>
                                <div class="text-overline text-white opacity-80">Analyse de réconciliation</div>
                                <div class="text-h5 font-weight-bold text-white">{{ selectedCommercial.name }}</div>
                                <div class="text-caption text-white opacity-70">
                                    Équipe : {{ selectedCommercial.team_name ?? '—' }} · Salaire de base : {{ formatAmount(selectedCommercial.salary) }}
                                </div>
                            </div>
                            <div class="d-flex flex-column align-end gap-3">
                                <v-btn
                                    color="white"
                                    variant="elevated"
                                    size="small"
                                    @click="openPayrollDialog"
                                >
                                    <v-icon size="16" class="mr-1">mdi-file-account-outline</v-icon>
                                    Générer fiche de paie
                                </v-btn>
                                <div class="d-flex gap-6 flex-wrap">
                                    <div class="text-center">
                                        <div class="text-caption text-white opacity-70">Déficit inventaire</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatAmount(totalInventoryDeficit) }}</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-caption text-white opacity-70">Factures en retard</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatAmount(totalOverdueAmount) }}</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-caption text-white opacity-70">Pénalités</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatAmount(totalPenaltiesAmount) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Inventory Results -->
                <template v-if="hasData">
                    <div class="text-subtitle-1 font-weight-medium text-grey-darken-2 px-1">
                        <v-icon size="18" class="mr-1">mdi-clipboard-list-outline</v-icon>
                        Résultats d'inventaire
                    </div>

                    <v-alert v-if="inventoryResults.length === 0" type="info" variant="tonal" class="rounded-xl">
                        Aucun inventaire clôturé trouvé pour cette période.
                    </v-alert>

                    <v-row v-else>
                        <v-col
                            v-for="inventoryResult in inventoryResults"
                            :key="inventoryResult.inventory_id"
                            cols="12"
                            md="6"
                        >
                            <v-card class="rounded-xl h-100" elevation="2">
                                <v-card-text class="pa-5">
                                    <div class="d-flex align-center gap-3 mb-3">
                                        <v-avatar
                                            :color="inventoryResult.is_deficit ? 'red-lighten-4' : inventoryResult.is_surplus ? 'green-lighten-4' : 'grey-lighten-3'"
                                            size="44"
                                        >
                                            <v-icon :color="inventoryResult.is_deficit ? 'red-darken-2' : inventoryResult.is_surplus ? 'green-darken-2' : 'grey-darken-1'">
                                                {{ inventoryResult.is_deficit ? 'mdi-alert-circle' : inventoryResult.is_surplus ? 'mdi-trending-up' : 'mdi-check-circle' }}
                                            </v-icon>
                                        </v-avatar>
                                        <div class="flex-grow-1">
                                            <div class="text-subtitle-2 font-weight-medium">{{ inventoryResult.inventory_name }}</div>
                                            <div class="text-caption text-grey">
                                                {{ inventoryResult.car_load_name }} · {{ formatDate(inventoryResult.load_date) }}
                                            </div>
                                        </div>
                                        <template v-if="inventoryResult.is_deficit">
                                            <div
                                                v-if="inventoryResult.linked_penalties.length"
                                                class="d-flex align-center gap-1"
                                            >
                                                <v-chip
                                                    color="green-darken-1"
                                                    size="small"
                                                    variant="tonal"
                                                    prepend-icon="mdi-check-circle"
                                                >
                                                    Pénalité appliquée
                                                </v-chip>
                                                <v-btn
                                                    v-for="linkedPenalty in inventoryResult.linked_penalties"
                                                    :key="linkedPenalty.id"
                                                    icon="mdi-delete-outline"
                                                    color="red"
                                                    variant="text"
                                                    size="x-small"
                                                    :title="`Supprimer la pénalité de ${formatAmount(linkedPenalty.amount)}`"
                                                    @click="confirmDeletePenalty(linkedPenalty.id)"
                                                />
                                            </div>
                                            <v-btn
                                                v-else
                                                color="red"
                                                variant="tonal"
                                                size="small"
                                                @click="openPenaltyDialog(inventoryResult.result_amount, new Date().toISOString().slice(0, 10), { car_load_inventory_id: inventoryResult.inventory_id })"
                                            >
                                                <v-icon size="16" class="mr-1">mdi-gavel</v-icon>
                                                Pénalité
                                            </v-btn>
                                        </template>
                                    </div>
                                    <div
                                        :class="[
                                            'text-h6 font-weight-bold',
                                            inventoryResult.is_deficit ? 'text-red-darken-2' : inventoryResult.is_surplus ? 'text-green-darken-2' : 'text-grey-darken-1',
                                        ]"
                                    >
                                        <span v-if="inventoryResult.is_deficit">
                                            Déficit : {{ formatAmount(Math.abs(inventoryResult.result_amount)) }}
                                        </span>
                                        <span v-else-if="inventoryResult.is_surplus">
                                            Surplus : {{ formatAmount(inventoryResult.result_amount) }}
                                        </span>
                                        <span v-else>Inventaire équilibré</span>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>

                    <!-- Overdue Invoices -->
                    <div class="d-flex align-center justify-space-between px-1 mt-2">
                        <div class="text-subtitle-1 font-weight-medium text-grey-darken-2">
                            <v-icon size="18" class="mr-1">mdi-file-clock-outline</v-icon>
                            Factures en retard de paiement
                        </div>
                        <v-btn
                            v-if="selectedInvoiceIds.length > 0"
                            color="red-darken-2"
                            variant="elevated"
                            size="small"
                            @click="openBulkPenaltyDialog"
                        >
                            <v-icon size="16" class="mr-1">mdi-gavel</v-icon>
                            Pénaliser {{ selectedInvoiceIds.length }} facture{{ selectedInvoiceIds.length > 1 ? 's' : '' }}
                            ({{ formatAmount(selectedInvoicesTotalRemaining) }})
                        </v-btn>
                    </div>

                    <v-alert v-if="overdueInvoices.length === 0" type="success" variant="tonal" class="rounded-xl">
                        Aucune facture en retard sur cette période.
                    </v-alert>

                    <v-card v-else class="rounded-xl" elevation="2">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th style="width: 40px">
                                        <v-checkbox
                                            :model-value="allInvoicesSelected"
                                            :indeterminate="someInvoicesSelected"
                                            :disabled="selectableInvoices.length === 0"
                                            density="compact"
                                            hide-details
                                            @update:model-value="toggleAllInvoices"
                                        />
                                    </th>
                                    <th>N° Facture</th>
                                    <th>Client</th>
                                    <th class="text-right">Montant total</th>
                                    <th class="text-right">Reste dû</th>
                                    <th>Échéance</th>
                                    <th class="text-center">Retard</th>
                                    <th class="text-center">Pénalités</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="invoice in overdueInvoices"
                                    :key="invoice.id"
                                    :class="{
                                        'bg-red-lighten-5': selectedInvoiceIds.includes(invoice.id),
                                        'bg-grey-lighten-4 opacity-70': invoiceHasPenalty(invoice.id),
                                    }"
                                    :style="invoiceHasPenalty(invoice.id) ? 'cursor: default' : 'cursor: pointer'"
                                    @click="toggleInvoiceSelection(invoice.id)"
                                >
                                    <td @click.stop>
                                        <v-checkbox
                                            :model-value="selectedInvoiceIds.includes(invoice.id)"
                                            :disabled="invoiceHasPenalty(invoice.id)"
                                            density="compact"
                                            hide-details
                                            @update:model-value="toggleInvoiceSelection(invoice.id)"
                                        />
                                    </td>
                                    <td class="font-weight-medium">{{ invoice.invoice_number }}</td>
                                    <td>{{ invoice.customer_name }}</td>
                                    <td class="text-right">{{ formatAmount(invoice.total_amount) }}</td>
                                    <td class="text-right font-weight-medium text-red-darken-2">
                                        {{ formatAmount(invoice.total_remaining) }}
                                    </td>
                                    <td>{{ formatDate(invoice.should_be_paid_at) }}</td>
                                    <td class="text-center">
                                        <v-chip color="red" size="x-small" variant="tonal">
                                            {{ invoice.days_overdue }}j
                                        </v-chip>
                                    </td>
                                    <td class="text-center" @click.stop>
                                        <!-- Already penalized: badge + delete buttons -->
                                        <div
                                            v-if="invoiceHasPenalty(invoice.id)"
                                            class="d-flex align-center justify-center gap-1"
                                        >
                                            <v-chip
                                                color="green-darken-1"
                                                size="x-small"
                                                variant="tonal"
                                                prepend-icon="mdi-check-circle"
                                            >
                                                Pénalité appliquée
                                            </v-chip>
                                            <v-btn
                                                v-for="linkedPenalty in penaltiesForInvoice(invoice.id)"
                                                :key="linkedPenalty.id"
                                                icon="mdi-delete-outline"
                                                color="red"
                                                variant="text"
                                                size="x-small"
                                                :title="`Supprimer la pénalité de ${formatAmount(linkedPenalty.amount)}`"
                                                @click="confirmDeletePenalty(linkedPenalty.id)"
                                            />
                                        </div>

                                        <!-- No penalty yet: show action button -->
                                        <v-btn
                                            v-else
                                            color="red"
                                            variant="tonal"
                                            size="x-small"
                                            @click="openPenaltyDialog(invoice.total_remaining, new Date().toISOString().slice(0, 10), { sales_invoice_id: invoice.id })"
                                        >
                                            <v-icon size="14" class="mr-1">mdi-gavel</v-icon>
                                            Pénalité
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="4">Total restant dû</td>
                                    <td class="text-right text-red-darken-2">{{ formatAmount(totalOverdueAmount) }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </v-table>
                    </v-card>

                    <!-- Penalties -->
                    <div class="text-subtitle-1 font-weight-medium text-grey-darken-2 px-1 mt-2">
                        <v-icon size="18" class="mr-1">mdi-gavel</v-icon>
                        Pénalités appliquées
                    </div>

                    <v-alert v-if="penalties.length === 0" type="info" variant="tonal" class="rounded-xl">
                        Aucune pénalité enregistrée sur cette période.
                    </v-alert>

                    <v-card v-else class="rounded-xl" elevation="2">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Raison</th>
                                    <th class="text-right">Montant</th>
                                    <th style="width: 48px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="penalty in penalties" :key="penalty.id">
                                    <td>{{ formatDate(penalty.work_day) }}</td>
                                    <td>{{ penalty.reason }}</td>
                                    <td class="text-right font-weight-medium text-red-darken-2">
                                        {{ formatAmount(penalty.amount) }}
                                    </td>
                                    <td class="text-center">
                                        <v-btn
                                            icon="mdi-delete-outline"
                                            color="red"
                                            variant="text"
                                            size="x-small"
                                            title="Supprimer la pénalité"
                                            @click="confirmDeletePenalty(penalty.id)"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="2">Total pénalités</td>
                                    <td class="text-right text-red-darken-2">{{ formatAmount(totalPenaltiesAmount) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </v-table>
                    </v-card>
                </template>

            </div>
        </div>

        <!-- Bulk penalty dialog -->
        <v-dialog v-model="bulkPenaltyDialogOpen" max-width="600" persistent>
            <v-card class="rounded-xl">
                <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
                    <v-icon color="red-darken-2">mdi-gavel</v-icon>
                    Pénaliser {{ selectedInvoicesForBulk.length }} facture{{ selectedInvoicesForBulk.length > 1 ? 's' : '' }}
                </v-card-title>
                <v-card-subtitle class="px-5 pb-3">
                    Commercial : <strong>{{ selectedCommercial?.name }}</strong>
                    · Total : <strong class="text-red-darken-2">{{ formatAmount(selectedInvoicesTotalRemaining) }}</strong>
                </v-card-subtitle>

                <v-divider />

                <v-card-text class="pa-5">
                    <v-text-field
                        v-model="bulkWorkDay"
                        label="Date d'application des pénalités"
                        type="date"
                        variant="outlined"
                        density="comfortable"
                        class="mb-4"
                        :error-messages="bulkErrors.work_day"
                    />

                    <div class="text-caption text-grey-darken-1 mb-2">
                        Les pénalités suivantes seront créées (une par facture) :
                    </div>

                    <v-list density="compact" class="rounded-lg border">
                        <v-list-item
                            v-for="invoice in selectedInvoicesForBulk"
                            :key="invoice.id"
                            class="py-2"
                        >
                            <template #prepend>
                                <v-icon size="16" color="red-darken-2" class="mr-2">mdi-file-document-outline</v-icon>
                            </template>
                            <v-list-item-title class="text-body-2">
                                Facture impayée du {{ formatDate(invoice.should_be_paid_at) }} pour client {{ invoice.customer_name }}
                            </v-list-item-title>
                            <template #append>
                                <span class="text-body-2 font-weight-medium text-red-darken-2">
                                    {{ formatAmount(invoice.total_remaining) }}
                                </span>
                            </template>
                        </v-list-item>
                    </v-list>

                    <v-alert v-if="bulkErrors.invoice_ids" type="error" variant="tonal" class="mt-3 rounded-lg" density="compact">
                        {{ bulkErrors.invoice_ids }}
                    </v-alert>
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-4 gap-2">
                    <v-spacer />
                    <v-btn variant="text" :disabled="bulkSubmitting" @click="bulkPenaltyDialogOpen = false">
                        Annuler
                    </v-btn>
                    <v-btn
                        color="red-darken-2"
                        variant="elevated"
                        :loading="bulkSubmitting"
                        @click="submitBulkPenalties"
                    >
                        <v-icon size="16" class="mr-1">mdi-check</v-icon>
                        Confirmer {{ selectedInvoicesForBulk.length }} pénalité{{ selectedInvoicesForBulk.length > 1 ? 's' : '' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Penalty creation dialog -->
        <v-dialog v-model="penaltyDialogOpen" max-width="480" persistent>
            <v-card class="rounded-xl">
                <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
                    <v-icon color="red-darken-2">mdi-gavel</v-icon>
                    Créer une pénalité
                </v-card-title>
                <v-card-subtitle class="px-5 pb-3">
                    Commercial : <strong>{{ selectedCommercial?.name }}</strong>
                </v-card-subtitle>

                <v-divider />

                <v-card-text class="pa-5">
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field
                                v-model="penaltyForm.reason"
                                label="Raison de la pénalité"
                                variant="outlined"
                                density="comfortable"
                                :error-messages="penaltyFormErrors.reason"
                                autofocus
                                counter="500"
                                maxlength="500"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model.number="penaltyForm.amount"
                                label="Montant (F)"
                                type="number"
                                min="1"
                                variant="outlined"
                                density="comfortable"
                                :error-messages="penaltyFormErrors.amount"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="penaltyForm.work_day"
                                label="Date d'application"
                                type="date"
                                variant="outlined"
                                density="comfortable"
                                :error-messages="penaltyFormErrors.work_day"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-4 gap-2">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="penaltySubmitting"
                        @click="penaltyDialogOpen = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="red-darken-2"
                        variant="elevated"
                        :loading="penaltySubmitting"
                        @click="submitPenalty"
                    >
                        <v-icon size="16" class="mr-1">mdi-check</v-icon>
                        Appliquer la pénalité
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Payroll PDF dialog -->
        <v-dialog v-model="payrollDialogOpen" max-width="440" persistent>
            <v-card class="rounded-xl">
                <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
                    <v-icon color="primary">mdi-file-account-outline</v-icon>
                    Générer la fiche de paie
                </v-card-title>
                <v-card-subtitle class="px-5 pb-3">
                    {{ selectedCommercial?.name }}
                    · {{ filters?.start_date ? formatDate(filters.start_date) : '' }}
                    → {{ filters?.end_date ? formatDate(filters.end_date) : '' }}
                </v-card-subtitle>

                <v-divider />

                <v-card-text class="pa-5">
                    <v-text-field
                        v-model.number="payrollBaseSalary"
                        label="Salaire de base (F CFA)"
                        type="number"
                        min="0"
                        variant="outlined"
                        density="comfortable"
                        hint="Modifiez si nécessaire avant de générer"
                        persistent-hint
                    />
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-4 gap-2">
                    <v-spacer />
                    <v-btn variant="text" @click="payrollDialogOpen = false">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        variant="elevated"
                        :disabled="payrollBaseSalary < 0"
                        @click="generatePayrollPdf"
                    >
                        <v-icon size="16" class="mr-1">mdi-open-in-new</v-icon>
                        Ouvrir le PDF
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete penalty confirmation dialog -->
        <v-dialog v-model="deletePenaltyDialogOpen" max-width="400" persistent>
            <v-card class="rounded-xl">
                <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
                    <v-icon color="red-darken-2">mdi-delete-outline</v-icon>
                    Supprimer la pénalité
                </v-card-title>
                <v-card-text class="pa-5 pt-2">
                    Cette action est irréversible. La commission du commercial sera recalculée automatiquement.
                </v-card-text>
                <v-divider />
                <v-card-actions class="pa-4 gap-2">
                    <v-spacer />
                    <v-btn variant="text" :disabled="deleteSubmitting" @click="deletePenaltyDialogOpen = false">
                        Annuler
                    </v-btn>
                    <v-btn
                        color="red-darken-2"
                        variant="elevated"
                        :loading="deleteSubmitting"
                        @click="submitDeletePenalty"
                    >
                        <v-icon size="16" class="mr-1">mdi-delete</v-icon>
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </AuthenticatedLayout>
</template>
