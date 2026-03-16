<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div class="d-flex align-center gap-4">
                    <div class="text-h6">
                        Total des factures: {{ formatPrice(totalInvoicesAmount) }}
                    </div>
                    <v-btn color="primary" @click="showCreateDialog">
                        <v-icon>mdi-plus</v-icon>
                        Nouvelle facture
                    </v-btn>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Numéro</th>
                                <th>Fournisseur</th>
                                <th>Date</th>
                                <th>Échéance</th>
                                <th>Montant</th>
                                <th>Payé</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="invoice in purchaseInvoices" :key="invoice.id">
                                <td>{{ invoice.invoice_number }}</td>
                                <td>{{ invoice.supplier.name }}</td>
                                <td>{{ formatDate(invoice.invoice_date) }}</td>
                                <td>{{ formatDate(invoice.due_date) }}</td>
                                <td>{{ formatPrice(invoice.total_amount) }}</td>
                                <td>{{ formatPrice(invoice.paid_amount) }}</td>
                                <td>
                                    <v-chip
                                        :color="getStatusColor(invoice.status)"
                                        small
                                    >
                                        {{ getStatusLabel(invoice.status) }}
                                    </v-chip>
                                </td>
                                <td>
                                    <v-btn
                                        icon="mdi-eye"
                                        variant="text"
                                        color="info"
                                        class="mr-2"
                                        @click="viewInvoice(invoice)"
                                    />
                                    <v-btn
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        class="mr-2"
                                        @click="editInvoice(invoice)"
                                    />
                                    <v-btn
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        class="mr-2"
                                        @click="deleteInvoice(invoice)"
                                    />
                                    <v-btn
                                        icon="mdi-package-variant-closed"
                                        variant="text"
                                        color="success"
                                        class="mr-2"
                                        v-if="!invoice.is_stocked"
                                        @click="openPutInStockDialog(invoice)"
                                        v-tooltip="'Mettre en stock'"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <v-dialog v-model="dialog" max-width="900px">
            <v-card>
                <v-card-title>
                    {{ editingInvoice ? 'Modifier la facture' : 'Nouvelle facture' }}
                    <v-chip v-if="editingInvoice?.is_stocked" color="warning" class="ml-2" size="small">
                        En stock — articles verrouillés
                    </v-chip>
                </v-card-title>
                <v-card-text>
                    <div class="d-flex justify-end mb-4">
                        <div class="text-h6">
                            Total: {{ formatPrice(calculateFormTotal) }}
                        </div>
                    </div>
                    <v-form @submit.prevent="saveInvoice">
                        <v-row>
                            <v-col cols="12" md="6">
                                <v-select
                                    v-model="form.supplier_id"
                                    :items="suppliers"
                                    item-title="name"
                                    item-value="id"
                                    label="Fournisseur"
                                    required
                                    :error-messages="form.errors.supplier_id"
                                />
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-text-field
                                    v-model="form.invoice_number"
                                    label="Numéro de facture (optionnel)"
                                    :error-messages="form.errors.invoice_number"
                                    hint="Laissez vide pour générer automatiquement"
                                    persistent-hint
                                />
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-text-field
                                    v-model="form.invoice_date"
                                    label="Date de facture"
                                    type="date"
                                    required
                                    :error-messages="form.errors.invoice_date"
                                />
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-text-field
                                    v-model.number="form.transportation_cost"
                                    label="Frais de transport"
                                    type="number"
                                    min="0"
                                    :error-messages="form.errors.transportation_cost"
                                />
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-checkbox
                                    v-model="form.is_paid"
                                    label="Facture payée"
                                    hide-details
                                />
                            </v-col>
                            <v-col cols="12" md="6" v-if="!form.is_paid">
                                <v-text-field
                                    v-model="form.due_date"
                                    label="Date d'échéance"
                                    type="date"
                                    :error-messages="form.errors.due_date"
                                />
                            </v-col>
                        </v-row>

                        <div class="mt-4">
                            <div class="d-flex justify-space-between align-center mb-4">
                                <h3 class="text-h6">Articles</h3>
                            </div>

                            <div v-for="(item, index) in form.items" :key="index" class="mb-4">
                                <v-row>
                                    <v-col cols="12" md="4">
                                        <v-select
                                            v-model="item.product_id"
                                            :items="products"
                                            item-title="name"
                                            item-value="id"
                                            label="Produit"
                                            required
                                            :disabled="!!editingInvoice?.is_stocked"
                                            :error-messages="form.errors[`items.${index}.product_id`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="2">
                                        <v-text-field
                                            v-model.number="item.quantity"
                                            label="Quantité"
                                            type="number"
                                            required
                                            :disabled="!!editingInvoice?.is_stocked"
                                            :error-messages="form.errors[`items.${index}.quantity`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="2">
                                        <v-text-field
                                            v-model.number="item.unit_price"
                                            label="Prix unitaire"
                                            type="number"
                                            required
                                            :disabled="!!editingInvoice?.is_stocked"
                                            :error-messages="form.errors[`items.${index}.unit_price`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="3">
                                        <div class="d-flex align-center h-100">
                                            <span class="text-subtitle-1 text-gray-600">
                                               {{ formatPrice(calculateItemTotal(item)) }}
                                            </span>
                                        </div>
                                    </v-col>
                                    <v-col cols="12" md="1">
                                        <v-btn
                                            v-if="!editingInvoice?.is_stocked"
                                            icon="mdi-delete"
                                            variant="text"
                                            color="error"
                                            @click="removeItem(index)"
                                        />
                                    </v-col>
                                </v-row>
                            </div>
                            <div v-if="!editingInvoice?.is_stocked" class="d-flex justify-center">
                                <v-btn icon color="primary" @click="addItem">
                                    <v-icon>mdi-plus</v-icon>
                                </v-btn>
                            </div>
                        </div>

                        <div class="d-flex justify-end mt-4 mb-4">
                            <div class="text-h6">
                                 {{ formatPrice(calculateFormTotal) }}
                            </div>
                        </div>

                        <v-textarea
                            v-model="form.comment"
                            label="Notes"
                            :error-messages="form.errors.comment"
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="closeDialog">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        @click="saveInvoice"
                        :loading="form.processing"
                    >
                        {{ editingInvoice ? 'Modifier' : 'Créer' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title>Supprimer la facture</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette facture ?
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        @click="confirmDelete"
                        :loading="deleteForm.processing"
                    >
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- View Invoice Details Dialog -->
        <v-dialog v-model="viewDialog" max-width="900px">
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <span>Détails de la facture</span>
                    <div class="text-h6">
                        Total: {{ formatPrice(selectedInvoice?.total_amount || 0) }}
                    </div>
                </v-card-title>
                <v-card-text>
                    <v-row class="mb-4">
                        <v-col cols="12" md="6">
                            <div class="text-subtitle-1"><strong>Fournisseur:</strong> {{ selectedInvoice?.supplier?.name }}</div>
                            <div class="text-subtitle-1"><strong>Numéro:</strong> {{ selectedInvoice?.invoice_number }}</div>
                        </v-col>
                        <v-col cols="12" md="6">
                            <div class="text-subtitle-1"><strong>Date:</strong> {{ formatDate(selectedInvoice?.invoice_date) }}</div>
                            <div class="text-subtitle-1"><strong>Échéance:</strong> {{ formatDate(selectedInvoice?.due_date) }}</div>
                        </v-col>
                    </v-row>

                    <v-table>
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th class="text-right">Quantité</th>
                                <th class="text-right">Prix unitaire</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in selectedInvoice?.items" :key="item.id">
                                <td>{{ item.product?.name }}</td>
                                <td class="text-right">{{ item.quantity }}</td>
                                <td class="text-right">{{ formatPrice(item.unit_price) }}</td>
                                <td class="text-right">{{ formatPrice(item.total_price) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right font-weight-bold">Total</td>
                                <td class="text-right font-weight-bold">{{ formatPrice(selectedInvoice?.total_amount || 0) }}</td>
                            </tr>
                        </tfoot>
                    </v-table>

                    <div v-if="selectedInvoice?.comment" class="mt-4">
                        <div class="font-weight-bold mb-2">Commentaire:</div>
                        <div>{{ selectedInvoice.comment }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="viewDialog = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Put In Stock Dialog -->
        <v-dialog v-model="putInStockDialog" max-width="600px">
            <v-card>
                <v-card-title>Mettre en stock</v-card-title>
                <v-card-text>
                    <p class="mb-4 text-body-2 text-medium-emphasis">
                        Choisissez un chargement pour transférer les articles directement dans un chargement,
                        ou sélectionnez un entrepôt pour les conserver en stock entrepôt.
                    </p>

                    <v-radio-group v-model="putInStockForm.car_load_id" label="Destination">
                        <v-radio
                            :value="null"
                            label="Entrepôt seulement (pas de chargement)"
                        />
                        <v-radio
                            v-for="carLoad in activeCarLoads"
                            :key="carLoad.id"
                            :value="carLoad.id"
                            :label="`${carLoad.name} — ${carLoad.team?.name} (${getCarLoadStatusLabel(carLoad.status)})`"
                        />
                    </v-radio-group>

                    <v-select
                        v-if="putInStockForm.car_load_id === null"
                        v-model="putInStockForm.warehouse_id"
                        :items="warehouses"
                        item-title="name"
                        item-value="id"
                        label="Entrepôt de destination"
                        clearable
                        :error-messages="putInStockForm.errors.warehouse_id"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="putInStockDialog = false">Annuler</v-btn>
                    <v-btn
                        color="success"
                        @click="confirmPutInStock"
                        :loading="putInStockForm.processing"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    purchaseInvoices: {
        type: Array,
        default: () => []
    },
    suppliers: {
        type: Array,
        default: () => []
    },
    products: {
        type: Array,
        default: () => []
    },
    activeCarLoads: {
        type: Array,
        default: () => []
    },
    warehouses: {
        type: Array,
        default: () => []
    }
});

const dialog = ref(false);
const deleteDialog = ref(false);
const editingInvoice = ref(null);
const invoiceToDelete = ref(null);
const viewDialog = ref(false);
const selectedInvoice = ref(null);
const putInStockDialog = ref(false);
const invoiceForPutInStock = ref(null);

const form = useForm({
    supplier_id: '',
    invoice_number: '',
    invoice_date: '',
    due_date: '',
    comment: '',
    transportation_cost: 0,
    is_paid: true,
    items: []
});

const deleteForm = useForm({});

const putInStockForm = useForm({
    car_load_id: null,
    warehouse_id: null,
});

const totalInvoicesAmount = computed(() => {
    return props.purchaseInvoices.reduce((total, invoice) => {
        return total + (invoice.total_amount || 0);
    }, 0);
});

const calculateItemTotal = (item) => {
    return (item.quantity || 0) * (item.unit_price || 0);
};

const calculateFormTotal = computed(() => {
    return form.items.reduce((total, item) => {
        return total + calculateItemTotal(item);
    }, 0);
});

const DRAFT_KEY = 'purchase_invoice_draft';
const isRestoringDraft = ref(false);

// Watch for form changes and save to localStorage
watch(() => ({
    supplier_id: form.supplier_id,
    invoice_number: form.invoice_number,
    invoice_date: form.invoice_date,
    due_date: form.due_date,
    comment: form.comment,
    transportation_cost: form.transportation_cost,
    is_paid: form.is_paid,
    items: form.items
}), (newValue) => {
    if (!editingInvoice.value && !isRestoringDraft.value) {
        localStorage.setItem(DRAFT_KEY, JSON.stringify(newValue));
    }
}, { deep: true });

// Clear draft when form is successfully submitted
function clearDraft() {
    localStorage.removeItem(DRAFT_KEY);
}

// Restore draft when component is mounted
onMounted(() => {
    const draft = localStorage.getItem(DRAFT_KEY);
    if (draft) {
        const shouldRestore = window.confirm('Un brouillon de facture non enregistré a été trouvé. Voulez-vous le restaurer?');
        if (shouldRestore) {
            isRestoringDraft.value = true;
            const draftData = JSON.parse(draft);
            form.supplier_id = draftData.supplier_id;
            form.invoice_number = draftData.invoice_number;
            form.invoice_date = draftData.invoice_date;
            form.due_date = draftData.due_date;
            form.comment = draftData.comment;
            form.transportation_cost = draftData.transportation_cost ?? 0;
            form.is_paid = draftData.is_paid ?? true;
            form.items = draftData.items;
            dialog.value = true;
            isRestoringDraft.value = false;
        } else {
            clearDraft();
        }
    }
});

function showCreateDialog() {
    editingInvoice.value = null;
    form.reset();
    form.is_paid = true;
    form.transportation_cost = 0;
    form.items = [{ product_id: '', quantity: 1, unit_price: 0 }];
    dialog.value = true;
}

function editInvoice(invoice) {
    editingInvoice.value = invoice;
    form.supplier_id = invoice.supplier_id;
    form.invoice_number = invoice.invoice_number;
    form.invoice_date = formatDateForInput(invoice.invoice_date);
    form.due_date = formatDateForInput(invoice.due_date);
    form.comment = invoice.comment;
    form.transportation_cost = invoice.transportation_cost ?? 0;
    form.is_paid = invoice.is_paid ?? false;
    form.items = invoice.items.map(item => ({
        product_id: item.product_id,
        quantity: item.quantity,
        unit_price: item.unit_price
    }));
    dialog.value = true;
}

function viewInvoice(invoice) {
    selectedInvoice.value = invoice;
    viewDialog.value = true;
}

function deleteInvoice(invoice) {
    invoiceToDelete.value = invoice;
    deleteDialog.value = true;
}

function confirmDelete() {
    deleteForm.delete(route('purchase-invoices.destroy', invoiceToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            invoiceToDelete.value = null;
        }
    });
}

function addItem() {
    form.items.push({ product_id: '', quantity: 1, unit_price: 0 });
}

function removeItem(index) {
    form.items.splice(index, 1);
}

function saveInvoice() {
    if (editingInvoice.value) {
        form.put(route('purchase-invoices.update', editingInvoice.value.id), {
            onSuccess: () => {
                dialog.value = false;
                editingInvoice.value = null;
                form.reset();
                clearDraft();
            }
        });
    } else {
        form.post(route('purchase-invoices.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                clearDraft();
            }
        });
    }
}

function openPutInStockDialog(invoice) {
    invoiceForPutInStock.value = invoice;
    putInStockForm.reset();
    putInStockDialog.value = true;
}

function confirmPutInStock() {
    putInStockForm.post(route('purchase-invoices.put-in-stock', invoiceForPutInStock.value.id), {
        onSuccess: () => {
            putInStockDialog.value = false;
            invoiceForPutInStock.value = null;
            putInStockForm.reset();
        }
    });
}

function formatDate(date) {
    if (!date) return '';
    return new Date(date).toLocaleDateString('fr-FR');
}

function formatPrice(price) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(price);
}

