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

const statusFilters = ref({
    DELIVERED: true,
    WAITING: true,
    CANCELLED: true,
});

const filteredBatchOrders = computed(() => {
    if (!currentBatch.value?.orders) return [];
    return currentBatch.value.orders.filter(order => statusFilters.value[order.status]);
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
    items: [{ product_id: '', quantity: 1, price: null }],
    delivery_batch_id: null,
    should_be_delivered_at: null,
    status: 'WAITING',
});

const localBatches = ref(props.batches);

const getProductTotals = (orders) => {
    const totals = {};
    orders.forEach(order => {
        order.items.forEach(item => {
            const productName = item.product.name;
            if (!totals[productName]) {
                totals[productName] = {
                    name: productName,
                    total_quantity: 0,
                    by_status: {
                        DELIVERED: 0,
                        WAITING: 0,
                        CANCELLED: 0,
                    }
                };
            }
            totals[productName].total_quantity += item.quantity;
            totals[productName].by_status[order.status] += item.quantity;
        });
    });
    return Object.values(totals);
};

const getStatusTotals = (orders) => {
    const totals = {
        DELIVERED: { count: 0, quantity: 0 },
        WAITING: { count: 0, quantity: 0 },
        CANCELLED: { count: 0, quantity: 0 },
    };

    orders.forEach(order => {
        totals[order.status].count++;
        order.items.forEach(item => {
            totals[order.status].quantity += item.quantity;
        });
    });

    return totals;
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
            onSuccess: () => {
                // Update the local batches
                const batchIndex = localBatches.value.findIndex(b => b.id === batch.id);
                if (batchIndex !== -1) {
                    // Create a new array reference for reactivity
                    const updatedBatch = { ...localBatches.value[batchIndex] };
                    updatedBatch.orders = updatedBatch.orders.filter(o => o.id !== order.id);
                    
                    // Update both local batches and current batch
                    localBatches.value[batchIndex] = updatedBatch;
                    if (currentBatch.value?.id === batch.id) {
                        currentBatch.value = { ...updatedBatch };
                    }
                }
            }
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
    // Find the batch in our local copy
    const localBatch = localBatches.value.find(b => b.id === batch.id);
    currentBatch.value = { ...localBatch };
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

const addItem = () => {
    orderForm.items.push({ product_id: '', quantity: null, price: null });
};

const removeItem = (index) => {
    if (orderForm.items.length > 1) {
        orderForm.items.splice(index, 1);
    }
};

const showAddItemModal = ref(false);
const currentOrder = ref(null);
const itemForm = useForm({
    product_id: '',
    quantity: 1,
    price: null
});

const openAddItemModal = (order) => {
    currentOrder.value = order;
    itemForm.reset();
    itemForm.product_id = '';
    itemForm.quantity = 1;
    itemForm.price = null;
    showAddItemModal.value = true;
};

const addItemToOrder = () => {
    const orderId = currentOrder.value.id;  // Store ID before resetting
    itemForm.post(route('orders.items.store', orderId), {
        preserveScroll: true,
        onSuccess: (response) => {
            // Get the updated order from the response
            const updatedOrder = response?.props?.flash?.order;
            
            if (updatedOrder) {
                // Find and update the order in the current batch
                const batchIndex = localBatches.value.findIndex(b => 
                    b.orders.some(o => o.id === updatedOrder.id)
                );
                
                if (batchIndex !== -1) {
                    const orderIndex = localBatches.value[batchIndex].orders
                        .findIndex(o => o.id === updatedOrder.id);
                    
                    if (orderIndex !== -1) {
                        // Update the order in the batch
                        localBatches.value[batchIndex].orders[orderIndex] = updatedOrder;
                        // Force Vue to detect the change
                        localBatches.value = [...localBatches.value];
                        
                        // If this order is in the current batch being viewed, update it there too
                        if (currentBatch.value?.id === localBatches.value[batchIndex].id) {
                            currentBatch.value = { ...localBatches.value[batchIndex] };
                        }
                    }
                }
            }
            
            // Reset the form and close the modal
            showAddItemModal.value = false;
            itemForm.reset();
            currentOrder.value = null;
        },
        onError: (errors) => {
            console.error('Error adding item:', errors);
        },
    });
};

const removeOrderItem = (order, item) => {
    if (!confirm('Êtes-vous sûr de vouloir supprimer cet article ?')) {
        return;
    }

    router.delete(route('orders.items.destroy', { order: order.id, item: item.id }), {
        preserveScroll: true,
        onSuccess: () => {
            // Update the local batches with the updated data from the response
            const updatedBatch = localBatches.value.find(b => b.orders.some(o => o.id === order.id));
            if (updatedBatch) {
                const updatedOrder = page.props.flash.order;
                const orderIndex = updatedBatch.orders.findIndex(o => o.id === updatedOrder.id);
                if (orderIndex !== -1) {
                    updatedBatch.orders[orderIndex] = updatedOrder;
                    // Force reactivity update
                    localBatches.value = [...localBatches.value];
                }
            }
        },
    });
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
                        <v-icon>mdi-file-pdf</v-icon>
                         PDF
                    </PrimaryButton>
                </div>

                <div v-if="currentBatch?.orders?.length" class="mt-4 space-y-4">
                    <!-- Status Statistics -->
                    <div class="grid grid-cols-3 gap-4">
                        <div class="bg-green-50 p-3 rounded-lg">
                            <div class="text-sm text-green-700 font-medium">Livrées</div>
                            <div class="text-2xl text-green-800">{{ getStatusTotals(currentBatch.orders).DELIVERED.count }}</div>
                            <label class="inline-flex items-center mt-2">
                                <input type="checkbox" v-model="statusFilters.DELIVERED" class="form-checkbox h-4 w-4 text-green-600">
                                <span class="ml-2 text-sm text-green-700">Afficher</span>
                            </label>
                        </div>
                        <div class="bg-yellow-50 p-3 rounded-lg">
                            <div class="text-sm text-yellow-700 font-medium">En attente</div>
                            <div class="text-2xl text-yellow-800">{{ getStatusTotals(currentBatch.orders).WAITING.count }}</div>
                            <label class="inline-flex items-center mt-2">
                                <input type="checkbox" v-model="statusFilters.WAITING" class="form-checkbox h-4 w-4 text-yellow-600">
                                <span class="ml-2 text-sm text-yellow-700">Afficher</span>
                            </label>
                        </div>
                        <div class="bg-red-50 p-3 rounded-lg">
                            <div class="text-sm text-red-700 font-medium">Annulées</div>
                            <div class="text-2xl text-red-800">{{ getStatusTotals(currentBatch.orders).CANCELLED.count }}</div>
                            <label class="inline-flex items-center mt-2">
                                <input type="checkbox" v-model="statusFilters.CANCELLED" class="form-checkbox h-4 w-4 text-red-600">
                                <span class="ml-2 text-sm text-red-700">Afficher</span>
                            </label>
                        </div>
                    </div>

                    <!-- Product Statistics -->
                    <div class="p-4 bg-gray-100 rounded-lg">
                        <h3 class="font-medium text-gray-700 mb-4">Total par produit:</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 bg-white rounded-lg">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Produit
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-green-600 uppercase tracking-wider">
                                            Livrées
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-yellow-600 uppercase tracking-wider">
                                            En attente
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-red-600 uppercase tracking-wider">
                                            Annulées
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="total in getProductTotals(filteredBatchOrders)" :key="total.name" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ total.name }}
                                            <div class="text-xs text-gray-500">Total: {{ total.total_quantity }} unité(s)</div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                            <span class="text-green-600 font-medium">{{ total.by_status.DELIVERED }}</span>
                                            <span class="text-xs text-gray-500"> unité(s)</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                            <span class="text-yellow-600 font-medium">{{ total.by_status.WAITING }}</span>
                                            <span class="text-xs text-gray-500"> unité(s)</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                            <span class="text-red-600 font-medium">{{ total.by_status.CANCELLED }}</span>
                                            <span class="text-xs text-gray-500"> unité(s)</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="space-y-4">
                        <div v-for="order in filteredBatchOrders" :key="order.id" class="flex flex-col p-3 bg-gray-50 rounded-lg mb-4">
                            <div class="flex justify-between items-center mb-3">
                                <div>
                                    <h3 class="font-medium text-gray-900">{{ order.customer?.name || 'Client inconnu' }}</h3>
                                    <h6 class="font-small text-gray-500">{{ order.customer?.address || 'Adresse non définie' }}</h6>
                                    <!--  add a button icon here to show order comment -->
                                   <!-- use vuetify to display order comment in a tooltip -->
                                    <v-tooltip v-if="order.comment">
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-icon v-bind="attrs" v-on="on" color="blue" small>mdi-comment</v-icon>
                                        </template>
                                        <span>{{ order.comment }}</span>
                                    </v-tooltip>
                                    <p class="text-xs" :class="{
                                        'text-green-600': order.status === 'DELIVERED',
                                        'text-yellow-600': order.status === 'WAITING',
                                        'text-red-600': order.status === 'CANCELLED'
                                    }">
                                        {{ order.status === 'DELIVERED' ? 'Livrée' : 
                                           order.status === 'WAITING' ? 'En attente' : 'Annulée' }}
                                        <span v-if="order.status === 'DELIVERED'"> - {{  order.is_paid ? 'Payée' : 'Non payée' }}</span>
                                    </p>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button 
                                        @click="openAddItemModal(order)"
                                        class="text-blue-600 hover:text-blue-800 p-2 rounded-full hover:bg-blue-50"
                                        title="Ajouter un article"
                                    >
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                    </button>
                                    <button 
                                        @click="removeOrder(currentBatch, order)" 
                                        class="text-red-600 hover:text-red-900 p-2 rounded-full hover:bg-red-50"
                                        title="Supprimer la commande"
                                    >
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Items Table -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 bg-white rounded-lg">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Produit
                                            </th>
                                            <th scope="col" class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Quantité
                                            </th>
                                            <th scope="col" class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Prix unitaire
                                            </th>
                                            <th scope="col" class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Total
                                            </th>
                                            <th scope="col" class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="item in order.items" :key="item.id" class="hover:bg-gray-50">
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900">
                                                {{ item.product?.name || 'Produit inconnu' }}
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900 text-right">
                                                {{ item.quantity }} unité(s)
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900 text-right">
                                                {{ item.price }} FCFA
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                                                {{ item.total_price }} FCFA
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-right text-sm font-medium">
                                                <button 
                                                    @click="removeOrderItem(order, item)"
                                                    class="text-red-600 hover:text-red-900 p-1 rounded-full hover:bg-red-50"
                                                    title="Supprimer l'article"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                        <!-- Total Row -->
                                        <tr class="bg-gray-50 font-medium">
                                            <td colspan="3" class="px-3 py-2 text-sm text-gray-900 text-right">
                                                Total de la commande:
                                            </td>
                                            <td class="px-3 py-2 text-sm text-gray-900 text-right">
                                                {{ order.total_price }} FCFA
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
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
                        <div v-for="(item, index) in orderForm.items" :key="index" class="border-b pb-4 mb-4">
                            <div class="flex justify-between items-center mb-2">
                                <h4 class="text-sm font-medium text-gray-700">Produit {{ index + 1 }}</h4>
                                <button 
                                    type="button" 
                                    @click="removeItem(index)" 
                                    v-if="orderForm.items.length > 1"
                                    class="text-red-600 hover:text-red-900"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel :for="'product_' + index" value="Produit" />
                                    <select
                                        :id="'product_' + index"
                                        v-model="item.product_id"
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                    >
                                        <option value="">Sélectionner un produit</option>
                                        <option v-for="product in products" :key="product.id" :value="product.id">
                                            {{ product.name }}
                                        </option>
                                    </select>
                                    <InputError :message="orderForm.errors['items.' + index + '.product_id']" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel :for="'quantity_' + index" value="Quantité" />
                                    <TextInput
                                        :id="'quantity_' + index"
                                        type="number"
                                        v-model="item.quantity"
                                        class="mt-1 block w-full"
                                        min="1"
                                    />
                                    <InputError :message="orderForm.errors['items.' + index + '.quantity']" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <SecondaryButton type="button" @click="addItem">
                                Ajouter un produit
                            </SecondaryButton>
                        </div>
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

        <!-- Add Item Modal -->
        <Modal :show="showAddItemModal" @close="showAddItemModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Ajouter un article à la commande
                </h2>

                <form @submit.prevent="addItemToOrder" class="mt-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="product" value="Produit" />
                            <select
                                id="product"
                                v-model="itemForm.product_id"
                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                required
                            >
                                <option value="">Sélectionner un produit</option>
                                <option v-for="product in products" :key="product.id" :value="product.id">
                                    {{ product.name }}
                                </option>
                            </select>
                            <InputError :message="itemForm.errors.product_id" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="quantity" value="Quantité" />
                            <TextInput
                                id="quantity"
                                type="number"
                                v-model="itemForm.quantity"
                                class="mt-1 block w-full"
                                required
                                min="1"
                            />
                            <InputError :message="itemForm.errors.quantity" class="mt-2" />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="showAddItemModal = false" class="mr-3">
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton :disabled="itemForm.processing || !itemForm.product_id || itemForm.quantity < 1">
                            Ajouter l'article
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template> 