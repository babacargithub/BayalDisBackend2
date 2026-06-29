<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'

const props = defineProps({
    timelineItems: Array,
    dailyTotals: Object,
    commerciaux: Array,
    filters: Object,
    activeBeat: Object,
})

const filterDialog = ref(false)

const filterForm = useForm({
    date: props.filters?.date || new Date().toISOString().split('T')[0],
    paid_status: props.filters?.paid_status || '',
    commercial_id: props.filters?.commercial_id || '',
    beat_id: props.filters?.beat_id || '',
})

const clearBeatFilter = () => {
    filterForm.beat_id = ''
    filterForm.get(route('ventes.index'), { preserveState: true, preserveScroll: true })
}

const applyFilters = () => {
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            filterDialog.value = false
        },
    })
}

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

const formatTime = (dateString) => {
    if (!dateString) return ''
    return new Date(dateString).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
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

const getInvoiceStatusChipColor = (status) => {
    if (status === 'FULLY_PAID') return 'success'
    if (status === 'PARTIALLY_PAID') return 'warning'
    return 'error'
}

const getInvoiceStatusLabel = (status) => {
    if (status === 'FULLY_PAID') return 'Payée'
    if (status === 'PARTIALLY_PAID') return 'Partielle'
    return 'Impayée'
}

// ─── Invoice items dialog ─────────────────────────────────────────────────────
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

// ─── Delete payment dialog ────────────────────────────────────────────────────
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
        payment: paymentToDelete.value.payment_id,
    }), {
        onSuccess: () => {
            deletePaymentDialog.value = false
            paymentToDelete.value = null
        },
    })
}

// ─── Product stats dialog ─────────────────────────────────────────────────────
const productStatsDialog = ref(false)
const productStatsLoading = ref(false)
const productStatsData = ref([])
const productStatsPeriodStart = ref(props.filters?.date || new Date().toISOString().split('T')[0])
const productStatsPeriodEnd = ref(props.filters?.date || new Date().toISOString().split('T')[0])

const productStatsHeaders = [
    { title: 'Produit', key: 'product_name', align: 'start', sortable: true },
    { title: 'Qté vendue', key: 'total_quantity_sold', align: 'end', sortable: true },
    { title: 'Clients', key: 'distinct_customers_count', align: 'end', sortable: true },
    { title: 'Chiffre d\'affaires', key: 'total_amount_sold', align: 'end', sortable: true },
    { title: 'Profit estimé', key: 'total_estimated_profit', align: 'end', sortable: true },
    { title: '% CA', key: 'sales_contribution_percentage', align: 'end', sortable: true },
    { title: '% Profit', key: 'profit_contribution_percentage', align: 'end', sortable: true },
]

const productStatsTotals = computed(() => ({
    totalAmount: productStatsData.value.reduce((sum, row) => sum + row.total_amount_sold, 0),
    totalProfit: productStatsData.value.reduce((sum, row) => sum + row.total_estimated_profit, 0),
    totalQuantity: productStatsData.value.reduce((sum, row) => sum + row.total_quantity_sold, 0),
    totalProducts: productStatsData.value.length,
}))

const openProductStats = async () => {
    productStatsDialog.value = true
    await fetchProductStats()
}

const fetchProductStats = async () => {
    productStatsLoading.value = true
    try {
        const url = new URL(route('ventes.product-stats'), window.location.origin)
        url.searchParams.set('date_start', productStatsPeriodStart.value)
        url.searchParams.set('date_end', productStatsPeriodEnd.value)

        const response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (response.ok) {
            const data = await response.json()
            productStatsData.value = data.stats || []
        }
    } catch {
        productStatsData.value = []
    } finally {
        productStatsLoading.value = false
    }
}

// ─── Cancel payment dialog ────────────────────────────────────────────────────
const cancelPaymentDialog = ref(false)
const paymentToCancel = ref(null)