function getStatusColor(status) {
    switch (status) {
        case 'paid':
            return 'success';
        case 'partially_paid':
            return 'warning';
        default:
            return 'error';
    }
}

function getStatusLabel(status) {
    switch (status) {
        case 'paid':
            return 'Payée';
        case 'partially_paid':
            return 'Partiellement payée';
        default:
            return 'En attente';
    }
}

function getCarLoadStatusLabel(status) {
    switch (status) {
        case 'LOADING':
            return 'En chargement';
        case 'SELLING':
            return 'En vente';
        case 'ONGOING_INVENTORY':
            return 'Inventaire en cours';
        default:
            return status;
    }
}

function formatDateForInput(date) {
    if (!date) return '';
    return new Date(date).toISOString().split('T')[0];
}

function discardDraft() {
    if (window.confirm('Êtes-vous sûr de vouloir supprimer ce brouillon ?')) {
        clearDraft();
        form.reset();
        form.is_paid = true;
        form.transportation_cost = 0;
        form.items = [{ product_id: '', quantity: 1, unit_price: 0 }];
        dialog.value = false;
    }
}

function closeDialog() {
    if (!editingInvoice.value && form.isDirty) {
        if (window.confirm('Voulez-vous sauvegarder ce brouillon pour plus tard ?')) {
            dialog.value = false;
        } else {
            clearDraft();
            dialog.value = false;
        }
    } else {
        dialog.value = false;
    }
}
</script>
