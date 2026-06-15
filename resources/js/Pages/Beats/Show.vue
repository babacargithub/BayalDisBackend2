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
                <!-- Beat Info Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Tournées effectuées</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ pastRoundsCount }} tournée(s)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <v-tabs v-model="activeTab" color="primary" class="border-b">
                        <v-tab value="clients" prepend-icon="mdi-account-group-outline">
                            Clients récurrents
                            <v-chip size="x-small" class="ml-2" color="primary" variant="tonal">
                                {{ batch.visits.length }}
                            </v-chip>
                        </v-tab>
                        <v-tab value="rounds" prepend-icon="mdi-map-marker-path">
                            Tournées
                            <v-chip size="x-small" class="ml-2" color="primary" variant="tonal">
                                {{ rounds.length }}
                            </v-chip>
                        </v-tab>
                    </v-tabs>

                    <v-window v-model="activeTab">
                        <!-- Clients récurrents tab -->
                        <v-window-item value="clients">
                            <!-- Search -->
                            <div class="p-4 border-b">
                                <v-text-field
                                    v-model="searchQuery"
                                    label="Rechercher un client"
                                    prepend-inner-icon="mdi-magnify"
                                    hide-details
                                    density="compact"
                                    variant="outlined"
                                    class="max-w-sm"
                                />
                            </div>

                            <div class="px-4 py-2 border-b bg-gray-50">
                                <p class="text-sm text-gray-500">
                                    Ces clients sont automatiquement ajoutés à la tournée chaque {{ batch.day_of_week_label?.toLowerCase() }}.
                                </p>
                            </div>

                            <v-data-table
                                :headers="clientHeaders"
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
                        </v-window-item>

                        <!-- Tournées tab -->
                        <v-window-item value="rounds">
                            <!-- Empty state -->
                            <div v-if="rounds.length === 0" class="pa-16 text-center text-grey">
                                <v-icon icon="mdi-calendar-blank-outline" size="56" class="mb-4" />
                                <p class="text-subtitle-1">Aucune tournée enregistrée pour ce beat.</p>
                                <p class="text-body-2 mt-1">
                                    Les tournées apparaîtront ici lorsqu'elles seront créées automatiquement le {{ batch.day_of_week_label?.toLowerCase() }}.
                                </p>
                            </div>

                            <!-- Rounds list -->
                            <div v-else class="divide-y">
                                <div
                                    v-for="round in rounds"
                                    :key="round.date"
                                    class="flex items-center justify-between px-6 py-4 hover:bg-gray-50 cursor-pointer transition-colors"
                                    @click="openRoundDetail(round)"
                                >
                                    <!-- Left: date + status -->
                                    <div class="flex items-center gap-4">
                                        <!-- Date block -->
                                        <div class="date-block text-center rounded-lg border px-3 py-2 min-w-[64px]">
                                            <div class="text-xs font-medium text-gray-500 uppercase">
                                                {{ formatDayAbbr(round.date) }}
                                            </div>
                                            <div class="text-xl font-bold text-gray-900 leading-tight">
                                                {{ formatDayNumber(round.date) }}
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                {{ formatMonthYear(round.date) }}
                                            </div>
                                        </div>

                                        <!-- Info -->
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-medium text-gray-900 text-sm">{{ round.label }}</span>
                                                <v-chip
                                                    :color="roundStatusColor(round.status)"
                                                    size="x-small"
                                                    variant="tonal"
                                                >
                                                    {{ roundStatusLabel(round.status) }}
                                                </v-chip>
                                            </div>
                                            <!-- Progress chips -->
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="inline-flex items-center gap-1 text-xs text-success font-medium">
                                                    <v-icon icon="mdi-check-circle" size="13" color="success" />
                                                    {{ round.completed }} visité(s)
                                                </span>
                                                <span v-if="round.cancelled > 0" class="inline-flex items-center gap-1 text-xs text-error font-medium">
                                                    <v-icon icon="mdi-close-circle" size="13" color="error" />
                                                    {{ round.cancelled }} annulé(s)
                                                </span>
                                                <span v-if="round.planned > 0" class="inline-flex items-center gap-1 text-xs text-warning font-medium">
                                                    <v-icon icon="mdi-clock-outline" size="13" color="warning" />
                                                    {{ round.planned }} prévu(s)
                                                </span>
                                                <span class="text-xs text-gray-400">/ {{ round.total }} total</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right: progress + chevron -->
                                    <div class="flex items-center gap-4">
                                        <!-- Progress bar + % -->
                                        <div v-if="round.total > 0" class="text-right hidden sm:block">
                                            <div class="text-xs text-gray-500 mb-1">
                                                {{ roundCompletionPercentage(round) }}% complété
                                            </div>
                                            <div class="w-24 bg-gray-200 rounded-full h-1.5">
                                                <div
                                                    class="h-1.5 rounded-full transition-all"
                                                    :class="roundProgressBarColor(round)"
                                                    :style="{ width: roundCompletionPercentage(round) + '%' }"
                                                />
                                            </div>
                                        </div>
                                        <v-icon icon="mdi-chevron-right" color="grey" />
                                    </div>
                                </div>
                            </div>
                        </v-window-item>
                    </v-window>
                </div>
            </div>
        </div>

        <!-- Remove Stop Confirmation Dialog -->
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

        <!-- Round Detail Dialog -->
        <BeatRoundDetailDialog
            v-model="roundDetailDialogOpen"
            :beat-id="batch.id"
            :round-date="selectedRoundDate"
        />
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BeatRoundDetailDialog from './Partials/BeatRoundDetailDialog.vue';

