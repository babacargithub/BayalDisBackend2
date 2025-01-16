<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
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
    }
});

// Form for creating/editing orders
const orderForm = useForm({
    customer_id: '',
    product_id: '',
    quantity: '',
    should_be_delivered_at: '',
    commercial_id: '',
    livreur_id: ''
});

// Dialog states
const createDialog = ref(false);
const editDialog = ref(false);
const deleteDialog = ref(false);
const editingOrder = ref(null);
const orderToDelete = ref(null);

// Create order
const submitCreate = () => {
    orderForm.post(route('orders.store'), {
        onSuccess: () => {
            createDialog.value = false;
            orderForm.reset();
        },
    });
};

// Edit order
const openEditDialog = (order) => {
    editingOrder.value = order;
    orderForm.customer_id = order.customer_id;
    orderForm.product_id = order.product_id;
    orderForm.quantity = order.quantity;
    orderForm.should_be_delivered_at = order.should_be_delivered_at;
    orderForm.commercial_id = order.commercial_id;
    orderForm.livreur_id = order.livreur_id;
    editDialog.value = true;
};

const submitEdit = () => {
    orderForm.put(route('orders.update', editingOrder.value.id), {
        onSuccess: () => {
            editDialog.value = false;
            editingOrder.value = null;
            orderForm.reset();
        },
    });
};

// Delete order
const confirmDelete = (order) => {
    orderToDelete.value = order;
    deleteDialog.value = true;
};

const submitDelete = () => {
    if (orderToDelete.value) {
        orderForm.delete(route('orders.destroy', orderToDelete.value.id), {
            onSuccess: () => {
                deleteDialog.value = false;
                orderToDelete.value = null;
            },
        });
    }
};
</script>

<template>
    <Head title="Commandes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Commandes</h2>
                <v-btn color="primary" @click="createDialog = true">
                    Nouvelle Commande
                </v-btn>
            </div>
        </template>

        <v-container>
            <!-- Orders Table -->
            <v-card>
                <v-table>
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Produit</th>
                            <th>Quantité</th>
                            <th>Date de livraison</th>
                            <th>Commercial</th>
                            <th>Livreur</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                            <td>{{ order.customer?.name }}</td>
                            <td>{{ order.product?.name }}</td>
                            <td>{{ order.quantity }}</td>
                            <td>{{ new Date(order.should_be_delivered_at).toLocaleString() }}</td>
                            <td>{{ order.commercial?.name || '-' }}</td>
                            <td>{{ order.livreur?.name || '-' }}</td>
                            <td>
                                <v-btn icon="mdi-pencil" size="small" class="mr-2" @click="openEditDialog(order)" />
                                <v-btn icon="mdi-delete" size="small" color="error" @click="confirmDelete(order)" />
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card>

            <!-- Create Dialog -->
            <v-dialog v-model="createDialog" max-width="600px">
                <v-card>
                    <v-card-title>Nouvelle Commande</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="submitCreate">
                            <v-select
                                v-model="orderForm.customer_id"
                                :items="customers"
                                item-title="name"
                                item-value="id"
                                label="Client"
                                :error-messages="orderForm.errors.customer_id"
                                required
                            />
                            <v-select
                                v-model="orderForm.product_id"
                                :items="products"
                                item-title="name"
                                item-value="id"
                                label="Produit"
                                :error-messages="orderForm.errors.product_id"
                                required
                            />
                            <v-text-field
                                v-model="orderForm.quantity"
                                label="Quantité"
                                type="number"
                                :error-messages="orderForm.errors.quantity"
                                required
                            />
                            <v-text-field
                                v-model="orderForm.should_be_delivered_at"
                                label="Date de livraison"
                                type="datetime-local"
                                :error-messages="orderForm.errors.should_be_delivered_at"
                                required
                            />
                            <v-select
                                v-model="orderForm.commercial_id"
                                :items="commercials"
                                item-title="name"
                                item-value="id"
                                label="Commercial"
                                :error-messages="orderForm.errors.commercial_id"
                                clearable
                            />
                            <v-select
                                v-model="orderForm.livreur_id"
                                :items="livreurs"
                                item-title="name"
                                item-value="id"
                                label="Livreur"
                                :error-messages="orderForm.errors.livreur_id"
                                clearable
                            />
                        </v-form>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="error" @click="createDialog = false">Annuler</v-btn>
                        <v-btn 
                            color="primary" 
                            @click="submitCreate"
                            :loading="orderForm.processing"
                        >
                            Créer
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Edit Dialog -->
            <v-dialog v-model="editDialog" max-width="600px">
                <v-card>
                    <v-card-title>Modifier la Commande</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="submitEdit">
                            <v-select
                                v-model="orderForm.customer_id"
                                :items="customers"
                                item-title="name"
                                item-value="id"
                                label="Client"
                                :error-messages="orderForm.errors.customer_id"
                                required
                            />
                            <v-select
                                v-model="orderForm.product_id"
                                :items="products"
                                item-title="name"
                                item-value="id"
                                label="Produit"
                                :error-messages="orderForm.errors.product_id"
                                required
                            />
                            <v-text-field
                                v-model="orderForm.quantity"
                                label="Quantité"
                                type="number"
                                :error-messages="orderForm.errors.quantity"
                                required
                            />
                            <v-text-field
                                v-model="orderForm.should_be_delivered_at"
                                label="Date de livraison"
                                type="datetime-local"
                                :error-messages="orderForm.errors.should_be_delivered_at"
                                required
                            />
                            <v-select
                                v-model="orderForm.commercial_id"
                                :items="commercials"
                                item-title="name"
                                item-value="id"
                                label="Commercial"
                                :error-messages="orderForm.errors.commercial_id"
                                clearable
                            />
                            <v-select
                                v-model="orderForm.livreur_id"
                                :items="livreurs"
                                item-title="name"
                                item-value="id"
                                label="Livreur"
                                :error-messages="orderForm.errors.livreur_id"
                                clearable
                            />
                        </v-form>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="error" @click="editDialog = false">Annuler</v-btn>
                        <v-btn 
                            color="primary" 
                            @click="submitEdit"
                            :loading="orderForm.processing"
                        >
                            Mettre à jour
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Delete Confirmation Dialog -->
            <v-dialog v-model="deleteDialog" max-width="500px">
                <v-card>
                    <v-card-title>Confirmer la suppression</v-card-title>
                    <v-card-text>
                        Êtes-vous sûr de vouloir supprimer cette commande ?
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                        <v-btn 
                            color="error" 
                            @click="submitDelete"
                            :loading="orderForm.processing"
                        >
                            Supprimer
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-container>
    </AuthenticatedLayout>
</template> 