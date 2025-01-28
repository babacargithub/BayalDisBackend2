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
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    batches: {
        type: Array,
        required: true
    }
});

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};
</script> 