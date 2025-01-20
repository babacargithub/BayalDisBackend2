<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import axios from 'axios';

const props = defineProps({
    batches: Array,
    livreurs: Array,
    customers: Array,
    products: Array,
});

const showingNewBatchModal = ref(false);
const showingEditBatchModal = ref(false);
const showingAddOrdersModal = ref(false);
const selectedBatch = ref(null);
const availableOrders = ref([]);
const selectedOrders = ref([]);

const form = useForm({
    name: '',
    delivery_date: null,
    livreur_id: null,
});

const editForm = useForm({
    name: '',
    delivery_date: null,
    livreur_id: null,
});

const showingOrdersModal = ref(false);
const showingNewOrderModal = ref(false);
const currentBatch = ref(null);

const orderForm = useForm({
    customer_id: '',
    product_id: '',
    quantity: 1,
    delivery_batch_id: null,
    should_be_delivered_at: null,
    status: 'WAITING',
});

const getProductTotals = (orders) => {
    if (!orders) return [];
    const totals = {};
    orders.forEach(order => {
        const productName = order.product?.name || 'Produit inconnu';
        if (!totals[productName]) {
            totals[productName] = 0;
        }
        totals[productName] += order.quantity;
    });
    return Object.entries(totals).map(([name, quantity]) => ({ name, quantity }));
};

const getStatusTotals = (orders) => {
    if (!orders) return { delivered: 0, pending: 0, cancelled: 0 };
    return orders.reduce((acc, order) => {
        switch (order.status) {
            case 'DELIVERED':
                acc.delivered++;
                break;
            case 'WAITING':
                acc.pending++;
                break;
            case 'CANCELLED':
                acc.cancelled++;
                break;
        }
        return acc;
    }, { delivered: 0, pending: 0, cancelled: 0 });
};

const createBatch = () => {
    form.post(route('delivery-batches.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showingNewBatchModal.value = false;
            form.reset();
        },
    });
};

const editBatch = () => {
    editForm.put(route('delivery-batches.update', selectedBatch.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showingEditBatchModal.value = false;
            editForm.reset();
            selectedBatch.value = null;
        },
    });
};

const deleteBatch = (batch) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce lot ?')) {
        router.delete(route('delivery-batches.destroy', batch.id), {
            preserveScroll: true,
        });
    }
};

const openEditModal = (batch) => {
    selectedBatch.value = batch;
    editForm.name = batch.name;
    editForm.delivery_date = batch.delivery_date;
    editForm.livreur_id = batch.livreur_id;
    showingEditBatchModal.value = true;
};

const openAddOrdersModal = async (batch) => {
    selectedBatch.value = batch;
    selectedOrders.value = [];
    
    // Load available orders
    try {
        const response = await axios.get(route('delivery-batches.available-orders'));
        availableOrders.value = response.data.orders;
        showingAddOrdersModal.value = true;
    } catch (error) {
        console.error('Error loading available orders:', error);
    }
};

const addOrders = () => {
    if (selectedOrders.value.length === 0) {
        alert('Veuillez sélectionner au moins une commande');
        return;
    }

    router.post(
        route('delivery-batches.add-orders', selectedBatch.value.id),
        { order_ids: selectedOrders.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showingAddOrdersModal.value = false;
                selectedOrders.value = [];
            },
        }
    );
};

const removeOrder = (batch, order) => {
    if (confirm('Êtes-vous sûr de vouloir retirer cette commande du lot ?')) {
        router.delete(route('delivery-batches.remove-order', { 
            deliveryBatch: batch.id, 
            order: order.id 
        }), {
            preserveScroll: true,
            preserveState: true,
        });
    }
};

const assignLivreur = (batch, livreurId) => {
    router.post(
        route('delivery-batches.assign-livreur', batch.id),
        { livreur_id: livreurId },
        { preserveScroll: true }
    );
};

