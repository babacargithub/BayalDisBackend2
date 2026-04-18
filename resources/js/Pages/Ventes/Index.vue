<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'
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
                                    <tr v-else-if="item.row_type === 'payment'">
                                        <td>
                                            <v-chip
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
