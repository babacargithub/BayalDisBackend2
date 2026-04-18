<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Arrêt — {{ stop.customer.name }}
                </h2>
                <Link
                    :href="route('beats.show', stop.beat.id)"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                >
                    Retour au beat
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Customer Info -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Client</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Nom</p>
                                <p class="mt-1 font-medium">{{ stop.customer.name }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Téléphone</p>
                                <p class="mt-1 font-medium">{{ stop.customer.phone_number || '—' }}</p>
                            </div>
                            <div class="md:col-span-2">
                                <p class="text-sm text-gray-500">Adresse</p>
                                <p class="mt-1 font-medium">{{ stop.customer.address || '—' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Beat Info -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Beat</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Nom</p>
                                <p class="mt-1 font-medium">{{ stop.beat.name }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Date</p>
                                <p class="mt-1 font-medium">{{ formatDate(stop.beat.visit_date) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stop Details -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Détails de l'arrêt</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Statut</p>
                                <v-chip
                                    :color="stop.status === 'completed' ? 'success' : stop.status === 'cancelled' ? 'error' : 'warning'"
                                    size="small"
                                    class="mt-1"
                                >
                                    {{ stopStatusText(stop.status) }}
                                </v-chip>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Résulté en vente</p>
                                <p class="mt-1 font-medium">{{ stop.resulted_in_sale ? 'Oui' : 'Non' }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Visite prévue à</p>
                                <p class="mt-1 font-medium">{{ formatTime(stop.visit_planned_at) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Visité à</p>
                                <p class="mt-1 font-medium">{{ formatDateTime(stop.visited_at) }}</p>
                            </div>
                            <div v-if="stop.notes" class="md:col-span-2">
                                <p class="text-sm text-gray-500">Notes</p>
                                <p class="mt-1 font-medium">{{ stop.notes }}</p>
                            </div>
                            <div v-if="stop.gps_coordinates" class="md:col-span-2">
                                <p class="text-sm text-gray-500">Coordonnées GPS</p>
                                <p class="mt-1 font-medium font-mono text-sm">{{ stop.gps_coordinates }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    stop: {
        type: Object,
        required: true
    }
});

const stopStatusText = (status) => {
    switch (status) {
        case 'planned': return 'Planifié';
        case 'completed': return 'Terminé';
        case 'cancelled': return 'Annulé';
        default: return status;
    }
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const formatTime = (time) => {
    if (!time) return '—';
    return new Date('2000-01-01 ' + time).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatDateTime = (datetime) => {
    if (!datetime) return '—';
    return new Date(datetime).toLocaleString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>