const props = defineProps({
    batch: {
        type: Object,
        required: true,
    },
    rounds: {
        type: Array,
        default: () => [],
    },
});

// ─── Tabs ─────────────────────────────────────────────────────────────────────

const activeTab = ref('clients');

// ─── Clients récurrents tab ───────────────────────────────────────────────────

const searchQuery = ref('');

const filteredStops = computed(() => {
    if (!searchQuery.value) return props.batch.visits;
    const query = searchQuery.value.toLowerCase();
    return props.batch.visits.filter(
        (stop) =>
            stop.customer.name.toLowerCase().includes(query) ||
            (stop.customer.address && stop.customer.address.toLowerCase().includes(query)) ||
            (stop.customer.phone_number && stop.customer.phone_number.toLowerCase().includes(query))
    );
});

const clientHeaders = [
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
        },
    });
};

// ─── Rounds tab ───────────────────────────────────────────────────────────────

const pastRoundsCount = computed(() => props.rounds.filter((r) => r.status !== 'upcoming').length);

const roundDetailDialogOpen = ref(false);
const selectedRoundDate = ref(null);

const openRoundDetail = (round) => {
    selectedRoundDate.value = round.date;
    roundDetailDialogOpen.value = true;
};

const roundStatusColor = (status) => {
    if (status === 'done') return 'success';
    if (status === 'in_progress') return 'warning';
    return 'info';
};

const roundStatusLabel = (status) => {
    if (status === 'done') return 'Terminée';
    if (status === 'in_progress') return 'En cours';
    return 'À venir';
};

const roundCompletionPercentage = (round) => {
    if (round.total === 0) return 0;
    return Math.round((round.completed / round.total) * 100);
};

const roundProgressBarColor = (round) => {
    const pct = roundCompletionPercentage(round);
    if (pct === 100) return 'bg-green-500';
    if (pct >= 50) return 'bg-yellow-500';
    return 'bg-blue-400';
};

// ─── Date formatting helpers ──────────────────────────────────────────────────

const formatDayAbbr = (dateString) => {
    return new Date(dateString).toLocaleDateString('fr-FR', { weekday: 'short' });
};

const formatDayNumber = (dateString) => {
    return new Date(dateString).getDate();
};

const formatMonthYear = (dateString) => {
    return new Date(dateString).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
};
</script>
