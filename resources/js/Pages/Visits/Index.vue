<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Gestion des visites
                </h2>
                <Link
                    :href="route('visits.create')"
                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    Planifier des visites
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6">
                        <!-- Empty state -->
                        <div v-if="!batches.length" class="text-center py-12">
                            <v-icon
                                icon="mdi-map-marker-check"
                                size="48"
                                class="text-gray-400 mb-4"
                            />
                            <h3 class="text-lg font-medium text-gray-900 mb-2">
                                Aucune visite planifiée
                            </h3>
                            <p class="text-gray-500 mb-6">
                                Commencez par planifier des visites pour vos clients.
                            </p>
                            <Link
                                :href="route('visits.create')"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                            >
                                Planifier des visites
                            </Link>
                        </div>

                        <!-- Batches list -->
                        <div v-else class="space-y-6">
                            <div
                                v-for="batch in batches"
                                :key="batch.id"
                                class="bg-white border rounded-lg overflow-hidden hover:shadow-md transition-shadow duration-200"
                            >
                                <div class="p-6">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <Link
                                                :href="route('visits.show', batch.id)"
                                                class="text-lg font-semibold text-blue-600 hover:text-blue-800"
                                            >
                                                {{ batch.name }}
                                            </Link>
                                            <p class="text-sm text-gray-500 mt-1">
                                                Date: {{ formatDate(batch.visit_date) }}
                                            </p>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button
                                                @click="openCustomerDialog(batch)"
                                                class="inline-flex items-center px-3 py-1 bg-green-100 border border-transparent rounded-md text-sm font-medium text-green-700 hover:bg-green-200"
                                            >
                                                <v-icon
                                                    icon="mdi-account-plus"
                                                    size="small"
                                                    class="mr-1"
                                                />
                                                Ajouter clients
                                            </button>
                                            <Link
                                                :href="route('visits.edit', batch.id)"
                                                class="inline-flex items-center px-3 py-1 bg-gray-100 border border-transparent rounded-md text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                <v-icon
                                                    icon="mdi-pencil"
                                                    size="small"
                                                    class="mr-1"
                                                />
                                                Modifier
                                            </Link>
                                        </div>
                                    </div>
                                    <div class="mt-4">
                                        <div class="flex items-center space-x-4">
                                            <span class="text-sm text-gray-600">
                                                {{ batch.completed_visits_count }} / {{ batch.visits_count }} visites complétées
                                            </span>
                                            <div class="flex-1 bg-gray-200 rounded-full h-2">
                                                <div
                                                    class="bg-blue-600 h-2 rounded-full"
                                                    :style="{ width: ((batch.completed_visits_count / batch.visits_count) * 100) + '%' }"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Selection Dialog -->
        <v-dialog v-model="customerDialog" max-width="700px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Ajouter des clients à la visite
                </v-card-title>
                <v-card-text class="pb-0">
                    <div class="flex gap-4 mb-4">
                        <v-text-field
                            v-model="customerSearch"
                            label="Rechercher par nom, téléphone ou adresse"
                            prepend-inner-icon="mdi-magnify"
                            variant="outlined"
                            density="comfortable"
                            hide-details
                            class="flex-1"
                        />
                        <v-select
                            v-model="lastVisitFilter"
                            :items="lastVisitOptions"
                            item-title="label"
                            item-value="value"
                            label="Filtrer par dernière visite"
                            variant="outlined"
                            density="comfortable"
                            hide-details
                            class="flex-1"
                        />
                    </div>

                    <div class="max-h-[400px] overflow-y-auto">
                        <v-table>
                            <thead>
                                <tr>
                                    <th style="width: 50px">
                                        <v-checkbox
                                            v-model="selectedCustomers"
                                            :value="filteredCustomers.map(c => c.id)"
                                            :indeterminate="
                                                selectedCustomers.length > 0 &&
                                                selectedCustomers.length < filteredCustomers.length
                                            "
                                            @click="toggleAllCustomers"
                                        />
                                    </th>
                                    <th>Nom</th>
                                    <th>Téléphone</th>
                                    <th>Adresse</th>
                                    <th>Dernière visite</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="customer in filteredCustomers" :key="customer.id">
                                    <td>
                                        <v-checkbox
                                            v-model="selectedCustomers"
                                            :value="customer.id"
                                            hide-details
                                        />
                                    </td>
                                    <td>{{ customer.name }}</td>
                                    <td>{{ customer.phone_number }}</td>
                                    <td>{{ customer.address }}</td>
                                    <td>{{ formatLastVisit(customer.last_visit) }}</td>
                                </tr>
                                <tr v-if="filteredCustomers.length === 0">
                                    <td colspan="5" class="text-center py-4 text-gray-500">
                                        Aucun client disponible
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>
                </v-card-text>
                <v-card-actions class="px-6 py-4 bg-gray-50">
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="customerDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        :disabled="!selectedCustomers.length"
                        @click="addCustomersToVisits"
                    >
                        Ajouter {{ selectedCustomers.length }} client(s)
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    batches: {
        type: Array,
        required: true
    },
    customers: {
        type: Array,
        required: true
    }
});