const openOrdersModal = (batch) => {
    currentBatch.value = batch;
    showingOrdersModal.value = true;
};

const openNewOrderModal = (batch) => {
    currentBatch.value = batch;
    orderForm.reset();
    orderForm.delivery_batch_id = batch.id;
    showingNewOrderModal.value = true;
};

const createOrder = () => {
    // Set the delivery date from the batch
    orderForm.should_be_delivered_at = currentBatch.value.delivery_date;
    orderForm.delivery_batch_id = currentBatch.value.id;
    orderForm.status = 'WAITING';
    
    orderForm.post(route('orders.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showingNewOrderModal.value = false;
            orderForm.reset();
            currentBatch.value = null;
        },
        onError: (errors) => {
            console.error('Error creating order:', errors);
        }
    });
};

const exportToPdf = async (batch) => {
    try {
        const response = await axios.get(
            route('delivery-batches.export-pdf', batch.id),
            { responseType: 'blob' }
        );
        
        // Create a blob from the PDF data
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = window.URL.createObjectURL(blob);
        
        // Create a link and trigger download
        const link = document.createElement('a');
        link.href = url;
        link.download = `lot-${batch.name}-${new Date().toISOString().split('T')[0]}.pdf`;
        document.body.appendChild(link);
        link.click();
        
        // Cleanup
        window.URL.revokeObjectURL(url);
        document.body.removeChild(link);
    } catch (error) {
        console.error('Error exporting PDF:', error);
        alert('Erreur lors de l\'export du PDF');
    }
};

const customersForSelect = computed(() => {
    return props.customers.map(customer => ({
        ...customer,
        displayText: `${customer.name} (${customer.phone_number || 'Pas de téléphone'})`
    }));
});

const customerFilter = (item, queryText) => {
    const searchText = queryText.toLowerCase();
    return item.name.toLowerCase().includes(searchText) || 
           (item.phone_number && item.phone_number.toLowerCase().includes(searchText));
};
</script>