const cancelPaymentForm = useForm({
    cancellation_reason: '',
})

const openCancelPaymentDialog = (payment) => {
    paymentToCancel.value = payment
    cancelPaymentForm.reset()
    cancelPaymentForm.clearErrors()
    cancelPaymentDialog.value = true
}

const confirmCancelPayment = () => {
    if (!paymentToCancel.value?.invoice_id) return

    cancelPaymentForm.post(route('sales-invoices.payments.cancel', {
        salesInvoice: paymentToCancel.value.invoice_id,
        payment: paymentToCancel.value.payment_id,
    }), {
        preserveScroll: true,
        onSuccess: () => {
            cancelPaymentDialog.value = false
            paymentToCancel.value = null
            cancelPaymentForm.reset()
        },
    })
}
</script>

<template>
    <Head title="Factures & Encaissements" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Factures & Encaissements
                </h2>
                <div class="flex gap-2 items-center">
                    <v-chip
                        v-if="activeBeat"
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-map-marker-check"
                        closable
                        @click:close="clearBeatFilter"
                        class="mr-2"
                    >
                        Beat : {{ activeBeat.name }}
                    </v-chip>
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
                    <v-btn color="indigo" variant="tonal" @click="openProductStats" prepend-icon="mdi-chart-bar">
                        Stats Produits
                    </v-btn>
                    <v-btn color="secondary" @click="filterDialog = true" prepend-icon="mdi-tune">
                        Filtres
                    </v-btn>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>

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
                                <div class="text-caption text-grey-darken-1 mb-1">Bénéfice Net</div>
                                <div
                                    class="text-h6 font-weight-bold"
                                    :class="(dailyTotals?.net_profit ?? 0) >= 0 ? 'text-success' : 'text-error'"
                                >
                                    {{ formatCurrency(dailyTotals?.net_profit) }}
                                </div>
                                <div class="text-caption text-grey mt-1">Réalisé − commissions − livraison</div>
                            </v-card>
                        </v-col>
                    </v-row>

                    <!-- Unified Timeline Table -->
                    <v-card variant="flat">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th class="text-left" style="width: 110px">Type</th>
                                    <th class="text-left">Client</th>
                                    <th class="text-center" style="width: 70px">Heure</th>
                                    <th class="text-right">Montant</th>
                                    <th class="text-right">Payé</th>
                                    <th class="text-right">Reste</th>
                                    <th class="text-center">Statut / Mode</th>
                                    <th class="text-center">Commercial</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="timelineItems.length === 0">
                                    <td colspan="9" class="text-center py-8 text-grey">
                                        <v-icon size="32" class="mb-2 d-block mx-auto">mdi-file-document-outline</v-icon>
                                        Aucune facture ni encaissement pour cette date
                                    </td>
                                </tr>

                                <template v-for="item in timelineItems" :key="item.row_type + '-' + (item.invoice_id ?? item.payment_id)">

                                    <!-- ── Invoice row ── -->
                                    <tr v-if="item.row_type === 'invoice'">
                                        <td>
                                            <v-chip
                                                size="small"
                                                :color="item.status === 'FULLY_PAID' ? 'success' : item.status === 'PARTIALLY_PAID' ? 'warning' : 'primary'"
                                                variant="tonal"
                                                prepend-icon="mdi-file-document"
                                            >
                                                Facture
                                            </v-chip>
                                        </td>
                                        <td>
                                            <div class="font-weight-medium">{{ item.customer_name }}</div>
                                            <div class="text-caption text-grey">{{ item.customer_address }}</div>
                                        </td>
                                        <td class="text-center text-caption text-grey">
                                            {{ formatTime(item.created_at) }}
                                        </td>
                                        <td class="text-right">{{ formatCurrency(item.total_amount) }}</td>
                                        <td class="text-right">{{ formatCurrency(item.total_payments) }}</td>
                                        <td class="text-right">
                                            <span :class="item.total_remaining > 0 ? 'text-error font-weight-medium' : 'text-success'">
                                                {{ formatCurrency(item.total_remaining) }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <v-chip
                                                size="small"
                                                :color="getInvoiceStatusChipColor(item.status)"
                                            >
                                                {{ getInvoiceStatusLabel(item.status) }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center">
                                            <v-tooltip v-if="item.commercial_name" :text="item.commercial_name">
                                                <template #activator="{ props: tooltipProps }">
                                                    <v-avatar
                                                        v-bind="tooltipProps"
                                                        size="28"
                                                        color="deep-purple"
                                                        class="text-white"
                                                        style="font-size: 10px; cursor: default"
                                                    >
                                                        {{ getInitials(item.commercial_name) }}
                                                    </v-avatar>
                                                </template>
                                            </v-tooltip>
                                            <span v-else class="text-grey">—</span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-center">
                                                <v-btn
                                                    icon="mdi-eye"
                                                    variant="text"
                                                    color="primary"
                                                    size="small"
                                                    density="compact"
                                                    title="Voir les articles"
                                                    @click="showInvoice(item)"
                                                />
                                                <v-btn
                                                    icon="mdi-file-pdf-box"
                                                    variant="text"
                                                    color="secondary"
                                                    size="small"
                                                    density="compact"
                                                    title="Télécharger PDF"
                                                    :href="route('sales-invoices.pdf', item.invoice_id)"
                                                    target="_blank"
                                                />
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- ── Past-invoice payment row ── -->
                                    <tr
                                        v-else-if="item.row_type === 'payment'"
                                        :class="{ 'cancelled-payment-row': item.cancelled_at }"
                                    >
                                        <td>
                                            <v-tooltip
                                                v-if="item.cancelled_at"
                                                location="top"
                                            >
                                                <template #activator="{ props: tooltipProps }">
                                                    <v-chip
                                                        v-bind="tooltipProps"
                                                        size="small"
                                                        color="error"
                                                        variant="outlined"
                                                        prepend-icon="mdi-cancel"
                                                    >
                                                        Annulé
                                                    </v-chip>
                                                </template>
                                                <span>
                                                    Annulé le {{ formatDate(item.cancelled_at) }}
                                                    <template v-if="item.cancelled_by_name"> par {{ item.cancelled_by_name }}</template>
                                                    <template v-if="item.cancellation_reason"> — {{ item.cancellation_reason }}</template>
                                                </span>
                                            </v-tooltip>
                                            <v-chip
                                                v-else
                                                size="small"
                                                color="teal"
                                                variant="outlined"
                                                prepend-icon="mdi-cash-multiple"
                                            >
                                                Encaissé
                                            </v-chip>
                                        </td>
                                        <td>
                                            <div class="font-weight-medium">{{ item.customer_name }}</div>
                                            <div class="text-caption text-grey">
                                                Facture du {{ formatDate(item.invoice_date) }}
                                            </div>
                                        </td>
                                        <td class="text-center text-caption text-grey">
                                            {{ formatTime(item.created_at) }}
                                        </td>
                                        <td class="text-right text-success font-weight-medium">
                                            {{ formatCurrency(item.payment_amount) }}
                                        </td>
                                        <td class="text-right">{{ formatCurrency(item.amount_paid) }}</td>
                                        <td class="text-right">
                                            <span :class="item.amount_remaining > 0 ? 'text-error font-weight-medium' : 'text-success'">
                                                {{ formatCurrency(item.amount_remaining) }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <v-chip size="small" color="teal" variant="tonal">
                                                {{ item.payment_method }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center text-grey">—</td>
                                        <td>
                                            <div class="d-flex justify-center">
                                                <v-btn
                                                    v-if="!item.cancelled_at"
                                                    icon="mdi-cancel"
                                                    variant="text"
                                                    color="warning"
                                                    size="small"
                                                    density="compact"
                                                    title="Annuler le paiement"
                                                    @click="openCancelPaymentDialog(item)"
                                                />
                                            </div>
                                        </td>
                                    </tr>

                                </template>
                            </tbody>
                        </v-table>
                    </v-card>
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
                        <strong>Client :</strong> {{ paymentToDelete.customer_name }}<br />
                        <strong>Montant :</strong> {{ formatCurrency(paymentToDelete.payment_amount) }}<br />
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

        <!-- ─── Cancel Payment Dialog ─────────────────────────────────────── -->
        <v-dialog v-model="cancelPaymentDialog" max-width="500px">
            <v-card>
                <v-card-title>Annuler le paiement</v-card-title>
                <v-card-text>
                    <v-alert type="warning" variant="tonal" class="mb-4">
                        L'annulation de ce paiement de
                        <strong>{{ formatCurrency(paymentToCancel?.payment_amount) }}</strong>
                        va recalculer les totaux de la facture, la commission du commercial
                        et retirer le montant de la caisse concernée.
                    </v-alert>
                    <v-alert
                        v-if="cancelPaymentForm.errors.error"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                    >
                        {{ cancelPaymentForm.errors.error }}
                    </v-alert>
                    <div v-if="paymentToCancel" class="mb-4">
                        <strong>Client :</strong> {{ paymentToCancel.customer_name }}<br />
                        <strong>Montant :</strong> {{ formatCurrency(paymentToCancel.payment_amount) }}<br />
                        <strong>Date :</strong> {{ formatDate(paymentToCancel.created_at) }}
                    </div>
                    <v-textarea
                        v-model="cancelPaymentForm.cancellation_reason"
                        label="Motif de l'annulation"
                        rows="2"
                        required
                        :error-messages="cancelPaymentForm.errors.cancellation_reason"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="cancelPaymentDialog = false">Retour</v-btn>
                    <v-btn
                        color="warning"
                        variant="text"
                        :loading="cancelPaymentForm.processing"
                        @click="confirmCancelPayment"
                    >
                        Confirmer l'annulation
                    </v-btn>
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
        <!-- ─── Product Stats Dialog ──────────────────────────────────────── -->
        <v-dialog v-model="productStatsDialog" max-width="1200px" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center pa-5 pb-3">
                    <v-icon color="indigo" class="mr-3" size="28">mdi-chart-bar</v-icon>
                    <span class="text-h5 font-weight-bold">Statistiques par Produit</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" @click="productStatsDialog = false" />
                </v-card-title>

                <v-divider />

                <!-- Date range filter -->
                <v-card-text class="pa-5 pb-3">
                    <v-row align="center" class="mb-4">
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model="productStatsPeriodStart"
                                label="Date début"
                                type="date"
                                density="compact"
                                variant="outlined"
                                hide-details
                                prepend-inner-icon="mdi-calendar-start"
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model="productStatsPeriodEnd"
                                label="Date fin"
                                type="date"
                                density="compact"
                                variant="outlined"
                                hide-details
                                prepend-inner-icon="mdi-calendar-end"
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-btn
                                color="indigo"
                                variant="tonal"
                                block
                                prepend-icon="mdi-refresh"
                                :loading="productStatsLoading"
                                @click="fetchProductStats"
                            >
                                Actualiser
                            </v-btn>
                        </v-col>
                    </v-row>

                    <!-- KPI summary cards -->
                    <v-row class="mb-4">
                        <v-col cols="6" sm="3">
                            <v-card variant="tonal" color="indigo" class="pa-3 text-center">
                                <div class="text-caption text-indigo-darken-2 mb-1">Produits vendus</div>
                                <div class="text-h6 font-weight-bold text-indigo">{{ productStatsTotals.totalProducts }}</div>
                            </v-card>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-card variant="tonal" color="blue" class="pa-3 text-center">
                                <div class="text-caption text-blue-darken-2 mb-1">Total unités</div>
                                <div class="text-h6 font-weight-bold text-blue">{{ formatNumber(productStatsTotals.totalQuantity) }}</div>
                            </v-card>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-card variant="tonal" color="primary" class="pa-3 text-center">
                                <div class="text-caption text-primary-darken-2 mb-1">Chiffre d'affaires</div>
                                <div class="text-h6 font-weight-bold text-primary">{{ formatCurrency(productStatsTotals.totalAmount) }}</div>
                            </v-card>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-card variant="tonal" color="success" class="pa-3 text-center">
                                <div class="text-caption text-success-darken-2 mb-1">Profit estimé</div>
                                <div class="text-h6 font-weight-bold text-success">{{ formatCurrency(productStatsTotals.totalProfit) }}</div>
                            </v-card>
                        </v-col>
                    </v-row>

                    <!-- Stats table -->
                    <v-data-table
                        :headers="productStatsHeaders"
                        :items="productStatsData"
                        :loading="productStatsLoading"
                        :items-per-page="25"
                        density="comfortable"
                        class="elevation-1 rounded-lg"
                        :sort-by="[{ key: 'total_amount_sold', order: 'desc' }]"
                    >
                        <template #item.total_amount_sold="{ item }">
                            <span class="font-weight-medium text-primary">{{ formatCurrency(item.total_amount_sold) }}</span>
                        </template>

                        <template #item.total_estimated_profit="{ item }">
                            <span
                                class="font-weight-medium"
                                :class="item.total_estimated_profit >= 0 ? 'text-success' : 'text-error'"
                            >
                                {{ formatCurrency(item.total_estimated_profit) }}
                            </span>
                        </template>

                        <template #item.total_quantity_sold="{ item }">
                            <v-chip size="small" color="blue" variant="tonal">
                                {{ formatNumber(item.total_quantity_sold) }}
                            </v-chip>
                        </template>

                        <template #item.distinct_customers_count="{ item }">
                            <v-chip size="small" color="purple" variant="tonal">
                                {{ item.distinct_customers_count }}
                            </v-chip>
                        </template>

                        <template #item.sales_contribution_percentage="{ item }">
                            <div class="d-flex align-center gap-2" style="min-width: 100px">
                                <v-progress-linear
                                    :model-value="item.sales_contribution_percentage"
                                    color="primary"
                                    bg-color="grey-lighten-3"
                                    rounded
                                    height="6"
                                    class="flex-grow-1"
                                />
                                <span class="text-caption font-weight-medium" style="min-width: 40px">
                                    {{ item.sales_contribution_percentage }}%
                                </span>
                            </div>
                        </template>

                        <template #item.profit_contribution_percentage="{ item }">
                            <div class="d-flex align-center gap-2" style="min-width: 100px">
                                <v-progress-linear
                                    :model-value="item.profit_contribution_percentage"
                                    color="success"
                                    bg-color="grey-lighten-3"
                                    rounded
                                    height="6"
                                    class="flex-grow-1"
                                />
                                <span class="text-caption font-weight-medium" style="min-width: 40px">
                                    {{ item.profit_contribution_percentage }}%
                                </span>
                            </div>
                        </template>

                        <template #no-data>
                            <div class="d-flex flex-column align-center justify-center py-10 text-grey">
                                <v-icon size="48" class="mb-3">mdi-chart-bar-stacked</v-icon>
                                <span class="text-body-1">Aucune vente sur cette période</span>
                            </div>
                        </template>
                    </v-data-table>
                </v-card-text>

                <v-divider />
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn color="primary" variant="tonal" @click="productStatsDialog = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </AuthenticatedLayout>
</template>

<style scoped>
.cancelled-payment-row {
    opacity: 0.55;
}

.cancelled-payment-row td:not(:first-child):not(:last-child) {
    text-decoration: line-through;
}
</style>
