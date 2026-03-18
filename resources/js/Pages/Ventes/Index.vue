<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'

const props = defineProps({
    summaries: Array,
    dailyTotals: Object,
    commerciaux: Array,
    filters: Object,
    payments: Object,
})

const filterDialog = ref(false)
const selectedTab = ref('factures')

const filterForm = useForm({
    date: props.filters?.date || new Date().toISOString().split('T')[0],
    paid_status: props.filters?.paid_status || '',
    commercial_id: props.filters?.commercial_id || '',
})

const applyFilters = () => {
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            filterDialog.value = false
        },
    })
}

// Apply status filter immediately on button click
const applyStatusFilter = (status) => {
    filterForm.paid_status = status
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
    })
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0)
}

const formatDate = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleDateString('fr-FR')
}

const formatNumber = (number) => {
    return new Intl.NumberFormat('fr-FR').format(number || 0)
}

const getInitials = (name) => {
    if (!name) return ''
    return name
        .split(' ')
        .filter(part => part.length > 0)
        .map(part => part[0].toUpperCase())
        .join('')
}

const getStatusChipColor = (status) => {
    if (status === 'FULLY_PAID') return 'success'
    if (status === 'PARTIALLY_PAID') return 'warning'
    return 'error'
}

const getStatusLabel = (status) => {
    if (status === 'FULLY_PAID') return 'Payée'
    if (status === 'PARTIALLY_PAID') return 'Partielle'
    return 'Impayée'
}

// ─── Invoice items dialog ────────────────────────────────────────────────────
const itemsDialog = ref(false)
const selectedInvoiceId = ref(null)
const selectedInvoiceName = ref('')
const invoiceItems = ref([])
const loadingItems = ref(false)

const editingItemId = ref(null)
const editingProfit = ref(0)
const savingProfit = ref(false)

