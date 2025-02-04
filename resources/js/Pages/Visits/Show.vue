<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ batch.name }}
                </h2>
                <div class="flex gap-2">
                    <Link
                        :href="route('visits.edit', batch.id)"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50"
                    >
                        <v-icon icon="mdi-pencil" size="small" class="mr-2" />
                        Modifier
                    </Link>
                    <Link
                        :href="route('visits.index')"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                    >
                        Retour à la liste
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Batch Info -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Date des visites</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ formatDate(batch.visit_date) }}
                                </p>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Nombre de visites</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ batch.visits.length }} visites planifiées
                                </p>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Progression</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ completedVisitsCount }} / {{ batch.visits.length }} visites complétées
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
              

                <!-- Progress Bar -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-4">
                        <div class="flex items-center gap-4">
                            <div class="flex-grow">
                                <v-progress-linear
                                    :model-value="progressPercentage"
                                    :color="progressColor"
                                    height="20"
                                    rounded
                                >
                                    <template v-slot:default="{ value }">
                                        <div class="text-white font-medium">{{ Math.ceil(value) }}%</div>
                                    </template>
                                </v-progress-linear>
                            </div>
                            <div class="flex gap-4 items-center text-sm">
                                <div class="flex items-center gap-1">
                                    <v-icon color="success" size="small">mdi-check-circle</v-icon>
                                    <span>{{ completedVisitsCount }} terminées</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <v-icon color="warning" size="small">mdi-clock</v-icon>
                                    <span>{{ plannedVisitsCount }} planifiées</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <v-icon color="error" size="small">mdi-close-circle</v-icon>
                                    <span>{{ cancelledVisitsCount }} annulées</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters and Search -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-4">
                        <div class="flex flex-wrap gap-6 items-center">
                            <v-text-field
                                v-model="searchQuery"
                                label="Rechercher un client"
                                prepend-icon="mdi-magnify"
                                hide-details
                                density="compact"
                                variant="outlined"
                                class="max-w-sm"
                            />
                            <div class="flex gap-4 items-center">
                                <v-checkbox
                                    v-model="statusFilters.planned"
                                    label="Planifiées"
                                    color="warning"
                                    hide-details
                                    density="compact"
                                />
                                <v-checkbox
                                    v-model="statusFilters.completed"
                                    label="Terminées"
                                    color="success"
                                    hide-details
                                    density="compact"
                                />
                                <v-checkbox
                                    v-model="statusFilters.cancelled"
                                    label="Annulées"
                                    color="error"
                                    hide-details
                                    density="compact"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visits Table -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <v-data-table
                        :headers="headers"
                        :items="filteredVisits"
                        :search="searchQuery"
                        density="compact"
                        :items-per-page="filteredVisits.length"
                        :items-per-page-options="[filteredVisits.length]"
                        class="elevation-1"
                    >
                        <template v-slot:item.customer.name="{ item }">
                            <div>
                                <div class="font-medium">{{ item.customer.name }}</div>
                                <div class="text-sm text-gray-500">{{ item.customer.address }}</div>
                                <div class="text-sm text-gray-500">{{ item.customer.phone_number }}</div>
                            </div>
                        </template>

                        <template v-slot:item.visit_planned_at="{ item }">
                            {{ formatTime(item.visit_planned_at) }}
                        </template>

                        <template v-slot:item.visited_at="{ item }">
                            {{ formatDateTime(item.visited_at) }}
                        </template>

                        <template v-slot:item.status="{ item }">
                            <v-chip
                                :color="item.status === 'completed' ? 'success' : 
                                       item.status === 'cancelled' ? 'error' : 'warning'"
                                size="small"
                            >
                                {{ visitStatusText(item.status) }}
                            </v-chip>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <div class="flex gap-2 justify-center">
                                <v-btn
                                    v-if="item.status === 'planned'"
                                    icon="mdi-check"
                                    variant="text"
                                    color="success"
                                    size="small"
                                    @click="completeVisit(item)"
                                />
                                <v-btn
                                    icon="mdi-delete"
                                    variant="text"
                                    color="error"
                                    size="small"
                                    @click="confirmDeleteVisit(item)"
                                />
                            </div>
                        </template>
                    </v-data-table>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Confirmer la suppression
                </v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette visite ? Cette action est irréversible.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="deleteDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="deleteVisit"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Complete Visit Dialog -->
        <CompleteVisitDialog
            v-if="visitToComplete"
            :show="!!visitToComplete"
            :visit="visitToComplete"
            @close="visitToComplete = null"
        />
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CompleteVisitDialog from './Partials/CompleteVisitDialog.vue';
import { onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    batch: {
        type: Object,
        required: true
    }
});

const refreshInterval = ref(null);

onMounted(() => {
    refreshInterval.value = setInterval(() => {
        router.reload({ preserveScroll: true });
    }, 600000);
});

onBeforeUnmount(() => {
    if (refreshInterval.value) {
        clearInterval(refreshInterval.value);
    }
});

const searchQuery = ref('');
const statusFilters = ref({
    planned: true,
    completed: true,
    cancelled: true
});

const filteredVisits = computed(() => {
    let filtered = props.batch.visits;

    // Apply status filters
    filtered = filtered.filter(visit => statusFilters.value[visit.status]);

    // Apply search filter
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter(visit => 
            visit.customer.name.toLowerCase().includes(query) ||
            visit.customer.address.toLowerCase().includes(query) ||
            visit.customer.phone_number.toLowerCase().includes(query)
        );
    }

    return filtered;
});

const headers = [
    { title: 'Client', key: 'customer.name', align: 'start', sortable: true },
    { title: 'Heure de visite', key: 'visited_at', align: 'center' },
    { title: 'Statut', key: 'status', align: 'center' },
    { title: 'Actions', key: 'actions', align: 'center', sortable: false },
];

const completedVisitsCount = computed(() => {
    return props.batch.visits.filter(visit => visit.status === 'completed').length;
});

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const formatTime = (time) => {
    if (!time) return '-';
    return new Date('2000-01-01 ' + time).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatDateTime = (datetime) => {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const visitStatusText = (status) => {
    switch (status) {
        case 'planned':
            return 'Planifiée';
        case 'completed':
            return 'Terminée';
        case 'cancelled':
            return 'Annulée';
        default:
            return status;
    }
};

const deleteDialog = ref(false);
const visitToDelete = ref(null);
const visitToComplete = ref(null);

const confirmDeleteVisit = (visit) => {
    visitToDelete.value = visit;
    deleteDialog.value = true;
};

const deleteVisit = () => {
    router.delete(route('visits.customer-visits.destroy', visitToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            visitToDelete.value = null;
        }
    });
};

const completeVisit = (visit) => {
    visitToComplete.value = visit;
};

const progressPercentage = computed(() => {
    if (props.batch.visits.length === 0) return 0;
    return (completedVisitsCount.value / props.batch.visits.length) * 100;
});

const progressColor = computed(() => {
    const percentage = progressPercentage.value;
    if (percentage >= 75) return 'success';
    if (percentage >= 50) return 'info';
    if (percentage >= 25) return 'warning';
    return 'error';
});

const plannedVisitsCount = computed(() => {
    return props.batch.visits.filter(visit => visit.status === 'planned').length;
});

const cancelledVisitsCount = computed(() => {
    return props.batch.visits.filter(visit => visit.status === 'cancelled').length;
});
</script> 