<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PaymentsDialog from '@/Pages/SalesInvoices/Partials/PaymentsDialog.vue';

const props = defineProps({
    modelValue: Boolean,
    customer: Object,
    prospectionEvents: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['update:modelValue']);

const dialog = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const currentTab = ref('factures');
const invoices = ref([]);
const isLoadingInvoices = ref(false);
const selectedInvoiceId = ref(null);
const isPaymentsDialogOpen = ref(false);
const invoiceToDeleteId = ref(null);
const isDeleteDialogOpen = ref(false);

const selectedInvoiceForPayments = computed(() =>
    invoices.value.find((invoice) => invoice.id === selectedInvoiceId.value) ?? null
);

const totalDebt = computed(() =>
    invoices.value
        .filter((invoice) => !invoice.paid)
        .reduce((sum, invoice) => sum + invoice.total_remaining, 0)
);

const allPayments = computed(() =>
    invoices.value
        .flatMap((invoice) =>
            (invoice.payments || []).map((payment) => ({
                ...payment,
                invoice_id: invoice.id,
            }))
        )
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
);

const fetchInvoices = async () => {
    if (!props.customer) {
        return;
    }
    isLoadingInvoices.value = true;
    try {
        const response = await fetch(route('clients.invoices', props.customer.id), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json();
        invoices.value = data.invoices;
    } catch (error) {
        console.error('Failed to fetch customer invoices:', error);
    } finally {
        isLoadingInvoices.value = false;
    }
};

watch(
    [() => props.modelValue, () => props.customer?.id],
    ([isOpen]) => {
        if (isOpen && props.customer) {
            invoices.value = [];
            fetchInvoices();
        } else if (!isOpen) {
            selectedInvoiceId.value = null;
            isPaymentsDialogOpen.value = false;
        }
    }
);

const openPaymentsDialog = (invoice) => {
    selectedInvoiceId.value = invoice.id;
    isPaymentsDialogOpen.value = true;
};

const handlePaymentsUpdated = () => {
    fetchInvoices();
};

const openDeleteDialog = (invoice) => {
    if (invoice.total_payments > 0) {
        alert("Impossible de supprimer une facture avec des paiements. Supprimez d'abord les paiements.");
        return;
    }
    invoiceToDeleteId.value = invoice.id;
    isDeleteDialogOpen.value = true;
};

const confirmDeleteInvoice = () => {
    router.delete(route('sales-invoices.destroy', invoiceToDeleteId.value), {
        preserveScroll: true,
        onSuccess: () => {
            isDeleteDialogOpen.value = false;
            invoiceToDeleteId.value = null;
            fetchInvoices();
        },
    });
};

const getInvoiceStatusColor = (invoice) => {
    if (invoice.paid) {
        return 'success';
    }
    if (invoice.total_payments > 0) {
        return 'warning';
    }
    return 'error';
};

const getInvoiceStatusLabel = (invoice) => {
    if (invoice.paid) {
        return 'Soldée';
    }
    if (invoice.total_payments > 0) {
        return 'Partielle';
    }
    return 'Impayée';
};

const formatCurrency = (amount) =>
    new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0);

const formatDate = (date) => {
    if (!date) {
        return '';
    }
    return new Date(date).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
};
</script>

<template>
    <div>
        <v-dialog v-model="dialog" max-width="900px">
            <v-card>
                <v-toolbar dark color="primary">
                    <v-btn icon dark @click="dialog = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                    <v-toolbar-title>Historique — {{ customer?.name }}</v-toolbar-title>
                </v-toolbar>

                <v-card-text class="pa-4">
                    <v-tabs v-model="currentTab" class="mb-4">
                        <v-tab value="factures">
                            Factures
                            <v-badge
                                v-if="invoices.length"
                                :content="invoices.length"
                                color="primary"
                                inline
                                class="ml-1"
                            />
                        </v-tab>
                        <v-tab value="paiements">
                            Paiements
                            <v-badge
                                v-if="allPayments.length"
                                :content="allPayments.length"
                                color="green"
                                inline
                                class="ml-1"
                            />
                        </v-tab>
                        <v-tab value="prospection">
                            Prospection
                            <v-badge
                                v-if="prospectionEvents.length"
                                :content="prospectionEvents.length"
                                color="purple"
                                inline
                                class="ml-1"
                            />
                        </v-tab>
                    </v-tabs>

                    <!-- Factures Tab -->
                    <div v-if="currentTab === 'factures'">
                        <v-progress-linear v-if="isLoadingInvoices" indeterminate color="primary" class="mb-4" />

                        <v-alert
                            v-if="totalDebt > 0"
                            type="error"
                            variant="tonal"
                            icon="mdi-alert-circle"
                            class="mb-4"
                        >
                            <strong>Dette totale : {{ formatCurrency(totalDebt) }}</strong>
                        </v-alert>

                        <div v-if="!isLoadingInvoices && invoices.length === 0" class="text-center text-grey py-8">
                            <v-icon size="48" color="grey-lighten-1">mdi-receipt-text-outline</v-icon>
                            <div class="mt-2">Aucune facture trouvée</div>
                        </div>

                        <v-expansion-panels v-else variant="accordion">
                            <v-expansion-panel v-for="invoice in invoices" :key="invoice.id">
                                <v-expansion-panel-title>
                                    <div class="d-flex align-center gap-3 w-100 pr-2">
                                        <span class="text-body-2 text-grey">{{ formatDate(invoice.created_at) }}</span>
                                        <v-chip
                                            :color="getInvoiceStatusColor(invoice)"
                                            size="x-small"
                                            variant="flat"
                                            class="text-white"
                                        >
                                            {{ getInvoiceStatusLabel(invoice) }}
                                        </v-chip>
                                        <span class="font-weight-medium">{{ formatCurrency(invoice.total) }}</span>
                                        <span v-if="!invoice.paid" class="text-error text-body-2">
                                            Reste: {{ formatCurrency(invoice.total_remaining) }}
                                        </span>
                                        <v-spacer />
                                        <v-btn
                                            icon
                                            size="small"
                                            color="deep-purple"
                                            variant="tonal"
                                            title="Gérer les paiements"
                                            @click.stop="openPaymentsDialog(invoice)"
                                        >
                                            <v-icon>mdi-cash</v-icon>
                                        </v-btn>
                                        <v-btn
                                            icon
                                            size="small"
                                            color="error"
                                            variant="tonal"
                                            title="Supprimer la facture"
                                            class="ml-1"
                                            @click.stop="openDeleteDialog(invoice)"
                                        >
                                            <v-icon>mdi-delete</v-icon>
                                        </v-btn>
                                    </div>
                                </v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <v-table density="compact">
                                        <thead>
                                            <tr>
                                                <th>Produit</th>
                                                <th class="text-right">Quantité</th>
                                                <th class="text-right">Prix unitaire</th>
                                                <th class="text-right">Sous-total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="item in invoice.items" :key="item.id">
                                                <td>{{ item.product.name }}</td>
                                                <td class="text-right">{{ item.quantity }}</td>
                                                <td class="text-right">{{ formatCurrency(item.price) }}</td>
                                                <td class="text-right font-weight-medium">
                                                    {{ formatCurrency(item.price * item.quantity) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>
                    </div>

                    <!-- Paiements Tab -->
                    <div v-if="currentTab === 'paiements'">
                        <v-progress-linear v-if="isLoadingInvoices" indeterminate color="primary" class="mb-4" />

                        <div
                            v-if="!isLoadingInvoices && allPayments.length === 0"
                            class="text-center text-grey py-8"
                        >
                            <v-icon size="48" color="grey-lighten-1">mdi-cash-remove</v-icon>
                            <div class="mt-2">Aucun paiement enregistré</div>
                        </div>

                        <v-table v-else density="compact">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Facture #</th>
                                    <th class="text-right">Montant</th>
                                    <th>Méthode</th>
                                    <th class="text-right">Commission</th>
                                    <th>Statut</th>
                                    <th>Commentaire</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="payment in allPayments"
                                    :key="payment.id"
                                    :style="payment.cancelled_at ? 'opacity: 0.55; text-decoration: line-through' : ''"
                                >
                                    <td>{{ formatDate(payment.created_at) }}</td>
                                    <td class="text-grey">#{{ payment.invoice_id }}</td>
                                    <td class="text-right font-weight-medium">{{ formatCurrency(payment.amount) }}</td>
                                    <td>{{ payment.payment_method }}</td>
                                    <td class="text-right text-deep-purple">
                                        {{ formatCurrency(payment.commercial_commission ?? 0) }}
                                    </td>
                                    <td>
                                        <v-chip
                                            v-if="payment.cancelled_at"
                                            color="error"
                                            size="x-small"
                                            variant="flat"
                                        >
                                            Annulé
                                        </v-chip>
                                        <v-chip v-else color="success" size="x-small" variant="flat">Actif</v-chip>
                                    </td>
                                    <td class="text-grey">{{ payment.comment }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>

                    <!-- Prospection Tab -->
                    <div v-if="currentTab === 'prospection'">
                        <div v-if="prospectionEvents.length === 0" class="text-center text-grey py-8">
                            <v-icon size="48" color="grey-lighten-1">mdi-account-clock-outline</v-icon>
                            <div class="mt-2">Aucune interaction enregistrée</div>
                        </div>
                        <v-timeline v-else density="compact" align="start">
                            <v-timeline-item
                                v-for="event in prospectionEvents"
                                :key="event.id"
                                :dot-color="event.status_color"
                                size="small"
                            >
                                <div class="d-flex align-center gap-2 mb-1">
                                    <v-chip
                                        :color="event.status_color"
                                        size="x-small"
                                        variant="flat"
                                        class="text-white"
                                    >
                                        {{ event.status_label }}
                                    </v-chip>
                                    <span class="text-caption text-grey">{{ formatDate(event.created_at) }}</span>
                                    <span v-if="event.commercial_name" class="text-caption text-grey">
                                        — {{ event.commercial_name }}
                                    </span>
                                </div>
                                <div v-if="event.notes" class="text-body-2 mt-1">{{ event.notes }}</div>
                                <div v-if="event.scheduled_revisit_date" class="text-caption text-orange mt-1">
                                    <v-icon size="12" class="mr-1">mdi-calendar-clock</v-icon>
                                    Revisiter le {{ formatDate(event.scheduled_revisit_date) }}
                                </div>
                            </v-timeline-item>
                        </v-timeline>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Payments sub-dialog — teleports to body so it renders above the history dialog -->
        <PaymentsDialog
            v-if="selectedInvoiceForPayments"
            v-model="isPaymentsDialogOpen"
            :invoice="selectedInvoiceForPayments"
            @updated="handlePaymentsUpdated"
        />

        <!-- Delete invoice confirmation -->
        <v-dialog v-model="isDeleteDialogOpen" max-width="420px">
            <v-card>
                <v-card-title>Supprimer la facture</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette facture ? Cette action est irréversible.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="isDeleteDialogOpen = false">Annuler</v-btn>
                    <v-btn color="error" @click="confirmDeleteInvoice">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
