<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ batch.name }}
                </h2>
                <div class="flex gap-2">
                    <a
                        :href="route('beats.pdf', batch.id)"
                        target="_blank"
                        class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700"
                    >
                        <v-icon icon="mdi-file-pdf-box" size="small" class="mr-2" />
                        Exporter PDF
                    </a>
                    <Link
                        :href="route('beats.edit', batch.id)"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50"
                    >
                        <v-icon icon="mdi-pencil" size="small" class="mr-2" />
                        Modifier
                    </Link>
                    <Link
                        :href="route('beats.index')"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                    >
                        Retour à la liste
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Beat Info -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Jour de la semaine</h3>
                                <p class="mt-1 text-lg font-semibold text-gray-900">
                                    {{ batch.day_of_week_label }}
                                </p>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Commercial</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ batch.commercial?.name || '—' }}
                                </p>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Clients récurrents</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ batch.visits.length }} client(s)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-4">
                        <v-text-field
                            v-model="searchQuery"
                            label="Rechercher un client"
                            prepend-icon="mdi-magnify"
                            hide-details
                            density="compact"
                            variant="outlined"
                            class="max-w-sm"
                        />
                    </div>
                </div>

                <!-- Template Stops Table -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 pt-4 pb-2 border-b">
                        <h3 class="text-base font-medium text-gray-900">
                            Clients planifiés chaque {{ batch.day_of_week_label?.toLowerCase() }}
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Ces clients seront automatiquement ajoutés à la tournée à chaque {{ batch.day_of_week_label?.toLowerCase() }}.
                        </p>
                    </div>
                    <v-data-table
                        :headers="headers"
                        :items="filteredStops"
                        density="compact"
                        :items-per-page="filteredStops.length"
                        :items-per-page-options="[filteredStops.length]"
                        class="elevation-0"
                    >
                        <template v-slot:item.customer.name="{ item }">
                            <div>
                                <div class="font-medium">{{ item.customer.name }}</div>
                                <div class="text-sm text-gray-500">{{ item.customer.address }}</div>
                                <div class="text-sm text-gray-500">{{ item.customer.phone_number }}</div>
                            </div>
                        </template>

                        <template v-slot:item.notes="{ item }">
                            <span class="text-gray-500 text-sm">{{ item.notes || '—' }}</span>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <v-btn
                                icon="mdi-delete"
                                variant="text"
                                color="error"
                                size="small"
                                @click="confirmRemoveStop(item)"
                            />
                        </template>
                    </v-data-table>
                </div>
            </div>
        </div>

        <!-- Remove Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Retirer le client
                </v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir retirer ce client du beat récurrent ? Il ne sera plus visité automatiquement les {{ batch.day_of_week_label?.toLowerCase() }}s.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" variant="text" @click="deleteDialog = false">
                        Annuler
                    </v-btn>
                    <v-btn color="primary" @click="removeStop">
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    batch: {
        type: Object,
        required: true
    }
});

const searchQuery = ref('');

const filteredStops = computed(() => {
    if (!searchQuery.value) return props.batch.visits;
    const query = searchQuery.value.toLowerCase();
    return props.batch.visits.filter(stop =>
        stop.customer.name.toLowerCase().includes(query) ||
        (stop.customer.address && stop.customer.address.toLowerCase().includes(query)) ||
        (stop.customer.phone_number && stop.customer.phone_number.toLowerCase().includes(query))
    );
});

const headers = [
    { title: 'Client', key: 'customer.name', align: 'start', sortable: true },
    { title: 'Notes', key: 'notes', align: 'start' },
    { title: 'Actions', key: 'actions', align: 'center', sortable: false },
];

const deleteDialog = ref(false);
const stopToDelete = ref(null);

const confirmRemoveStop = (stop) => {
    stopToDelete.value = stop;
    deleteDialog.value = true;
};

const removeStop = () => {
    router.delete(route('beats.beat-stops.destroy', stopToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            stopToDelete.value = null;
        }
    });
};
</script>
