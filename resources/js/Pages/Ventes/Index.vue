<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    invoices: Object,
    clients: Array,
    commerciaux: Array,
    filters: Object,
    statistics: Object,
    payments: Object
});

// Add console log to debug payments data
console.log('Payments data:', props.payments);

const filterDialog = ref(false);
const deleteDialog = ref(false);
const invoiceToDelete = ref(null);
const currentPage = ref(1);

// Invoice items dialog
const itemsDialog = ref(false);
const selectedInvoice = ref(null);
const invoiceItems = ref([]);
const loadingItems = ref(false);

// Editable profit functionality
const editingItemId = ref(null);
const editingProfit = ref(0);
const savingProfit = ref(false);

const filterForm = useForm({
    date_debut: props.filters?.date_debut || '',
    date_fin: props.filters?.date_fin || '',
    paid_status: props.filters?.paid_status || '',
    commercial_id: props.filters?.commercial_id || '',
});

const applyFilters = () => {
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            filterDialog.value = false;
        },
    });
};

const applyPaidFilter = () => {
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

watch(() => filterForm.paid_status, (newValue) => {
    applyPaidFilter();
});

const formatPrice = (price) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(price);
};

const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('fr-FR');
};

const formatNumber = (number) => {
    return new Intl.NumberFormat('fr-FR').format(number || 0);
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', { 
        style: 'currency', 
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
};

const confirmDelete = (invoice) => {
    invoiceToDelete.value = invoice;
    deleteDialog.value = true;
};

const deleteInvoice = () => {
    // TODO: Implement delete logic
    console.log('Delete invoice:', invoiceToDelete.value);
    deleteDialog.value = false;
    invoiceToDelete.value = null;
};

