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
                            <!-- Top bar -->
                            <div class="flex items-center justify-between p-4 border-b gap-4">
                                <v-text-field
                                    v-model="searchQuery"
                                    label="Rechercher un client"
                                    prepend-inner-icon="mdi-magnify"
                                    hide-details
                                    density="compact"
                                    variant="outlined"
                                    class="max-w-sm"
                                />
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-account-plus"
                                    size="small"
                                    @click="openAddCustomersDialog"
                                >
                                    Ajouter des clients
                                </v-btn>
                            </div>

                            <div class="px-4 py-2 border-b bg-gray-50">
                                <p class="text-sm text-gray-500">
                                    Ces clients sont automatiquement ajoutés à la tournée chaque {{ batch.day_of_week_label?.toLowerCase() }}.
                                </p>
                            </div>

                            <!-- Draggable clients list -->
                            <div v-if="filteredStops.length === 0" class="py-10 text-center text-gray-400">
                                <v-icon icon="mdi-account-off-outline" size="40" class="mb-2" />
                                <p class="text-sm">Aucun client récurrent.</p>
                            </div>

                            <div
                                v-else
                                class="divide-y"
                                @dragover.prevent
                            >
                                <div
                                    v-for="(stop, index) in filteredStops"
                                    :key="stop.id"
                                    draggable="true"
                                    class="flex items-center gap-3 px-4 py-3 transition-colors"
                                    :class="dragOverIndex === index ? 'bg-blue-50 border-t-2 border-blue-400' : (index % 2 === 0 ? 'bg-white' : 'bg-gray-50')"
                                    @dragstart="onDragStart(index)"
                                    @dragenter.prevent="onDragEnter(index)"
                                    @dragleave="onDragLeave"
                                    @drop.prevent="onDrop(index)"
                                    @dragend="onDragEnd"
                                >
                                    <!-- Drag handle -->
                                    <v-icon
                                        icon="mdi-drag-vertical"
                                        size="20"
                                        color="grey-lighten-1"
                                        class="cursor-grab flex-shrink-0"
                                    />

                                    <!-- Position number -->
                                    <span class="text-xs text-gray-400 w-5 text-center flex-shrink-0">{{ index + 1 }}</span>

                                    <!-- Customer info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium text-sm text-gray-900">{{ stop.customer.name }}</div>
                                        <div class="text-xs text-gray-500 truncate">
                                            <span v-if="stop.customer.address">{{ stop.customer.address }}</span>
                                            <span v-if="stop.customer.phone_number" class="ml-2">{{ stop.customer.phone_number }}</span>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <span v-if="stop.notes" class="text-xs text-gray-400 hidden sm:block truncate max-w-[160px]">{{ stop.notes }}</span>

                                    <!-- Delete -->
                                    <v-btn
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        size="small"
                                        class="flex-shrink-0"
                                        @click.stop="confirmRemoveStop(stop)"
                                    />
                                </div>
                            </div>

                            <!-- Saving indicator -->
                            <div v-if="reorderSaving" class="px-4 py-2 bg-blue-50 border-t flex items-center gap-2 text-sm text-blue-600">
                                <v-progress-circular indeterminate size="14" width="2" color="primary" />
                                Enregistrement de l'ordre…
                            </div>
                        </v-window-item>

                        <!-- Tournées tab -->
                        <v-window-item value="rounds">
                            <!-- Top bar -->
                            <div class="flex items-center justify-between px-6 py-3 border-b bg-gray-50">
                                <p class="text-sm text-gray-500">
                                    {{ rounds.length }} tournée(s) enregistrée(s)
                                </p>
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-plus"
                                    size="small"
                                    @click="createRoundDialog = true"
                                >
                                    Nouvelle tournée
                                </v-btn>
                            </div>

                            <!-- Empty state -->
                            <div v-if="rounds.length === 0" class="pa-16 text-center text-grey">
                                <v-icon icon="mdi-calendar-blank-outline" size="56" class="mb-4" />
                                <p class="text-subtitle-1">Aucune tournée enregistrée pour ce beat.</p>
                                <p class="text-body-2 mt-1">
                                    Les tournées apparaîtront ici une fois créées depuis l'application mobile.
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

                                    <!-- Right: progress + delete + chevron -->
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
                                        <v-btn
                                            v-if="round.id"
                                            icon="mdi-delete-outline"
                                            variant="text"
                                            color="error"
                                            size="small"
                                            @click.stop="confirmDeleteRound(round)"
                                        />
                                        <v-icon icon="mdi-chevron-right" color="grey" />
                                    </div>
                                </div>
                            </div>
                        </v-window-item>
                    </v-window>
                </div>
            </div>
        </div>

        <!-- Add Customers Dialog -->
        <v-dialog v-model="addCustomersDialog" max-width="640px" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center justify-space-between pa-4 border-b">
                    <span class="text-h6">Ajouter des clients au beat</span>
                    <v-btn icon="mdi-close" variant="text" size="small" @click="addCustomersDialog = false" />
                </v-card-title>

                <div class="px-4 pt-3 pb-2 border-b">
                    <v-text-field
                        v-model="addCustomersSearch"
                        label="Rechercher"
                        prepend-inner-icon="mdi-magnify"
                        hide-details
                        density="compact"
                        variant="outlined"
                        clearable
                    />
                </div>

                <v-card-text class="pa-0" style="max-height: 400px;">
                    <div v-if="filteredAvailableCustomers.length === 0" class="pa-8 text-center text-grey">
                        <v-icon icon="mdi-account-off-outline" size="40" class="mb-2" />
                        <p class="text-body-2">Aucun client disponible.</p>
                    </div>
                    <v-list v-else class="pa-0">
                        <v-list-item
                            v-for="customer in filteredAvailableCustomers"
                            :key="customer.id"
                            :value="customer.id"
                            class="border-b"
                            @click="toggleCustomerSelection(customer.id)"
                        >
                            <template #prepend>
                                <v-checkbox-btn
                                    :model-value="selectedCustomerIds.includes(customer.id)"
                                    color="primary"
                                    @click.stop="toggleCustomerSelection(customer.id)"
                                />
                            </template>
                            <v-list-item-title class="font-weight-medium">{{ customer.name }}</v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                <span v-if="customer.address">{{ customer.address }}</span>
                                <span v-if="customer.phone_number" class="ml-2">{{ customer.phone_number }}</span>
                                <v-chip
                                    v-if="customer.current_beat"
                                    size="x-small"
                                    color="warning"
                                    variant="tonal"
                                    prepend-icon="mdi-swap-horizontal"
                                    class="ml-2"
                                >
                                    {{ customer.current_beat.name }}
                                </v-chip>
                            </v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </v-card-text>

                <v-card-actions class="pa-4 border-t d-flex flex-column align-start gap-1">
                    <div v-if="selectedTransferCount > 0" class="text-caption text-warning d-flex align-center gap-1 w-100">
                        <v-icon icon="mdi-alert" size="14" color="warning" />
                        {{ selectedTransferCount }} client(s) seront transférés depuis un autre beat.
                    </div>
                    <div class="d-flex align-center w-100">
                        <span class="text-caption text-grey">{{ selectedCustomerIds.length }} sélectionné(s)</span>
                        <v-spacer />
                        <v-btn variant="text" @click="addCustomersDialog = false">Annuler</v-btn>
                        <v-btn
                            color="primary"
                            variant="flat"
                            :disabled="selectedCustomerIds.length === 0"
                            :loading="addingCustomers"
                            @click="submitAddCustomers"
                        >
                            Ajouter
                        </v-btn>
                    </div>
                </v-card-actions>
            </v-card>
        </v-dialog>

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

        <!-- Create Round Dialog -->
        <v-dialog v-model="createRoundDialog" max-width="420px">
            <v-card>
                <v-card-title class="text-h6 pt-5 px-6">Nouvelle tournée</v-card-title>
                <v-card-text class="px-6">
                    <v-text-field
                        v-model="createRoundForm.planned_at"
                        label="Date de la tournée"
                        type="date"
                        :error-messages="createRoundErrors.planned_at"
                        variant="outlined"
                        density="compact"
                    />
                </v-card-text>
                <v-card-actions class="px-6 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="closeCreateRoundDialog">Annuler</v-btn>
                    <v-btn color="primary" variant="flat" :loading="creatingRound" @click="submitCreateRound">
                        Créer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Round Confirmation Dialog -->
        <v-dialog v-model="deleteRoundDialog" max-width="480px">
            <v-card>
                <v-card-title class="text-h6 pt-5 px-6">
                    Supprimer la tournée
                </v-card-title>
                <v-card-text class="px-6">
                    <p>
                        Êtes-vous sûr de vouloir supprimer la tournée du
                        <strong>{{ roundToDelete?.label }}</strong> ?
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        Tous les arrêts liés à cette tournée seront également supprimés. Cette action est irréversible.
                    </p>
                </v-card-text>
                <v-card-actions class="px-6 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="deleteRoundDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" :loading="deletingRound" @click="deleteRound">
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
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
    availableCustomers: {
        type: Array,
        default: () => [],
    },
});

