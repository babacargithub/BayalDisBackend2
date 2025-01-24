<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    orders: {
        type: Array,
        required: true
    },
    customers: {
        type: Array,
        required: true
    },
    products: {
        type: Array,
        required: true
    },
    commercials: {
        type: Array,
        required: true
    },
    livreurs: {
        type: Array,
        required: true
    },
    statuses: {
        type: Array,
        required: true
    }
});

const dialog = ref(false);
const editedItem = ref(null);
const deleteDialog = ref(false);
const itemToDelete = ref(null);
const paymentDialog = ref(false);
const selectedOrder = ref(null);
const isSubmitting = ref(false);
const paymentsDialog = ref(false);
const selectedOrderPayments = ref(null);
const showSnackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('success');

const form = useForm({
    customer_id: '',
    product_id: '',
    commercial_id: '',
    livreur_id: '',
    quantity: '',
    should_be_delivered_at: '',
    status: 'WAITING',
    comment: '',
});

const statusForm = useForm({
    status: '',
});

const paymentForm = useForm({
    amount: '',
    payment_method: '',
    comment: ''
});

const formatDate = (date) => {
    return new Date(date).toLocaleString('fr-FR');
};

const getStatusColor = (status) => {
    switch (status) {
        case 'WAITING':
            return 'warning';
        case 'DELIVERED':
            return 'success';
        case 'CANCELLED':
            return 'error';
        default:
            return 'grey';
    }
};

const getStatusIcon = (status) => {
    switch (status) {
        case 'WAITING':
            return 'mdi-clock-outline';
        case 'DELIVERED':
            return 'mdi-check-circle';
        case 'CANCELLED':
            return 'mdi-close-circle';
        default:
            return 'mdi-help-circle';
    }
};

const openDialog = (item = null) => {
    editedItem.value = item;
    if (item) {
        form.customer_id = item.customer_id;
        form.product_id = item.product_id;
        form.commercial_id = item.commercial_id;
        form.livreur_id = item.livreur_id;
        form.quantity = item.quantity;
        form.should_be_delivered_at = item.should_be_delivered_at;
        form.status = item.status;
        form.comment = item.comment;
    } else {
        form.reset();
        form.status = 'WAITING';
    }
    dialog.value = true;
};

const openDeleteDialog = (item) => {
    itemToDelete.value = item;
    deleteDialog.value = true;
};

const submit = () => {
    if (editedItem.value) {
        form.put(route('orders.update', editedItem.value.id), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                editedItem.value = null;
            },
        });
    } else {
        form.post(route('orders.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            },
        });
    }
};

const deleteOrder = () => {
    if (itemToDelete.value) {
        form.delete(route('orders.destroy', itemToDelete.value.id), {
            onSuccess: () => {
                deleteDialog.value = false;
                itemToDelete.value = null;
            },
        });
    }
};

const updateStatus = (order, newStatus) => {
    statusForm.status = newStatus;
    statusForm.put(route('orders.update', order.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            statusForm.reset();
        },
    });
};

const openPaymentDialog = (order) => {
    selectedOrder.value = order;
    paymentDialog.value = true;
};

const submitPayment = () => {
    if (!selectedOrder.value) return;

    paymentForm.post(route('orders.payments.store', selectedOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            paymentDialog.value = false;
            paymentForm.reset();
            selectedOrder.value = null;
        },
        onError: (errors) => {
            console.error('Payment submission failed:', errors);
        }
    });
};

const openPaymentsDialog = (order) => {
    selectedOrderPayments.value = order;
    paymentsDialog.value = true;
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', { 
        style: 'currency', 
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
};

const getPaymentMethodColor = (method) => {
    switch (method) {
        case 'CASH':
            return 'success';
        case 'WAVE':
            return 'info';
        case 'OM':
            return 'warning';
        default:
            return 'grey';
    }
};