const showInvoice = async (invoice) => {
    selectedInvoice.value = invoice;
    loadingItems.value = true;
    itemsDialog.value = true;

    try {
        // Fetch invoice details with items
        const response = await fetch(route('sales-invoices.show', invoice.id), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (response.ok) {
            const data = await response.json();
            invoiceItems.value = data.props.invoice.items || [];
        } else {
            console.error('Failed to fetch invoice items');
            invoiceItems.value = [];
        }
    } catch (error) {
        console.error('Error fetching invoice items:', error);
        invoiceItems.value = [];
    } finally {
        loadingItems.value = false;
    }
};

const editInvoice = (invoice) => {
    // TODO: Implement edit logic
    console.log('Edit invoice:', invoice);
};

// Profit editing methods
const startEditingProfit = (item) => {
    editingItemId.value = item.id;
    editingProfit.value = item.profit;
};

const cancelEditingProfit = () => {
    editingItemId.value = null;
    editingProfit.value = 0;
};

const saveProfit = async (item) => {
    if (savingProfit.value) return;

    savingProfit.value = true;

    try {
        // Get CSRF token safely
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                         document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                         '';
        
        const response = await fetch(`/sales-invoices/${selectedInvoice.value.id}/items/${item.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                profit: editingProfit.value
            })
        });

        if (response.ok) {
            const data = await response.json();

            // Update the item in the local array
            const itemIndex = invoiceItems.value.findIndex(i => i.id === item.id);
            if (itemIndex !== -1) {
                invoiceItems.value[itemIndex].profit = editingProfit.value;
            }

            // Cancel editing mode
            cancelEditingProfit();

            // Show success message (optional)
            console.log('Profit updated successfully');
        } else {
            const errorData = await response.json();
            console.error('Failed to update profit:', errorData.error);
            // You could show an error message to the user here
        }
    } catch (error) {
        console.error('Error updating profit:', error);
        // You could show an error message to the user here
    } finally {
        savingProfit.value = false;
    }
};

const changePage = (page) => {
    filterForm.get(route('ventes.index', { page }), {
        preserveState: true,
        preserveScroll: true,
    });
};

watch([() => filterForm.date_debut, () => filterForm.date_fin, () => filterForm.commercial_id], () => {
    currentPage.value = 1;
});

// Add new data for payments
const paymentSearch = ref('');
const paymentMethodFilter = ref('');
const selectedTab = ref('factures');

const filteredPayments = computed(() => {
    if (!props.payments?.data) {
        return [];
    }

    let filtered = props.payments.data;

    // Filter by payment method if selected
    if (paymentMethodFilter.value) {
        filtered = filtered.filter(payment => payment.payment_method === paymentMethodFilter.value);
    }

    // Filter by search term
    if (paymentSearch.value) {
        const searchTerm = paymentSearch.value.toLowerCase();
        filtered = filtered.filter(payment => {
            return payment.customer?.name?.toLowerCase().includes(searchTerm) ||
                   payment.customer?.phone_number?.toLowerCase().includes(searchTerm);
        });
    }

    return filtered;
});

const paymentStatistics = computed(() => {
    if (!props.payments?.statistics) return {
        today_total: 0,
        today_count: 0,
        week_total: 0,
        month_total: 0
    };

    const stats = props.payments.statistics;
    return {
        ...stats,
        average_transaction: stats.today_count ? Math.round(stats.today_total / stats.today_count) : 0
    };
});

// Update the header title check
const pageTitle = computed(() => {
    return selectedTab.value === 'encaissements' ? 'Encaissements' : 'Factures';
});

const paymentToDelete = ref(null);
const deletePaymentDialog = ref(false);

const confirmDeletePayment = (payment) => {
    paymentToDelete.value = payment;
    deletePaymentDialog.value = true;
};

const deletePayment = () => {
    if (!paymentToDelete.value || !paymentToDelete.value.invoice_id) {
        console.error('Missing required payment or invoice data');
        return;
    }

    router.delete(route('sales-invoices.payments.destroy', {
        salesInvoice: paymentToDelete.value.invoice_id,
        payment: paymentToDelete.value.id
    }), {
        onSuccess: () => {
            deletePaymentDialog.value = false;
            paymentToDelete.value = null;
        },
    });
};

const paymentHeaders = [
    { 
        title: 'Client',
        key: 'customer.name',
        align: 'start',
        sortable: true
    },
    { 
        title: 'Date',
        key: 'created_at',
        align: 'center',
        sortable: true
    },
    { 
        title: 'Montant Facture',
        key: 'invoice_total',
        align: 'end',
        sortable: true
    },
    { 
        title: 'Montant Payé',
        key: 'amount_paid',
        align: 'end',
        sortable: true
    },
    { 
        title: 'Reste à Payer',
        key: 'amount_remaining',
        align: 'end',
        sortable: true
    },
    { 
        title: 'Mode de Paiement',
        key: 'payment_method',
        align: 'center',
        sortable: true
    },
    { 
        title: 'Actions',
        key: 'actions',
        align: 'center',
        sortable: false
    }
];
</script>

<template>
    <Head :title="pageTitle" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ pageTitle }}
                </h2>
                <div class="flex gap-2">
                    <template v-if="selectedTab === 'factures'">
                        <v-btn-group class="mr-2">
                            <v-btn 
                                :color="filterForm.paid_status === '' ? 'primary' : undefined"
                                @click="filterForm.paid_status = ''"
                            >
                                Toutes
                            </v-btn>
                            <v-btn 
                                :color="filterForm.paid_status === 'paid' ? 'primary' : undefined"
                                @click="filterForm.paid_status = 'paid'"
                            >
                                Payées
                            </v-btn>
                            <v-btn 
                                :color="filterForm.paid_status === 'partial' ? 'primary' : undefined"
                                @click="filterForm.paid_status = 'partial'"
                            >
                                Partielles
                            </v-btn>
                            <v-btn 
                                :color="filterForm.paid_status === 'unpaid' ? 'primary' : undefined"
                                @click="filterForm.paid_status = 'unpaid'"
                            >
                                Impayées
                            </v-btn>
                        </v-btn-group>
                        <v-btn color="secondary" @click="filterDialog = true">
                            Plus de filtres
                        </v-btn>
                    </template>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-tabs
                        v-model="selectedTab"
                        color="primary"
                        align-tabs="center"
                    >
                        <v-tab value="factures">
                            <v-icon start>mdi-file-document</v-icon>
                            Factures
                        </v-tab>
                        <v-tab value="encaissements">
                            <v-icon start>mdi-cash-register</v-icon>
                            Encaissements
                        </v-tab>
                    </v-tabs>

                    <v-tabs-window v-model="selectedTab">
                        <!-- Factures Tab -->
                        <v-tabs-window-item value="factures">
                            <!-- Statistics Cards -->
                            <v-row class="mb-6">
                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Total Factures</div>
                                                    <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics?.total_amount || 0) }}</div>
                                                    <div class="text-caption mt-1">
                                                        {{ formatNumber(statistics?.total_invoices || 0) }} factures
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="primary">mdi-file-document</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Factures Payées</div>
                                                    <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics?.paid_amount || 0) }}</div>
                                                    <div class="text-caption mt-1">
                                                        {{ formatNumber(statistics?.paid_count || 0) }} factures payées
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="success">mdi-cash-check</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Factures Impayées</div>
                                                    <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics?.unpaid_amount || 0) }}</div>
                                                    <div class="text-caption mt-1">
                                                        {{ formatNumber(statistics?.unpaid_count || 0) }} factures impayées
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="error">mdi-cash-remove</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Taux de Paiement</div>
                                                    <div class="text-h5 font-weight-bold">
                                                        {{ statistics?.total_invoices ? formatNumber((statistics.paid_count / statistics.total_invoices) * 100) : 0 }}%
                                                    </div>
                                                    <div class="text-caption mt-1">
                                                        des factures sont payées
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="info">mdi-chart-pie</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>
                            </v-row>

                            <!-- Main Table -->
                            <v-card>
                                <v-table>
                                    <thead>
                                        <tr>
                                            <th>Numéro</th>
                                            <th>Date</th>
                                            <th>Client</th>
                                            <th>Montant Total</th>
                                            <th>Montant Payé</th>
                                            <th>Reste à Payer</th>
                                            <th>Bénéfice</th>
                                            <th>Statut</th>
                                            <th>Commercial</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="invoice in invoices?.data || []" :key="invoice.id">
                                            <td>{{ invoice.invoice_number || invoice.id }}</td>
                                            <td>{{ formatDate(invoice.created_at) }}</td>
                                            <td>{{ invoice.customer?.name }}</td>
                                            <td>{{ formatCurrency(invoice.total_amount) }}</td>
                                            <td>{{ formatCurrency(invoice.total_paid) }}</td>
                                            <td>
                                                <span :class="invoice.total_amount - invoice.total_paid > 0 ? 'text-error' : 'text-success'">
                                                    {{ formatCurrency(invoice.total_amount - invoice.total_paid) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span :class="(invoice.total_profit || 0) > 0 ? 'text-success' : (invoice.total_profit || 0) < 0 ? 'text-error' : ''">
                                                    {{ formatCurrency(invoice.total_profit || 0) }}
                                                </span>
                                            </td>
                                            <td>
                                                <v-chip
                                                    :color="invoice.total_paid >= invoice.total_amount ? 'success' : 
                                                           invoice.total_paid > 0 ? 'warning' : 'error'"
                                                >
                                                    {{ invoice.total_paid >= invoice.total_amount ? 'Payée' : 
                                                       invoice.total_paid > 0 ? 'Partielle' : 'Impayée' }}
                                                </v-chip>
                                            </td>
                                            <td>{{ invoice.commercial?.name }}</td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <v-btn 
                                                        icon="mdi-eye" 
                                                        variant="text" 
                                                        color="primary"
                                                        @click="showInvoice(invoice)"
                                                        size="small"
                                                    />
                                                    <v-btn 
                                                        icon="mdi-pencil" 
                                                        variant="text" 
                                                        color="secondary"
                                                        @click="editInvoice(invoice)"
                                                        size="small"
                                                    />
                                                    <v-btn 
                                                        icon="mdi-delete" 
                                                        variant="text" 
                                                        color="error"
                                                        @click="confirmDelete(invoice)"
                                                        size="small"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                                <!-- Add pagination -->
                                <div class="d-flex justify-center mt-4" v-if="invoices?.links && invoices.links.length > 3">
                                    <v-pagination
                                        v-model="currentPage"
                                        :length="Math.ceil(invoices.total / invoices.per_page)"
                                        :total-visible="7"
                                        @update:model-value="changePage"
                                    ></v-pagination>
                                </div>
                            </v-card>
                        </v-tabs-window-item>

                        <!-- Encaissements Tab -->
                        <v-tabs-window-item value="encaissements">
                            <!-- Payment Statistics Cards -->
                            <v-row class="mb-6">
                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Encaissements du Jour</div>
                                                    <div class="text-h5 font-weight-bold">{{ formatCurrency(props.payments?.statistics?.today_total || 0) }}</div>
                                                    <div class="text-caption mt-1">
                                                        {{ formatNumber(props.payments?.statistics?.today_count || 0) }} transactions
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="success">mdi-cash-plus</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Total Semaine</div>
                                                    <div class="text-h5 font-weight-bold">
                                                        {{ formatCurrency(props.payments?.statistics?.week_total || 0) }}
                                                    </div>
                                                    <div class="text-caption mt-1">
                                                        Cumul hebdomadaire
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="info">mdi-calendar-week</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Total Mois</div>
                                                    <div class="text-h5 font-weight-bold">
                                                        {{ formatCurrency(props.payments?.statistics?.month_total || 0) }}
                                                    </div>
                                                    <div class="text-caption mt-1">
                                                        Cumul mensuel
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="primary">mdi-calendar-month</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="3">
                                    <v-card elevation="2" class="rounded-lg">
                                        <v-card-item>
                                            <div class="d-flex justify-space-between align-center">
                                                <div>
                                                    <div class="text-subtitle-2 mb-1">Moyenne par Transaction</div>
                                                    <div class="text-h5 font-weight-bold">
                                                        {{ formatCurrency(props.payments?.statistics?.today_count ? (props.payments?.statistics?.today_total / props.payments?.statistics?.today_count) : 0) }}
                                                    </div>
                                                    <div class="text-caption mt-1">
                                                        Aujourd'hui
                                                    </div>
                                                </div>
                                                <v-icon size="48" color="warning">mdi-chart-line</v-icon>
                                            </div>
                                        </v-card-item>
                                    </v-card>
                                </v-col>
                            </v-row>

                            <!-- Search and Filter Section -->
                            <v-card class="mb-6">
                                <v-card-text>
                                    <v-row>
                                        <v-col cols="12" md="4">
                                            <v-text-field
                                                v-model="paymentSearch"
                                                prepend-icon="mdi-magnify"
                                                label="Rechercher par client"
                                                hide-details
                                                density="compact"
                                                variant="outlined"
                                                class="mb-2"
                                            />
                                        </v-col>
                                        <v-col cols="12" md="4">
                                            <v-select
                                                v-model="paymentMethodFilter"
                                                :items="[
                                                    { title: 'Tous', value: '' },
                                                    { title: 'Espèces', value: 'cash' },
                                                    { title: 'Virement', value: 'bank_transfer' },
                                                    { title: 'Mobile Money', value: 'mobile_money' }
                                                ]"
                                                label="Mode de paiement"
                                                hide-details
                                                density="compact"
                                                variant="outlined"
                                            />
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                            </v-card>

                            <!-- Payments Table -->
                            <v-card>
                                <v-data-table
                                    :headers="paymentHeaders"
                                    :items="filteredPayments"
                                    :search="paymentSearch"
                                    :loading="false"
                                    class="elevation-1"
                                >
                                    <template v-slot:item.customer.name="{ item }">
                                        <div>
                                            <div class="font-weight-medium">{{ item.customer?.name }}</div>
                                            <div class="text-caption text-grey">
                                                {{ item.customer?.phone_number }}
                                                <template v-if="item.customer?.address">
                                                    • {{ item.customer?.address }}
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <template v-slot:item.created_at="{ item }">
                                        <div class="text-center">
                                            {{ formatDate(item.created_at) }}
                                        </div>
                                    </template>

                                    <template v-slot:item.invoice_total="{ item }">
                                        {{ formatCurrency(item.invoice_total) }}
                                    </template>

                                    <template v-slot:item.amount_paid="{ item }">
                                        <span class="text-success">
                                            {{ formatCurrency(item.amount_paid) }}
                                        </span>
                                    </template>

                                    <template v-slot:item.amount_remaining="{ item }">
                                        <span :class="item.amount_remaining > 0 ? 'text-error' : 'text-success'">
                                            {{ formatCurrency(item.amount_remaining) }}
                                        </span>
                                    </template>

                                    <template v-slot:item.payment_method="{ item }">
                                        <v-chip
                                            :color="item.payment_method === 'cash' ? 'success' : 
                                                   item.payment_method === 'bank_transfer' ? 'info' : 
                                                   'warning'"
                                            size="small"
                                            class="text-capitalize"
                                        >
                                            {{ item.payment_method === 'cash' ? 'Espèces' : 
                                               item.payment_method === 'bank_transfer' ? 'Virement' : 
                                               item.payment_method === 'mobile_money' ? 'Mobile Money' : 
                                               item.payment_method }}
                                        </v-chip>
                                    </template>

                                    <template v-slot:item.actions="{ item }">
                                        <v-btn 
                                            icon="mdi-delete" 
                                            variant="text" 
                                            color="error"
                                            @click="confirmDeletePayment(item)"
                                        />
                                    </template>

                                    <template v-slot:no-data>
                                        <div class="d-flex align-center justify-center pa-4">
                                            <v-icon color="grey" class="mr-2">mdi-alert-circle-outline</v-icon>
                                            Aucun encaissement trouvé
                                        </div>
                                    </template>
                                </v-data-table>
                            </v-card>
                        </v-tabs-window-item>
                    </v-tabs-window>
                </v-card>
            </div>
        </div>

        <!-- Filtres Dialog -->
        <v-dialog v-model="filterDialog" max-width="500px">
            <v-card>
                <v-card-title>Filtrer les factures</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="applyFilters">
                        <v-text-field
                            v-model="filterForm.date_debut"
                            label="Date début"
                            type="date"
                        />
                        <v-text-field
                            v-model="filterForm.date_fin"
                            label="Date fin"
                            type="date"
                        />
                        <v-select
                            v-model="filterForm.paid_status"
                            :items="[
                                { title: 'Toutes', value: '' },
                                { title: 'Payées', value: 'paid' },
                                { title: 'Partielles', value: 'partial' },
                                { title: 'Impayées', value: 'unpaid' }
                            ]"
                            label="Statut de paiement"
                        />
                        <v-select
                            v-model="filterForm.commercial_id"
                            :items="[{ title: 'Tous', value: '' }, ...commerciaux]"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="filterDialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="filterForm.processing">
                                Appliquer
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Delete Invoice Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Confirmer la suppression</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette facture ? Cette action est irréversible.
                    <div v-if="invoiceToDelete" class="mt-4">
                        <strong>Détails de la facture :</strong>
                        <div>Numéro : {{ invoiceToDelete.invoice_number || invoiceToDelete.id }}</div>
                        <div>Client : {{ invoiceToDelete.customer?.name }}</div>
                        <div>Montant Total : {{ formatCurrency(invoiceToDelete.total_amount) }}</div>
                        <div>Montant Payé : {{ formatCurrency(invoiceToDelete.total_paid) }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deleteDialog = false">
                        Annuler
                    </v-btn>
                    <v-btn color="error" variant="text" @click="deleteInvoice">
                        Confirmer la suppression
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Payment Confirmation Dialog -->
        <v-dialog v-model="deletePaymentDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Confirmer la suppression</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cet encaissement ? Cette action est irréversible.
                    <div v-if="paymentToDelete" class="mt-4">
                        <strong>Détails de l'encaissement :</strong>
                        <div>Client : {{ paymentToDelete.customer?.name }}</div>
                        <div>Montant : {{ formatCurrency(paymentToDelete.amount_paid) }}</div>
                        <div>Date : {{ formatDate(paymentToDelete.created_at) }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deletePaymentDialog = false">
                        Annuler
                    </v-btn>
                    <v-btn color="error" variant="text" @click="deletePayment">
                        Confirmer la suppression
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Invoice Items Dialog -->
        <v-dialog v-model="itemsDialog" max-width="900px">
            <v-card>
                <v-card-title class="text-h5 d-flex align-center">
                    <v-icon class="mr-2" color="primary">mdi-file-document-outline</v-icon>
                    Détails de la facture
                    <template v-if="selectedInvoice">
                        - {{ selectedInvoice.invoice_number || selectedInvoice.id }}
                    </template>
                </v-card-title>

                <v-card-text>
                    <div v-if="selectedInvoice" class="mb-4">
                        <v-row>
                            <v-col cols="12" md="6">
                                <div class="text-subtitle-2 mb-1">Client</div>
                                <div class="text-body-1">{{ selectedInvoice.customer?.name }}</div>
                            </v-col>
                            <v-col cols="12" md="6">
                                <div class="text-subtitle-2 mb-1">Date</div>
                                <div class="text-body-1">{{ formatDate(selectedInvoice.created_at) }}</div>
                            </v-col>
                        </v-row>
                    </div>

                    <v-divider class="mb-4"></v-divider>

                    <div class="text-h6 mb-3">Articles de la facture</div>

                    <v-data-table
                        :headers="[
                            { title: 'Produit', key: 'product.name', align: 'start' },
                            { title: 'Prix unitaire', key: 'price', align: 'end' },
                            { title: 'Quantité', key: 'quantity', align: 'center' },
                            { title: 'Total', key: 'subtotal', align: 'end' },
                            { title: 'Profit', key: 'profit', align: 'end' }
                        ]"
                        :items="invoiceItems"
                        :loading="loadingItems"
                        class="elevation-1"
                        density="compact"
                    >
                        <template v-slot:item.price="{ item }">
                            {{ formatCurrency(item.price) }}
                        </template>

                        <template v-slot:item.quantity="{ item }">
                            <v-chip size="small" color="primary">
                                {{ formatNumber(item.quantity) }}
                            </v-chip>
                        </template>

                        <template v-slot:item.subtotal="{ item }">
                            <span class="font-weight-medium">
                                {{ formatCurrency(item.price * item.quantity) }}
                            </span>
                        </template>

                        <template v-slot:item.profit="{ item }">
                            <div v-if="editingItemId === item.id" class="d-flex align-center gap-2">
                                <v-text-field
                                    v-model.number="editingProfit"
                                    type="number"
                                    density="compact"
                                    variant="outlined"
                                    hide-details
                                    style="width: 120px;"
                                    @keyup.enter="saveProfit(item)"
                                    @keyup.escape="cancelEditingProfit"
                                />
                                <v-btn
                                    icon="mdi-check"
                                    size="small"
                                    color="success"
                                    variant="text"
                                    :loading="savingProfit"
                                    @click="saveProfit(item)"
                                />
                                <v-btn
                                    icon="mdi-close"
                                    size="small"
                                    color="error"
                                    variant="text"
                                    :disabled="savingProfit"
                                    @click="cancelEditingProfit"
                                />
                            </div>
                            <span 
                                v-else
                                :class="item.profit > 0 ? 'text-success' : item.profit < 0 ? 'text-error' : ''"
                                class="cursor-pointer hover:bg-gray-100 px-2 py-1 rounded"
                                @click="startEditingProfit(item)"
                                title="Cliquer pour modifier"
                            >
                                {{ formatCurrency(item.profit) }}
                            </span>
                        </template>

                        <template v-slot:no-data>
                            <div class="d-flex align-center justify-center pa-4">
                                <v-icon color="grey" class="mr-2">mdi-alert-circle-outline</v-icon>
                                Aucun article trouvé pour cette facture
                            </div>
                        </template>

                        <template v-slot:loading>
                            <div class="d-flex align-center justify-center pa-4">
                                <v-progress-circular indeterminate color="primary" class="mr-2"></v-progress-circular>
                                Chargement des articles...
                            </div>
                        </template>
                    </v-data-table>

                    <!-- Summary -->
                    <v-card v-if="invoiceItems.length > 0" class="mt-4" variant="outlined">
                        <v-card-text>
                            <v-row>
                                <v-col cols="12" md="4">
                                    <div class="text-subtitle-2 mb-1">Total articles</div>
                                    <div class="text-h6">{{ invoiceItems.length }}</div>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <div class="text-subtitle-2 mb-1">Montant total</div>
                                    <div class="text-h6 text-primary">
                                        {{ formatCurrency(invoiceItems.reduce((sum, item) => sum + (item.price * item.quantity), 0)) }}
                                    </div>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <div class="text-subtitle-2 mb-1">Profit total</div>
                                    <div class="text-h6" :class="invoiceItems.reduce((sum, item) => sum + item.profit, 0) > 0 ? 'text-success' : 'text-error'">
                                        {{ formatCurrency(invoiceItems.reduce((sum, item) => sum + item.profit, 0)) }}
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-card-text>

                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="itemsDialog = false">
                        Fermer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 