<template>
    <Head title="Lots de livraison" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Lots de livraison
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="mb-4">
                            <PrimaryButton @click="showingNewBatchModal = true">
                                Nouveau lot de livraison
                            </PrimaryButton>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Nom
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date de livraison
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Livreur
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Commandes
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="batch in batches" :key="batch.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ batch.name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ batch.delivery_date || 'Non définie' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select
                                                v-model="batch.livreur_id"
                                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                                @change="assignLivreur(batch, $event.target.value)"
                                            >
                                                <option value="">Sélectionner un livreur</option>
                                                <option v-for="livreur in livreurs" :key="livreur.id" :value="livreur.id">
                                                    {{ livreur.name }}
                                                </option>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-col space-y-2">
                                                <div class="flex items-center space-x-2">
                                                    <PrimaryButton @click="openOrdersModal(batch)" class="text-sm">
                                                        {{ batch.orders?.length || 0 }} commande(s)
                                                    </PrimaryButton>
                                                    <SecondaryButton @click="openAddOrdersModal(batch)" class="text-sm">
                                                        Ajouter des commandes
                                                    </SecondaryButton>
                                                    <SecondaryButton @click="openNewOrderModal(batch)" class="text-sm">
                                                        Nouvelle commande
                                                    </SecondaryButton>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button @click="openEditModal(batch)" class="text-indigo-600 hover:text-indigo-900 mr-4">
                                                Modifier
                                            </button>
                                            <button @click="deleteBatch(batch)" class="text-red-600 hover:text-red-900">
                                                Supprimer
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New Batch Modal -->
        <Modal :show="showingNewBatchModal" @close="showingNewBatchModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Nouveau lot de livraison
                </h2>

                <div class="mt-6">
                    <form @submit.prevent="createBatch">
                        <div>
                            <InputLabel for="name" value="Nom" />
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="delivery_date" value="Date de livraison" />
                            <TextInput
                                id="delivery_date"
                                v-model="form.delivery_date"
                                type="date"
                                class="mt-1 block w-full"
                            />
                            <InputError :message="form.errors.delivery_date" class="mt-2" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="livreur" value="Livreur" />
                            <select
                                id="livreur"
                                v-model="form.livreur_id"
                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                            >
                                <option value="">Sélectionner un livreur</option>
                                <option v-for="livreur in livreurs" :key="livreur.id" :value="livreur.id">
                                    {{ livreur.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.livreur_id" class="mt-2" />
                        </div>

                        <div class="mt-6 flex justify-end">
                            <SecondaryButton @click="showingNewBatchModal = false" class="mr-3">
                                Annuler
                            </SecondaryButton>
                            <PrimaryButton :disabled="form.processing">
                                Créer
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Modal>

        <!-- Edit Batch Modal -->
        <Modal :show="showingEditBatchModal" @close="showingEditBatchModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Modifier le lot de livraison
                </h2>

                <div class="mt-6">
                    <form @submit.prevent="editBatch">
                        <div>
                            <InputLabel for="edit_name" value="Nom" />
                            <TextInput
                                id="edit_name"
                                v-model="editForm.name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError :message="editForm.errors.name" class="mt-2" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="edit_delivery_date" value="Date de livraison" />
                            <TextInput
                                id="edit_delivery_date"
                                v-model="editForm.delivery_date"
                                type="date"
                                class="mt-1 block w-full"
                            />
                            <InputError :message="editForm.errors.delivery_date" class="mt-2" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="edit_livreur" value="Livreur" />
                            <select
                                id="edit_livreur"
                                v-model="editForm.livreur_id"
                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                            >
                                <option value="">Sélectionner un livreur</option>
                                <option v-for="livreur in livreurs" :key="livreur.id" :value="livreur.id">
                                    {{ livreur.name }}
                                </option>
                            </select>
                            <InputError :message="editForm.errors.livreur_id" class="mt-2" />
                        </div>

                        <div class="mt-6 flex justify-end">
                            <SecondaryButton @click="showingEditBatchModal = false" class="mr-3">
                                Annuler
                            </SecondaryButton>
                            <PrimaryButton :disabled="editForm.processing">
                                Mettre à jour
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Modal>

        <!-- Add Orders Modal -->
        <Modal :show="showingAddOrdersModal" @close="showingAddOrdersModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Ajouter des commandes au lot
                </h2>

                <div class="mt-6">
                    <div class="space-y-4">
                        <div v-for="order in availableOrders" :key="order.id" class="flex items-center">
                            <input
                                type="checkbox"
                                :value="order.id"
                                v-model="selectedOrders"
                                class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                            />
                            <label class="ml-3">
                                {{ order.customer?.name || 'Client inconnu' }} - 
                                {{ order.product?.name || 'Produit inconnu' }} 
                                ({{ order.quantity }})
                            </label>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="showingAddOrdersModal = false" class="mr-3">
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton @click="addOrders">
                            Ajouter les commandes
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- Orders Modal -->
        <Modal :show="showingOrdersModal" @close="showingOrdersModal = false">
            <div class="p-6">
                <div class="flex justify-between items-center">
                    <h2 class="text-lg font-medium text-gray-900">
                        Commandes du lot {{ currentBatch?.name }}
                    </h2>
                    <PrimaryButton 
                        @click="exportToPdf(currentBatch)"
                        class="ml-4"
                        v-if="currentBatch?.orders?.length"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Exporter en PDF
                    </PrimaryButton>
                </div>

                <div v-if="currentBatch?.orders?.length" class="mt-4 space-y-4">
                    <!-- Status totals -->
                    <div class="grid grid-cols-3 gap-4">
                        <div class="bg-green-50 p-3 rounded-lg">
                            <div class="text-sm text-green-700 font-medium">Livrées</div>
                            <div class="text-2xl text-green-800">{{ getStatusTotals(currentBatch.orders).delivered }}</div>
                        </div>
                        <div class="bg-yellow-50 p-3 rounded-lg">
                            <div class="text-sm text-yellow-700 font-medium">En attente</div>
                            <div class="text-2xl text-yellow-800">{{ getStatusTotals(currentBatch.orders).pending }}</div>
                        </div>
                        <div class="bg-red-50 p-3 rounded-lg">
                            <div class="text-sm text-red-700 font-medium">Annulées</div>
                            <div class="text-2xl text-red-800">{{ getStatusTotals(currentBatch.orders).cancelled }}</div>
                        </div>
                    </div>

                    <!-- Product totals -->
                    <div class="p-4 bg-gray-100 rounded-lg">
                        <h3 class="font-medium text-gray-700 mb-2">Total par produit:</h3>
                        <div class="space-y-1">
                            <div v-for="total in getProductTotals(currentBatch.orders)" :key="total.name" class="text-sm">
                                <span class="font-medium">{{ total.name }}:</span> {{ total.quantity }} unité(s)
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="space-y-4">
                        <div v-for="order in currentBatch?.orders" :key="order.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium">{{ order.customer?.name || 'Client inconnu' }}</p>
                                <p class="text-sm text-gray-600">
                                    {{ order.product?.name || 'Produit inconnu' }} - {{ order.quantity }} unité(s)
                                </p>
                                <p class="text-xs" :class="{
                                    'text-green-600': order.status === 'DELIVERED',
                                    'text-yellow-600': order.status === 'WAITING',
                                    'text-red-600': order.status === 'CANCELLED'
                                }">
                                    {{ order.status === 'DELIVERED' ? 'Livrée' : 
                                       order.status === 'WAITING' ? 'En attente' : 'Annulée' }}
                                </p>
                            </div>
                            <button 
                                @click="removeOrder(currentBatch, order)" 
                                class="text-red-600 hover:text-red-900 p-2 rounded-full hover:bg-red-50"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="showingOrdersModal = false">
                            Fermer
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- New Order Modal -->
        <Modal :show="showingNewOrderModal" @close="showingNewOrderModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Nouvelle commande pour le lot {{ currentBatch?.name }}
                </h2>

                <form @submit.prevent="createOrder" class="mt-6">
                    <div>
                        <InputLabel for="customer" value="Client" />
                        <v-autocomplete
                            v-model="orderForm.customer_id"
                            :items="customersForSelect"
                            item-title="displayText"
                            item-value="id"
                            :error-messages="orderForm.errors.customer_id"
                            label="Sélectionner un client"
                            placeholder="Commencer à taper pour rechercher..."
                            :filter="customerFilter"
                            clearable
                            class="mt-1"
                        >
                            <!-- <template v-slot:item="{ props, item }">
                                <v-list-item v-bind="props">
                                    <v-list-item-title>{{ item.raw.name }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ item.raw.phone_number || 'Pas de téléphone' }}</v-list-item-subtitle>
                                </v-list-item>
                            </template> -->
                        </v-autocomplete>
                    </div>

                    <div class="mt-4">
                        <InputLabel for="product" value="Produit" />
                        <select
                            id="product"
                            v-model="orderForm.product_id"
                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                        >
                            <option value="">Sélectionner un produit</option>
                            <option v-for="product in products" :key="product.id" :value="product.id">
                                {{ product.name }}
                            </option>
                        </select>
                        <InputError :message="orderForm.errors.product_id" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="quantity" value="Quantité" />
                        <TextInput
                            id="quantity"
                            type="number"
                            v-model="orderForm.quantity"
                            class="mt-1 block w-full"
                            min="1"
                        />
                        <InputError :message="orderForm.errors.quantity" class="mt-2" />
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="showingNewOrderModal = false" class="mr-3">
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton :disabled="orderForm.processing">
                            Créer la commande
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template> 