const showInvoice = async (summary) => {
    selectedInvoiceId.value = summary.invoice_id
    selectedInvoiceName.value = summary.customer_name
    loadingItems.value = true
    itemsDialog.value = true

    try {
        const response = await fetch(route('sales-invoices.show', summary.invoice_id), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (response.ok) {
            const data = await response.json()
            invoiceItems.value = data.invoice.items || []
        } else {
            invoiceItems.value = []
        }
    } catch {
        invoiceItems.value = []
    } finally {
        loadingItems.value = false
    }
}

const startEditingProfit = (item) => {
    editingItemId.value = item.id
    editingProfit.value = item.profit
}

const cancelEditingProfit = () => {
    editingItemId.value = null
    editingProfit.value = 0
}

const saveProfit = async (item) => {
    if (savingProfit.value) return
    savingProfit.value = true

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        const response = await fetch(`/sales-invoices/${selectedInvoiceId.value}/items/${item.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ profit: editingProfit.value }),
        })

        if (response.ok) {
            const itemIndex = invoiceItems.value.findIndex(i => i.id === item.id)
            if (itemIndex !== -1) {
                invoiceItems.value[itemIndex].profit = editingProfit.value
            }
            cancelEditingProfit()
        }
    } finally {
        savingProfit.value = false
    }
}

// ─── Payments tab ─────────────────────────────────────────────────────────────
const paymentSearch = ref('')
const paymentMethodFilter = ref('')

const filteredPayments = computed(() => {
    if (!props.payments?.data) return []

    let filtered = props.payments.data

    if (paymentMethodFilter.value) {
        filtered = filtered.filter(payment => payment.payment_method === paymentMethodFilter.value)
    }

    if (paymentSearch.value) {
        const searchTerm = paymentSearch.value.toLowerCase()
        filtered = filtered.filter(payment =>
            payment.customer?.name?.toLowerCase().includes(searchTerm) ||
            payment.customer?.phone_number?.toLowerCase().includes(searchTerm)
        )
    }

    return filtered
})

const pageTitle = computed(() => {
    return selectedTab.value === 'encaissements' ? 'Encaissements' : 'Factures'
})

const paymentToDelete = ref(null)
const deletePaymentDialog = ref(false)

const confirmDeletePayment = (payment) => {
    paymentToDelete.value = payment
    deletePaymentDialog.value = true
}

const deletePayment = () => {
    if (!paymentToDelete.value?.invoice_id) return

    router.delete(route('sales-invoices.payments.destroy', {
        salesInvoice: paymentToDelete.value.invoice_id,
        payment: paymentToDelete.value.id,
    }), {
        onSuccess: () => {
            deletePaymentDialog.value = false
            paymentToDelete.value = null
        },
    })
}

const paymentHeaders = [
    { title: 'Client', key: 'customer.name', align: 'start', sortable: true },
    { title: 'Date', key: 'created_at', align: 'center', sortable: true },
    { title: 'Montant Facture', key: 'invoice_total', align: 'end', sortable: true },
    { title: 'Montant Payé', key: 'amount_paid', align: 'end', sortable: true },
    { title: 'Reste à Payer', key: 'amount_remaining', align: 'end', sortable: true },
    { title: 'Mode de Paiement', key: 'payment_method', align: 'center', sortable: true },
    { title: 'Actions', key: 'actions', align: 'center', sortable: false },
]
</script>

<template>
    <Head :title="pageTitle" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ pageTitle }}
                </h2>
                <div class="flex gap-2 items-center">
                    <template v-if="selectedTab === 'factures'">
                        <v-btn-group class="mr-2">
                            <v-btn
                                :color="filterForm.paid_status === '' ? 'primary' : undefined"
                                @click="applyStatusFilter('')"
                            >
                                Toutes
                            </v-btn>
                            <v-btn
                                :color="filterForm.paid_status === 'paid' ? 'success' : undefined"
                                @click="applyStatusFilter('paid')"
                            >
                                Payées
                            </v-btn>
                            <v-btn
                                :color="filterForm.paid_status === 'partial' ? 'warning' : undefined"
                                @click="applyStatusFilter('partial')"
                            >
                                Partielles
                            </v-btn>
                            <v-btn
                                :color="filterForm.paid_status === 'unpaid' ? 'error' : undefined"
                                @click="applyStatusFilter('unpaid')"
                            >
                                Impayées
                            </v-btn>
                        </v-btn-group>
                        <v-btn color="secondary" @click="filterDialog = true" prepend-icon="mdi-tune">
                            Plus de filtres
                        </v-btn>
                    </template>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-tabs v-model="selectedTab" color="primary" align-tabs="center">
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

                        <!-- ─── Factures Tab ─────────────────────────────── -->
                        <v-tabs-window-item value="factures">

                            <!-- Summary Cards -->
                            <v-row class="pa-4 mb-2">
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Total Factures</div>
                                        <div class="text-h6 font-weight-bold">{{ formatCurrency(dailyTotals?.total_amount) }}</div>
                                        <div class="text-caption text-grey mt-1">{{ formatNumber(dailyTotals?.invoices_count) }} factures</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Total Encaissements</div>
                                        <div class="text-h6 font-weight-bold text-success">{{ formatCurrency(dailyTotals?.total_payments) }}</div>
                                        <div class="text-caption text-grey mt-1">Reste: {{ formatCurrency((dailyTotals?.total_amount ?? 0) - (dailyTotals?.total_payments ?? 0)) }}</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Total Commissions</div>
                                        <div class="text-h6 font-weight-bold text-deep-purple">{{ formatCurrency(dailyTotals?.total_commissions) }}</div>
                                        <div class="text-caption text-grey mt-1">Commissions estimées</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Coût Livraison</div>
                                        <div class="text-h6 font-weight-bold text-orange-darken-2">{{ formatCurrency(dailyTotals?.total_delivery_cost) }}</div>
                                        <div class="text-caption text-grey mt-1">Coût transport du jour</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Bénéfice Net</div>
                                        <div class="text-h6 font-weight-bold" :class="(dailyTotals?.net_profit ?? 0) >= 0 ? 'text-success' : 'text-error'">
                                            {{ formatCurrency(dailyTotals?.net_profit) }}
                                        </div>
                                        <div class="text-caption text-grey mt-1">Réalisé − commissions − livraison</div>
                                    </v-card>
                                </v-col>
                            </v-row>

                            <!-- Invoices Table -->
                            <v-card variant="flat">
                                <v-table density="compact">
                                    <thead>
                                        <tr>
                                            <th class="text-left">Client</th>
                                            <th class="text-right">Total</th>
                                            <th class="text-right">Payé</th>
                                            <th class="text-right">Reste</th>
                                            <th class="text-right">Bénéfice</th>
                                            <th class="text-right">Commission</th>
                                            <th class="text-right">Coût Livraison</th>
                                            <th class="text-center">Commercial</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="summaries.length === 0">
                                            <td colspan="9" class="text-center py-8 text-grey">
                                                <v-icon size="32" class="mb-2 d-block mx-auto">mdi-file-document-outline</v-icon>
                                                Aucune facture pour cette date
                                            </td>
                                        </tr>
                                        <tr v-for="summary in summaries" :key="summary.invoice_id">
                                            <!-- Client -->
                                            <td>
                                                <div class="font-weight-medium">{{ summary.customer_name }}</div>
                                                <div class="text-caption text-grey">{{ summary.customer_address }}</div>
                                            </td>
                                            <!-- Total -->
                                            <td class="text-right">{{ formatCurrency(summary.total_amount) }}</td>
                                            <!-- Payé -->
                                            <td class="text-right">{{ formatCurrency(summary.total_payments) }}</td>
                                            <!-- Reste -->
                                            <td class="text-right">
                                                <span :class="summary.total_remaining > 0 ? 'text-error font-weight-medium' : 'text-success'">
                                                    {{ formatCurrency(summary.total_remaining) }}
                                                </span>
                                            </td>
                                            <!-- Bénéfice -->
                                            <td class="text-right">{{ formatCurrency(summary.total_estimated_profit) }}</td>
                                            <!-- Commission -->
                                            <td class="text-right text-deep-purple">
                                                {{ formatCurrency(summary.estimated_commercial_commission) }}
                                            </td>
                                            <!-- Coût Livraison -->
                                            <td class="text-right text-orange-darken-2">
                                                {{ summary.delivery_cost != null ? formatCurrency(summary.delivery_cost) : '—' }}
                                            </td>
                                            <!-- Commercial (initials) -->
                                            <td class="text-center">
                                                <v-tooltip v-if="summary.commercial_name" :text="summary.commercial_name">
                                                    <template #activator="{ props: tooltipProps }">
                                                        <v-avatar
                                                            v-bind="tooltipProps"
                                                            size="28"
                                                            color="deep-purple"
                                                            class="text-white"
                                                            style="font-size: 10px; cursor: default"
                                                        >
                                                            {{ getInitials(summary.commercial_name) }}
                                                        </v-avatar>
                                                    </template>
                                                </v-tooltip>
                                                <span v-else class="text-grey">—</span>
                                            </td>
                                            <!-- Actions -->
                                            <td>
                                                <div class="d-flex gap-1 justify-center">
                                                    <v-btn
                                                        icon="mdi-eye"
                                                        variant="text"
                                                        color="primary"
                                                        size="small"
                                                        density="compact"
                                                        title="Voir les articles"
                                                        @click="showInvoice(summary)"
                                                    />
                                                    <v-btn
                                                        icon="mdi-file-pdf-box"
                                                        variant="text"
                                                        color="secondary"
                                                        size="small"
                                                        density="compact"
                                                        title="Télécharger PDF"
                                                        :href="route('sales-invoices.pdf', summary.invoice_id)"
                                                        target="_blank"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>
                        </v-tabs-window-item>

                        <!-- ─── Encaissements Tab ────────────────────────── -->
                        <v-tabs-window-item value="encaissements">
                            <!-- Payment Statistics Cards -->
                            <v-row class="pa-4 mb-2">
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Encaissements du Jour</div>
                                        <div class="text-h6 font-weight-bold text-success">{{ formatCurrency(payments?.statistics?.today_total) }}</div>
                                        <div class="text-caption text-grey mt-1">{{ formatNumber(payments?.statistics?.today_count) }} transactions</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Total Semaine</div>
                                        <div class="text-h6 font-weight-bold">{{ formatCurrency(payments?.statistics?.week_total) }}</div>
                                        <div class="text-caption text-grey mt-1">Cumul hebdomadaire</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Total Mois</div>
                                        <div class="text-h6 font-weight-bold">{{ formatCurrency(payments?.statistics?.month_total) }}</div>
                                        <div class="text-caption text-grey mt-1">Cumul mensuel</div>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card elevation="1" class="pa-4">
                                        <div class="text-caption text-grey-darken-1 mb-1">Moyenne par Transaction</div>
                                        <div class="text-h6 font-weight-bold">
                                            {{ formatCurrency(payments?.statistics?.today_count ? (payments?.statistics?.today_total / payments?.statistics?.today_count) : 0) }}
                                        </div>
                                        <div class="text-caption text-grey mt-1">Aujourd'hui</div>
                                    </v-card>
                                </v-col>
                            </v-row>

                            <!-- Search and Filter Section -->
                            <div class="px-4 pb-4">
                                <v-row>
                                    <v-col cols="12" md="4">
                                        <v-text-field
                                            v-model="paymentSearch"
                                            prepend-inner-icon="mdi-magnify"
                                            label="Rechercher par client"
                                            hide-details
                                            density="compact"
                                            variant="outlined"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="4">
                                        <v-select
                                            v-model="paymentMethodFilter"
                                            :items="[
                                                { title: 'Tous', value: '' },
                                                { title: 'Cash', value: 'Cash' },
                                                { title: 'Wave', value: 'Wave' },
                                                { title: 'Om', value: 'Om' },
                                            ]"
                                            label="Mode de paiement"
                                            hide-details
                                            density="compact"
                                            variant="outlined"
                                        />
                                    </v-col>
                                </v-row>
                            </div>

                            <!-- Payments Table -->
                            <v-data-table
                                :headers="paymentHeaders"
                                :items="filteredPayments"
                                class="elevation-1"
                            >
                                <template #item.customer.name="{ item }">
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

                                <template #item.created_at="{ item }">
                                    <div class="text-center">{{ formatDate(item.created_at) }}</div>
                                </template>

                                <template #item.invoice_total="{ item }">
                                    {{ formatCurrency(item.invoice_total) }}
                                </template>

                                <template #item.amount_paid="{ item }">
                                    <span class="text-success">{{ formatCurrency(item.amount_paid) }}</span>
                                </template>

                                <template #item.amount_remaining="{ item }">
                                    <span :class="item.amount_remaining > 0 ? 'text-error' : 'text-success'">
                                        {{ formatCurrency(item.amount_remaining) }}
                                    </span>
                                </template>

                                <template #item.payment_method="{ item }">
                                    <v-chip size="small">{{ item.payment_method }}</v-chip>
                                </template>

                                <template #item.actions="{ item }">
                                    <v-btn icon="mdi-delete" variant="text" color="error" @click="confirmDeletePayment(item)" />
                                </template>

                                <template #no-data>
                                    <div class="d-flex align-center justify-center pa-4">
                                        <v-icon color="grey" class="mr-2">mdi-alert-circle-outline</v-icon>
                                        Aucun encaissement trouvé
                                    </div>
                                </template>
                            </v-data-table>
                        </v-tabs-window-item>
                    </v-tabs-window>
                </v-card>
            </div>
        </div>

        <!-- ─── More Filters Dialog ───────────────────────────────────────── -->
        <v-dialog v-model="filterDialog" max-width="500px">
            <v-card>
                <v-card-title>Filtrer les factures</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="applyFilters">
                        <v-text-field
                            v-model="filterForm.date"
                            label="Date"
                            type="date"
                            class="mb-2"
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

        <!-- ─── Delete Payment Dialog ─────────────────────────────────────── -->
        <v-dialog v-model="deletePaymentDialog" max-width="500px">
            <v-card>
                <v-card-title>Confirmer la suppression</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cet encaissement ? Cette action est irréversible.
                    <div v-if="paymentToDelete" class="mt-4">
                        <strong>Client :</strong> {{ paymentToDelete.customer?.name }}<br />
                        <strong>Montant :</strong> {{ formatCurrency(paymentToDelete.amount_paid) }}<br />
                        <strong>Date :</strong> {{ formatDate(paymentToDelete.created_at) }}
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deletePaymentDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="text" @click="deletePayment">Confirmer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ─── Invoice Items Dialog ──────────────────────────────────────── -->
        <v-dialog v-model="itemsDialog" max-width="900px">
            <v-card>
                <v-card-title class="text-h5 d-flex align-center">
                    <v-icon class="mr-2" color="primary">mdi-file-document-outline</v-icon>
                    Détails — {{ selectedInvoiceName }}
                </v-card-title>

                <v-card-text>
                    <v-data-table
                        :headers="[
                            { title: 'Produit', key: 'product.name', align: 'start' },
                            { title: 'Prix unitaire', key: 'price', align: 'end' },
                            { title: 'Quantité', key: 'quantity', align: 'center' },
                            { title: 'Total', key: 'subtotal', align: 'end' },
                            { title: 'Profit', key: 'profit', align: 'end' },
                        ]"
                        :items="invoiceItems"
                        :loading="loadingItems"
                        class="elevation-1"
                        density="compact"
                    >
                        <template #item.price="{ item }">{{ formatCurrency(item.price) }}</template>

                        <template #item.quantity="{ item }">
                            <v-chip size="small" color="primary">{{ formatNumber(item.quantity) }}</v-chip>
                        </template>

                        <template #item.subtotal="{ item }">
                            <span class="font-weight-medium">{{ formatCurrency(item.price * item.quantity) }}</span>
                        </template>

                        <template #item.profit="{ item }">
                            <div v-if="editingItemId === item.id" class="d-flex align-center gap-2">
                                <v-text-field
                                    v-model.number="editingProfit"
                                    type="number"
                                    density="compact"
                                    variant="outlined"
                                    hide-details
                                    style="width: 120px"
                                    @keyup.enter="saveProfit(item)"
                                    @keyup.escape="cancelEditingProfit"
                                />
                                <v-btn icon="mdi-check" size="small" color="success" variant="text" :loading="savingProfit" @click="saveProfit(item)" />
                                <v-btn icon="mdi-close" size="small" color="error" variant="text" :disabled="savingProfit" @click="cancelEditingProfit" />
                            </div>
                            <span
                                v-else
                                :class="item.profit > 0 ? 'text-success' : item.profit < 0 ? 'text-error' : ''"
                                class="cursor-pointer px-2 py-1 rounded"
                                style="cursor: pointer"
                                @click="startEditingProfit(item)"
                                title="Cliquer pour modifier"
                            >
                                {{ formatCurrency(item.profit) }}
                            </span>
                        </template>

                        <template #no-data>
                            <div class="d-flex align-center justify-center pa-4">
                                <v-icon color="grey" class="mr-2">mdi-alert-circle-outline</v-icon>
                                Aucun article trouvé
                            </div>
                        </template>
                    </v-data-table>

                    <!-- Summary footer -->
                    <v-card v-if="invoiceItems.length > 0" class="mt-4" variant="outlined">
                        <v-card-text>
                            <v-row>
                                <v-col cols="4">
                                    <div class="text-caption text-grey mb-1">Articles</div>
                                    <div class="text-h6">{{ invoiceItems.length }}</div>
                                </v-col>
                                <v-col cols="4">
                                    <div class="text-caption text-grey mb-1">Montant total</div>
                                    <div class="text-h6 text-primary">
                                        {{ formatCurrency(invoiceItems.reduce((sum, item) => sum + item.price * item.quantity, 0)) }}
                                    </div>
                                </v-col>
                                <v-col cols="4">
                                    <div class="text-caption text-grey mb-1">Profit total</div>
                                    <div class="text-h6" :class="invoiceItems.reduce((s, i) => s + i.profit, 0) > 0 ? 'text-success' : 'text-error'">
                                        {{ formatCurrency(invoiceItems.reduce((sum, item) => sum + item.profit, 0)) }}
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-card-text>

                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="itemsDialog = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>
