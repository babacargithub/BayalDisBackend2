<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Factures d'achat</h2>
                <v-btn color="primary" @click="showCreateDialog">
                    <v-icon>mdi-plus</v-icon>
                    Nouvelle facture
                </v-btn>
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
                                        @click="deleteInvoice(invoice)"
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
                </v-card-title>
                <v-card-text>
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
                                    label="Numéro de facture"
                                    required
                                    :error-messages="form.errors.invoice_number"
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
                                <v-btn color="primary" @click="addItem">
                                    <v-icon>mdi-plus</v-icon>
                                    Ajouter un article
                                </v-btn>
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
                                            :error-messages="form.errors[`items.${index}.product_id`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="2">
                                        <v-text-field
                                            v-model.number="item.quantity"
                                            label="Quantité"
                                            type="number"
                                            required
                                            :error-messages="form.errors[`items.${index}.quantity`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="2">
                                        <v-text-field
                                            v-model.number="item.unit_price"
                                            label="Prix unitaire"
                                            type="number"
                                            required
                                            :error-messages="form.errors[`items.${index}.unit_price`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="3">
                                        <v-text-field
                                            v-model="item.description"
                                            label="Description"
                                            :error-messages="form.errors[`items.${index}.description`]"
                                        />
                                    </v-col>
                                    <v-col cols="12" md="1">
                                        <v-btn
                                            icon="mdi-delete"
                                            variant="text"
                                            color="error"
                                            @click="removeItem(index)"
                                        />
                                    </v-col>
                                </v-row>
                            </div>
                        </div>

                        <v-textarea
                            v-model="form.notes"
                            label="Notes"
                            :error-messages="form.errors.notes"
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="dialog = false">Annuler</v-btn>
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
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
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
    }
});

const dialog = ref(false);
const deleteDialog = ref(false);
const editingInvoice = ref(null);
const invoiceToDelete = ref(null);

const form = useForm({
    supplier_id: '',
    invoice_number: '',
    invoice_date: '',
    due_date: '',
    notes: '',
    items: []
});

const deleteForm = useForm({});

function showCreateDialog() {
    editingInvoice.value = null;
    form.reset();
    form.items = [{ product_id: '', quantity: 1, unit_price: 0, description: '' }];
    dialog.value = true;
}

function editInvoice(invoice) {
    editingInvoice.value = invoice;
    form.supplier_id = invoice.supplier_id;
    form.invoice_number = invoice.invoice_number;
    form.invoice_date = invoice.invoice_date;
    form.due_date = invoice.due_date;
    form.notes = invoice.notes;
    form.items = invoice.items.map(item => ({
        product_id: item.product_id,
        quantity: item.quantity,
        unit_price: item.unit_price,
        description: item.description
    }));
    dialog.value = true;
}

function viewInvoice(invoice) {
    window.location.href = route('purchase-invoices.show', invoice.id);
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
    form.items.push({ product_id: '', quantity: 1, unit_price: 0, description: '' });
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
            }
        });
    } else {
        form.post(route('purchase-invoices.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            }
        });
    }
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
</script> 