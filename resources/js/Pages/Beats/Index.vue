<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Gestion des beats
                </h2>
                <Link
                    :href="route('beats.create')"
                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    Planifier un beat
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6">
                        <!-- Empty state -->
                        <div v-if="!beats.length" class="text-center py-12">
                            <v-icon
                                icon="mdi-map-marker-check"
                                size="48"
                                class="text-gray-400 mb-4"
                            />
                            <h3 class="text-lg font-medium text-gray-900 mb-2">
                                Aucun beat planifié
                            </h3>
                            <p class="text-gray-500 mb-6">
                                Commencez par planifier des beats pour vos clients.
                            </p>
                            <Link
                                :href="route('beats.create')"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                            >
                                Planifier un beat
                            </Link>
                        </div>

                        <!-- Beats list -->
                        <div v-else class="space-y-6">
                            <div
                                v-for="beat in beats"
                                :key="beat.id"
                                class="bg-white border rounded-lg overflow-hidden hover:shadow-md transition-shadow duration-200"
                            >
                                <div class="p-6">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <Link
                                                :href="route('beats.show', beat.id)"
                                                class="text-lg font-semibold text-blue-600 hover:text-blue-800"
                                            >
                                                {{ beat.name }}
                                            </Link>
                                            <p class="text-sm text-gray-500 mt-1">
                                                Jour: {{ beat.day_of_week_label }}
                                            </p>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button
                                                @click="openHistoryDialog(beat)"
                                                class="inline-flex items-center px-3 py-1 bg-purple-100 border border-transparent rounded-md text-sm font-medium text-purple-700 hover:bg-purple-200"
                                            >
                                                <v-icon
                                                    icon="mdi-chart-line"
                                                    size="small"
                                                    class="mr-1"
                                                />
                                                Historique
                                            </button>
                                            <button
                                                @click="openCustomerDialog(beat)"
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
                                                :href="route('beats.edit', beat.id)"
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
                                    <div class="mt-4 flex flex-wrap gap-4 items-center">
                                        <span class="text-sm text-gray-600">
                                            {{ beat.template_stops_count }} client(s) planifié(s) chaque {{ beat.day_of_week_label?.toLowerCase() }}
                                        </span>

                                        <div
                                            v-if="beat.forecast_data_points_count > 0"
                                            class="flex gap-3"
                                        >
                                            <div class="flex items-center gap-1 px-3 py-1 bg-blue-50 border border-blue-100 rounded-full">
                                                <v-icon icon="mdi-chart-timeline-variant" size="14" class="text-blue-500" />
                                                <span class="text-xs font-medium text-blue-700">
                                                    Prév. ventes : {{ formatForecastAmount(beat.forecasted_total_sales) }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-1 px-3 py-1 bg-green-50 border border-green-100 rounded-full">
                                                <v-icon icon="mdi-trending-up" size="14" class="text-green-500" />
                                                <span class="text-xs font-medium text-green-700">
                                                    Prév. profit : {{ formatForecastAmount(beat.forecasted_total_profit) }}
                                                </span>
                                            </div>
                                            <span class="text-xs text-gray-400 self-center">
                                                (moy. sur {{ beat.forecast_data_points_count }} j.)
                                            </span>
                                        </div>

                                        <div v-else class="flex items-center gap-1 px-3 py-1 bg-gray-50 border border-gray-200 rounded-full">
                                            <v-icon icon="mdi-chart-timeline-variant" size="14" class="text-gray-400" />
                                            <span class="text-xs text-gray-400">Pas encore de données prévisionnelles</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Beat History Dialog -->
        <BeatHistoryDialog
            v-model="historyDialogOpen"
            :beat-id="selectedBeatIdForHistory"
        />

        <!-- Customer Selection Dialog -->
        <v-dialog v-model="customerDialog" max-width="700px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Ajouter des clients au beat
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
                        @click="addCustomersToBeats"
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
import BeatHistoryDialog from './Partials/BeatHistoryDialog.vue';

const props = defineProps({
    beats: {
        type: Array,
        required: true
    },
    customers: {
        type: Array,
        required: true
    },
    days_of_week: {
        type: Array,
        required: true
    }
});

const historyDialogOpen = ref(false);
const selectedBeatIdForHistory = ref(null);

const openHistoryDialog = (beat) => {
    selectedBeatIdForHistory.value = beat.id;
    historyDialogOpen.value = true;
};

const customerDialog = ref(false);
const selectedBeat = ref(null);
const customerSearch = ref('');
const selectedCustomers = ref([]);
const lastVisitFilter = ref(null);

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

const openCustomerDialog = (beat) => {
    selectedBeat.value = beat;
    selectedCustomers.value = [];
    customerDialog.value = true;
};

const filteredCustomers = computed(() => {
    if (!selectedBeat.value) return [];

    const existingCustomerIds = selectedBeat.value.stops?.map(stop => stop.customer_id) || [];

    let availableCustomers = props.customers.filter(customer =>
        !existingCustomerIds.includes(customer.id)
    );

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

const addCustomersToBeats = () => {
    router.post(route('beats.add-customers', selectedBeat.value.id), {
        customer_ids: selectedCustomers.value
    }, {
        onSuccess: () => {
            customerDialog.value = false;
            selectedBeat.value = null;
            selectedCustomers.value = [];
        }
    });
};

const formatForecastAmount = (amount) => {
    if (!amount || amount === 0) {
        return '—';
    }

    if (amount >= 1_000_000) {
        return new Intl.NumberFormat('fr-FR').format(Math.round(amount / 1_000)) + ' k XOF';
    }

    return new Intl.NumberFormat('fr-FR').format(amount) + ' XOF';
};

const toggleAllCustomers = () => {
    if (selectedCustomers.value.length === filteredCustomers.value.length) {
        selectedCustomers.value = [];
    } else {
        selectedCustomers.value = filteredCustomers.value.map(c => c.id);
    }
};
</script>