// ─── Tabs ─────────────────────────────────────────────────────────────────────

const activeTab = ref('clients');

// ─── Clients récurrents tab ───────────────────────────────────────────────────

const searchQuery = ref('');

const filteredStops = computed(() => {
    if (!searchQuery.value) return localVisits.value;
    const query = searchQuery.value.toLowerCase();
    return localVisits.value.filter(
        (stop) =>
            stop.customer.name.toLowerCase().includes(query) ||
            (stop.customer.address && stop.customer.address.toLowerCase().includes(query)) ||
            (stop.customer.phone_number && stop.customer.phone_number.toLowerCase().includes(query))
    );
});

// ─── Drag-to-reorder ─────────────────────────────────────────────────────────

const localVisits = ref([...props.batch.visits]);
const dragSourceIndex = ref(null);
const dragOverIndex = ref(null);
const reorderSaving = ref(false);

watch(() => props.batch.visits, (newVisits) => {
    localVisits.value = [...newVisits];
});

const onDragStart = (index) => {
    dragSourceIndex.value = index;
};

const onDragEnter = (index) => {
    if (dragSourceIndex.value !== null && dragSourceIndex.value !== index) {
        dragOverIndex.value = index;
    }
};

const onDragLeave = () => {
    // keep dragOverIndex until drop or end to avoid flicker
};