const customerDialog = ref(false);
const selectedBatch = ref(null);
const customerSearch = ref('');
const selectedCustomers = ref([]);
const lastVisitFilter = ref(null); // null means no filter

const lastVisitOptions = [
    { label: 'Tous les clients', value: null },
    { label: 'Non visités depuis 30 jours', value: 30 },
    { label: 'Non visités depuis 60 jours', value: 60 },
    { label: 'Non visités depuis 90 jours', value: 90 },
    { label: 'Jamais visités', value: 'never' }
];

const formatLastVisit = (date) => {
    if (!date) return 'Jamais';
    const visitDate = new Date(date);
    const now = new Date();
    const diffTime = Math.abs(now - visitDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) return 'Aujourd\'hui';
    if (diffDays === 1) return 'Hier';
    return `Il y a ${diffDays} jours`;
};

const openCustomerDialog = (batch) => {
    console.log('Opening dialog for batch:', batch);
    selectedBatch.value = batch;
    selectedCustomers.value = [];
    customerDialog.value = true;
};

const filteredCustomers = computed(() => {
    if (!selectedBatch.value) return [];

    // Get the IDs of customers already in the batch
    const existingCustomerIds = selectedBatch.value.visits?.map(visit => visit.customer_id) || [];
    
    // Filter out existing customers
    let availableCustomers = props.customers.filter(customer => 
        !existingCustomerIds.includes(customer.id)
    );

    // Apply last visit filter
    if (lastVisitFilter.value) {
        const now = new Date();
        availableCustomers = availableCustomers.filter(customer => {
            if (lastVisitFilter.value === 'never') {
                return customer.last_visit === customer.created_at;
            }
            
            const lastVisit = new Date(customer.last_visit);
            const diffTime = Math.abs(now - lastVisit);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            return diffDays >= lastVisitFilter.value;
        });
    }

    // Apply search filter
    if (customerSearch.value) {
        const search = customerSearch.value.toLowerCase();
        availableCustomers = availableCustomers.filter(customer => 
            customer.name.toLowerCase().includes(search) ||
            (customer.phone_number && customer.phone_number.toLowerCase().includes(search)) ||
            (customer.address && customer.address.toLowerCase().includes(search))
        );
    }

    return availableCustomers;
});

const addCustomersToVisits = () => {
    router.post(route('visits.add-customers', selectedBatch.value.id), {
        customer_ids: selectedCustomers.value
    }, {
        onSuccess: () => {
            customerDialog.value = false;
            selectedBatch.value = null;
            selectedCustomers.value = [];
        }
    });
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const toggleAllCustomers = () => {
    if (selectedCustomers.value.length === filteredCustomers.value.length) {
        selectedCustomers.value = [];
    } else {
        selectedCustomers.value = filteredCustomers.value.map(c => c.id);
    }
};
</script> 