const createInvoice = (order) => {
    router.post(route('orders.create-invoice', order.id), {}, {
        onSuccess: () => {
            snackbarColor.value = 'success';
            snackbarText.value = 'Facture créée avec succès';
            showSnackbar.value = true;
            router.reload({ preserveScroll: true });
        },
        onError: (errors) => {
            snackbarColor.value = 'error';
            snackbarText.value = errors.error || 'Échec de la création de la facture';
            showSnackbar.value = true;
        }
    });
};
</script>

<template>
    <Head title="Commandes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Commandes</h2>
                <v-btn color="primary" @click="openDialog()">
                    Nouvelle commande
                </v-btn>
            </div>
        </template>

        <v-container>
            <v-card>
                <v-table>
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Produit</th>
                            <th>Quantité</th>
                            <th>Commercial</th>
                            <th>Livreur</th>
                            <th>Date de livraison</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                            <td>{{ order.customer?.name }}</td>
                            <td>{{ order.product?.name }}</td>
                            <td>{{ order.quantity }}</td>
                            <td>{{ order.commercial?.name || 'Non assigné' }}</td>
                            <td>{{ order.livreur?.name || 'Non assigné' }}</td>
                            <td>{{ formatDate(order.should_be_delivered_at) }}</td>
                            <td>
                                <div class="d-flex align-center">
                                    <v-menu>
                                        <template v-slot:activator="{ props: menu }">
                                            <v-chip
                                                v-bind="menu"
                                                :color="getStatusColor(order.status)"
                                                :prepend-icon="getStatusIcon(order.status)"
                                                class="cursor-pointer"
                                            >
                                                {{ statuses.find(s => s.value === order.status)?.text }}
                                            </v-chip>
                                        </template>
                                        <v-list>
                                            <v-list-item
                                                v-for="status in statuses"
                                                :key="status.value"
                                                :value="status.value"
                                                @click="updateStatus(order, status.value)"
                                                :active="order.status === status.value"
                                            >
                                                <template v-slot:prepend>
                                                    <v-icon :color="getStatusColor(status.value)">
                                                        {{ getStatusIcon(status.value) }}
                                                    </v-icon>
                                                </template>
                                                <v-list-item-title>{{ status.text }}</v-list-item-title>
                                            </v-list-item>
                                        </v-list>
                                    </v-menu>
                                    <v-tooltip
                                        v-if="order.comment"
                                        location="top"
                                        :text="order.comment"
                                    >
                                        <template v-slot:activator="{ props }">
                                            <v-icon
                                                v-bind="props"
                                                size="small"
                                                color="grey"
                                                class="ml-2"
                                            >
                                                mdi-comment-text-outline
                                            </v-icon>
                                        </template>
                                    </v-tooltip>
                                </div>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <v-btn 
                                        icon="mdi-pencil" 
                                        variant="text" 
                                        color="primary"
                                        @click="openDialog(order)"
                                    />
                                    <v-btn 
                                        icon="mdi-cash-plus" 
                                        variant="text" 
                                        color="success"
                                        @click="openPaymentDialog(order)"
                                        title="Ajouter un paiement"
                                    />
                                    <v-btn 
                                        icon="mdi-cash-multiple" 
                                        variant="text" 
                                        color="info"
                                        @click="openPaymentsDialog(order)"
                                        title="Voir les paiements"
                                    />
                                    <v-btn 
                                        icon="mdi-file-document-plus" 
                                        variant="text" 
                                        color="primary"
                                        @click="createInvoice(order)"
                                        :disabled="order.sales_invoice_id !== null"
                                        :title="order.sales_invoice_id ? 'Facture déjà créée' : 'Créer une facture'"
                                    />
                                    <v-btn 
                                        icon="mdi-delete" 
                                        variant="text" 
                                        color="error"
                                        @click="openDeleteDialog(order)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card>

            <!-- Create/Edit Dialog -->
            <v-dialog v-model="dialog" max-width="600px">
                <v-card>
                    <v-card-title>
                        {{ editedItem ? 'Modifier la commande' : 'Nouvelle commande' }}
                    </v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="submit">
                            <v-select
                                v-model="form.customer_id"
                                :items="customers"
                                item-title="name"
                                item-value="id"
                                label="Client"
                                :error-messages="form.errors.customer_id"
                            />
                            <v-select
                                v-model="form.product_id"
                                :items="products"
                                item-title="name"
                                item-value="id"
                                label="Produit"
                                :error-messages="form.errors.product_id"
                            />
                            <v-text-field
                                v-model="form.quantity"
                                label="Quantité"
                                type="number"
                                :error-messages="form.errors.quantity"
                            />
                            <v-select
                                v-model="form.commercial_id"
                                :items="commercials"
                                item-title="name"
                                item-value="id"
                                label="Commercial"
                                :error-messages="form.errors.commercial_id"
                                clearable
                            />
                            <v-select
                                v-model="form.livreur_id"
                                :items="livreurs"
                                item-title="name"
                                item-value="id"
                                label="Livreur"
                                :error-messages="form.errors.livreur_id"
                                clearable
                            />
                            <v-text-field
                                v-model="form.should_be_delivered_at"
                                label="Date de livraison"
                                type="datetime-local"
                                :error-messages="form.errors.should_be_delivered_at"
                            />
                            <v-select
                                v-model="form.status"
                                :items="statuses"
                                item-title="text"
                                item-value="value"
                                label="Statut"
                                :error-messages="form.errors.status"
                            />
                            <v-textarea
                                v-model="form.comment"
                                label="Commentaire"
                                :error-messages="form.errors.comment"
                                rows="3"
                                class="mt-2"
                                placeholder="Ajouter un commentaire (optionnel)"
                            />
                            <v-card-actions>
                                <v-spacer />
                                <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                                <v-btn 
                                    color="primary" 
                                    type="submit" 
                                    :loading="form.processing"
                                >
                                    {{ editedItem ? 'Mettre à jour' : 'Créer' }}
                                </v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Delete Confirmation Dialog -->
            <v-dialog v-model="deleteDialog" max-width="500px">
                <v-card>
                    <v-card-title>Supprimer la commande</v-card-title>
                    <v-card-text>
                        Êtes-vous sûr de vouloir supprimer cette commande ?
                        Cette action est irréversible.
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                        <v-btn 
                            color="error" 
                            @click="deleteOrder"
                            :loading="form.processing"
                        >
                            Confirmer
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Payment Dialog -->
            <v-dialog v-model="paymentDialog" max-width="500px">
                <v-card>
                    <v-card-title>Ajouter un paiement</v-card-title>
                    <v-card-text>
                        <div v-if="selectedOrder" class="mb-4">
                            <div class="font-weight-bold mb-2">Détails de la commande:</div>
                            <div>Client: {{ selectedOrder.customer?.name }}</div>
                            <div>Total: {{ formatCurrency(selectedOrder.total_amount) }}</div>
                            <div>Déjà payé: {{ formatCurrency(selectedOrder.paid_amount) }}</div>
                            <div>Reste à payer: {{ formatCurrency(selectedOrder.remaining_amount) }}</div>
                        </div>
                        <v-divider class="mb-4"></v-divider>
                        <v-form @submit.prevent="submitPayment">
                            <v-text-field
                                v-model.number="paymentForm.amount"
                                label="Montant"
                                type="number"
                                min="0"
                                :error-messages="paymentForm.errors.amount"
                                required
                            />
                            <v-select
                                v-model="paymentForm.payment_method"
                                :items="['CASH', 'WAVE', 'OM']"
                                label="Mode de paiement"
                                :error-messages="paymentForm.errors.payment_method"
                                required
                            />
                            <v-textarea
                                v-model="paymentForm.comment"
                                label="Commentaire"
                                :error-messages="paymentForm.errors.comment"
                            />
                            <v-alert
                                v-if="paymentForm.errors.error"
                                type="error"
                                class="mb-4"
                            >
                                {{ paymentForm.errors.error }}
                            </v-alert>
                            <v-card-actions>
                                <v-spacer />
                                <v-btn color="error" @click="paymentDialog = false">Annuler</v-btn>
                                <v-btn 
                                    color="success" 
                                    type="submit" 
                                    :loading="paymentForm.processing"
                                    :disabled="paymentForm.processing"
                                    @click="submitPayment"
                                >
                                    Enregistrer
                                </v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Payments List Dialog -->
            <v-dialog v-model="paymentsDialog" max-width="800px">
                <v-card>
                    <v-toolbar color="primary" class="text-white">
                        <v-toolbar-title>Historique des paiements</v-toolbar-title>
                        <v-spacer></v-spacer>
                        <v-btn icon="mdi-close" variant="text" @click="paymentsDialog = false" />
                    </v-toolbar>
                    
                    <v-card-text class="pt-4">
                        <div v-if="selectedOrderPayments" class="mb-4">
                            <v-row>
                                <v-col cols="12" sm="6" md="3">
                                    <div class="text-subtitle-2">Client</div>
                                    <div class="text-h6">{{ selectedOrderPayments.customer?.name }}</div>
                                </v-col>
                                <v-col cols="12" sm="6" md="3">
                                    <div class="text-subtitle-2">Total</div>
                                    <div class="text-h6">{{ formatCurrency(selectedOrderPayments.total_amount) }}</div>
                                </v-col>
                                <v-col cols="12" sm="6" md="3">
                                    <div class="text-subtitle-2">Déjà payé</div>
                                    <div class="text-h6">{{ formatCurrency(selectedOrderPayments.paid_amount) }}</div>
                                </v-col>
                                <v-col cols="12" sm="6" md="3">
                                    <div class="text-subtitle-2">Reste à payer</div>
                                    <div class="text-h6">{{ formatCurrency(selectedOrderPayments.remaining_amount) }}</div>
                                </v-col>
                            </v-row>
                            <v-row class="mt-2">
                                <v-col cols="12">
                                    <v-chip
                                        :color="selectedOrderPayments.is_fully_paid ? 'success' : 'warning'"
                                        class="mt-1"
                                    >
                                        {{ selectedOrderPayments.is_fully_paid ? 'Payé' : 'Paiement partiel' }}
                                    </v-chip>
                                </v-col>
                            </v-row>
                        </div>

                        <v-divider class="mb-4"></v-divider>

                        <div v-if="selectedOrderPayments?.payments?.length">
                            <v-table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Montant</th>
                                        <th>Mode de paiement</th>
                                        <th>Commentaire</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="payment in selectedOrderPayments.payments" :key="payment.id">
                                        <td>{{ formatDate(payment.created_at) }}</td>
                                        <td>{{ formatCurrency(payment.amount) }}</td>
                                        <td>
                                            <v-chip
                                                :color="getPaymentMethodColor(payment.payment_method)"
                                                size="small"
                                            >
                                                {{ payment.payment_method }}
                                            </v-chip>
                                        </td>
                                        <td>{{ payment.comment || '-' }}</td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="selectedOrderPayments.payments.length > 0">
                                    <tr>
                                        <td class="text-right font-weight-bold">Total</td>
                                        <td class="font-weight-bold">{{ formatCurrency(selectedOrderPayments.paid_amount) }}</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </v-table>
                        </div>
                        <div v-else class="text-center py-4">
                            <v-icon icon="mdi-cash-remove" size="48" color="grey" class="mb-2" />
                            <div class="text-grey">Aucun paiement enregistré</div>
                        </div>
                    </v-card-text>

                    <v-card-actions>
                        <v-spacer />
                        <v-btn
                            color="primary"
                            @click="openPaymentDialog(selectedOrderPayments)"
                            :disabled="selectedOrderPayments?.is_fully_paid"
                        >
                            <v-icon start>mdi-cash-plus</v-icon>
                            Ajouter un paiement
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Success/Error Notification Dialog -->
            <v-snackbar
                v-model="showSnackbar"
                :color="snackbarColor"
                :timeout="3000"
            >
                {{ snackbarText }}
                <template v-slot:actions>
                    <v-btn
                        variant="text"
                        @click="showSnackbar = false"
                    >
                        Fermer
                    </v-btn>
                </template>
            </v-snackbar>
        </v-container>
    </AuthenticatedLayout>
</template> 