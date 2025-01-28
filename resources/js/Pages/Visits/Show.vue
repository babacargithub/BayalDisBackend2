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

                <!-- Visits List -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Liste des visites</h3>
                        <div class="space-y-4">
                            <div
                                v-for="visit in batch.visits"
                                :key="visit.id"
                                class="border rounded-lg overflow-hidden"
                            >
                                <div class="p-4">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h4 class="text-lg font-medium text-gray-900">
                                                {{ visit.customer.name }}
                                            </h4>
                                            <p class="text-sm text-gray-500">
                                                {{ visit.customer.phone_number }}
                                            </p>
                                            <p class="text-sm text-gray-500">
                                                {{ visit.customer.address }}
                                            </p>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span
                                                class="px-2 py-1 text-xs font-medium rounded-full"
                                                :class="{
                                                    'bg-yellow-100 text-yellow-800': visit.status === 'planned',
                                                    'bg-green-100 text-green-800': visit.status === 'completed',
                                                    'bg-red-100 text-red-800': visit.status === 'cancelled'
                                                }"
                                            >
                                                {{ visitStatusText(visit.status) }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <h5 class="text-sm font-medium text-gray-500">Heure prévue</h5>
                                            <p class="mt-1">
                                                {{ formatTime(visit.visit_planned_at) }}
                                            </p>
                                        </div>
                                        <div v-if="visit.visited_at">
                                            <h5 class="text-sm font-medium text-gray-500">Heure de visite</h5>
                                            <p class="mt-1">
                                                {{ formatDateTime(visit.visited_at) }}
                                            </p>
                                        </div>
                                    </div>

                                    <div v-if="visit.notes" class="mt-4">
                                        <h5 class="text-sm font-medium text-gray-500">Notes</h5>
                                        <p class="mt-1 text-gray-700">{{ visit.notes }}</p>
                                    </div>

                                    <div v-if="visit.status === 'completed'" class="mt-4">
                                        <h5 class="text-sm font-medium text-gray-500">Résultat</h5>
                                        <p class="mt-1">
                                            {{ visit.resulted_in_sale ? 'Vente réalisée' : 'Pas de vente' }}
                                        </p>
                                    </div>

                                    <div v-if="visit.gps_coordinates" class="mt-4">
                                        <h5 class="text-sm font-medium text-gray-500">Coordonnées GPS</h5>
                                        <p class="mt-1 text-gray-700">{{ visit.gps_coordinates }}</p>
                                    </div>

                                    <div class="mt-4 flex gap-2">
                                        <button
                                            v-if="visit.status === 'planned'"
                                            @click="completeVisit(visit)"
                                            class="inline-flex items-center px-3 py-1 bg-green-100 border border-transparent rounded-md text-sm font-medium text-green-700 hover:bg-green-200"
                                        >
                                            <v-icon
                                                icon="mdi-check"
                                                size="small"
                                                class="mr-1"
                                            />
                                            Terminer
                                        </button>
                                        <button
                                            @click="confirmDeleteVisit(visit)"
                                            class="inline-flex items-center px-3 py-1 bg-red-100 border border-transparent rounded-md text-sm font-medium text-red-700 hover:bg-red-200"
                                        >
                                            <v-icon
                                                icon="mdi-delete"
                                                size="small"
                                                class="mr-1"
                                            />
                                            Supprimer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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

const props = defineProps({
    batch: {
        type: Object,
        required: true
    }
});

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
</script> 