const onDrop = (targetIndex) => {
    if (dragSourceIndex.value === null || dragSourceIndex.value === targetIndex) return;

    const sourceStop = filteredStops.value[dragSourceIndex.value];
    const targetStop = filteredStops.value[targetIndex];

    const sourceLocalIndex = localVisits.value.findIndex((s) => s.id === sourceStop.id);
    const targetLocalIndex = localVisits.value.findIndex((s) => s.id === targetStop.id);

    const items = [...localVisits.value];
    const [moved] = items.splice(sourceLocalIndex, 1);
    items.splice(targetLocalIndex, 0, moved);
    localVisits.value = items;

    dragOverIndex.value = null;
    dragSourceIndex.value = null;
    saveReorder();
};

const onDragEnd = () => {
    dragSourceIndex.value = null;
    dragOverIndex.value = null;
};

const saveReorder = () => {
    reorderSaving.value = true;
    const positions = localVisits.value.map((stop, index) => ({
        stop_id: stop.id,
        display_position: index,
    }));
    axios.put(route('beats.reorder-customers', props.batch.id), { positions })
        .finally(() => {
            reorderSaving.value = false;
        });
};

// ─── Add customers ────────────────────────────────────────────────────────────

const addCustomersDialog = ref(false);
const addCustomersSearch = ref('');
const selectedCustomerIds = ref([]);
const addingCustomers = ref(false);

const selectedTransferCount = computed(() =>
    props.availableCustomers.filter(
        (c) => selectedCustomerIds.value.includes(c.id) && c.current_beat !== null
    ).length
);

const filteredAvailableCustomers = computed(() => {
    if (!addCustomersSearch.value) return props.availableCustomers;
    const query = addCustomersSearch.value.toLowerCase();
    return props.availableCustomers.filter(
        (c) =>
            c.name.toLowerCase().includes(query) ||
            (c.address && c.address.toLowerCase().includes(query)) ||
            (c.phone_number && c.phone_number.toLowerCase().includes(query))
    );
});

const openAddCustomersDialog = () => {
    selectedCustomerIds.value = [];
    addCustomersSearch.value = '';
    addCustomersDialog.value = true;
};

const toggleCustomerSelection = (customerId) => {
    const index = selectedCustomerIds.value.indexOf(customerId);
    if (index === -1) {
        selectedCustomerIds.value.push(customerId);
    } else {
        selectedCustomerIds.value.splice(index, 1);
    }
};

const submitAddCustomers = () => {
    addingCustomers.value = true;
    router.post(route('beats.add-customers', props.batch.id), { customer_ids: selectedCustomerIds.value }, {
        onSuccess: () => {
            addCustomersDialog.value = false;
            selectedCustomerIds.value = [];
        },
        onFinish: () => {
            addingCustomers.value = false;
        },
    });
};

// ─── Remove stop ─────────────────────────────────────────────────────────────

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

const pastRoundsCount = computed(() => props.rounds.filter((r) => r.status === 'done' || r.status === 'in_progress').length);

const roundDetailDialogOpen = ref(false);
const selectedRoundDate = ref(null);

const openRoundDetail = (round) => {
    selectedRoundDate.value = round.date;
    roundDetailDialogOpen.value = true;
};

// ─── Create Round ─────────────────────────────────────────────────────────────

const createRoundDialog = ref(false);
const creatingRound = ref(false);
const createRoundForm = useForm({ planned_at: '' });
const createRoundErrors = ref({});

const closeCreateRoundDialog = () => {
    createRoundDialog.value = false;
    createRoundForm.reset();
    createRoundErrors.value = {};
};

const submitCreateRound = () => {
    creatingRound.value = true;
    createRoundErrors.value = {};
    router.post(route('beats.rounds.store', props.batch.id), { planned_at: createRoundForm.planned_at }, {
        onSuccess: () => {
            createRoundDialog.value = false;
            createRoundForm.reset();
        },
        onError: (errors) => {
            createRoundErrors.value = errors;
        },
        onFinish: () => {
            creatingRound.value = false;
        },
    });
};

// ─── Delete Round ──────────────────────────────────────────────────────────────

const deleteRoundDialog = ref(false);
const roundToDelete = ref(null);
const deletingRound = ref(false);

const confirmDeleteRound = (round) => {
    roundToDelete.value = round;
    deleteRoundDialog.value = true;
};

const deleteRound = () => {
    deletingRound.value = true;
    router.delete(route('beats.rounds.destroy', [props.batch.id, roundToDelete.value.id]), {
        onSuccess: () => {
            deleteRoundDialog.value = false;
            roundToDelete.value = null;
        },
        onFinish: () => {
            deletingRound.value = false;
        },
    });
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
