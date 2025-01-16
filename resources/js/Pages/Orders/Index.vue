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
                                <v-btn 
                                    icon="mdi-pencil" 
                                    variant="text" 
                                    color="primary"
                                    @click="openDialog(order)"
                                />
                                <v-btn 
                                    icon="mdi-delete" 
                                    variant="text" 
                                    color="error"
                                    @click="openDeleteDialog(order)"
                                />
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
        </v-container>
    </AuthenticatedLayout>
</template> 