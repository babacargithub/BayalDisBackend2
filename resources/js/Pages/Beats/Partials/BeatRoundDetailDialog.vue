<template>
    <v-dialog v-model="isOpen" max-width="860px" scrollable>
        <v-card>
            <!-- Header -->
            <v-card-title class="d-flex align-center justify-space-between pa-4 border-b">
                <div>
                    <span class="text-h6">Détails de la tournée</span>
                    <div v-if="roundData" class="text-body-2 text-grey mt-1">
                        {{ roundData.label }}
                    </div>
                </div>
                <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
            </v-card-title>

            <!-- Loading -->
            <div v-if="loading" class="d-flex justify-center align-center py-16">
                <v-progress-circular indeterminate color="primary" size="48" />
            </div>

            <!-- Error -->
            <div v-else-if="error" class="pa-8 text-center">
                <v-icon icon="mdi-alert-circle-outline" size="48" color="error" class="mb-3" />
                <p class="text-error">Impossible de charger les données de la tournée.</p>
            </div>

            <template v-else-if="roundData">
                <!-- Summary bar -->
                <div class="pa-4 border-b bg-grey-lighten-5">
                    <!-- Per-status count chips -->
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button
                            v-for="statusDef in roundData.available_statuses"
                            :key="statusDef.status"
                            class="status-count-chip d-flex align-center gap-2 rounded-lg border pa-2 transition-all"
                            :class="activeStatusFilters.includes(statusDef.status) ? 'chip-active' : 'bg-white'"
                            @click="toggleStatusFilter(statusDef.status)"
                        >
                            <v-icon :icon="statusIcon(statusDef.status)" size="16" :color="statusColor(statusDef.status)" />
                            <div class="text-left">
                                <div class="text-caption text-grey leading-tight">{{ statusDef.label }}</div>
                                <div class="text-subtitle-2 font-weight-bold" :class="`text-${statusColor(statusDef.status)}`">
                                    {{ statusCounts[statusDef.status] ?? 0 }}
                                </div>
                            </div>
                        </button>
                    </div>

                    <!-- Completion progress bar -->
                    <v-progress-linear
                        :model-value="completionPercentage"
                        color="success"
                        bg-color="grey-lighten-3"
                        rounded
                        height="6"
                        class="mb-3"
                    />

                    <!-- Encaissements row -->
                    <div class="d-flex flex-wrap gap-3">
                        <div class="encaissement-chip pa-3 border rounded-lg bg-white d-flex align-center gap-2">
                            <v-icon icon="mdi-cash-multiple" size="20" color="blue-darken-2" />
                            <div>
                                <div class="text-caption text-grey">Créances à encaisser</div>
                                <div class="text-subtitle-2 font-weight-bold text-blue-darken-2">
                                    {{ formatAmount(roundData.total_debt_to_collect) }}
                                </div>
                            </div>
                        </div>
                        <div class="encaissement-chip pa-3 border rounded-lg bg-white d-flex align-center gap-2">
                            <v-icon icon="mdi-check-decagram" size="20" color="green-darken-2" />
                            <div>
                                <div class="text-caption text-grey">Encaissé ce jour</div>
                                <div class="text-subtitle-2 font-weight-bold text-green-darken-2">
                                    {{ formatAmount(roundData.total_collected) }}
                                </div>
                            </div>
                        </div>
                        <div class="encaissement-chip pa-3 border rounded-lg bg-white d-flex align-center gap-2">
                            <v-icon icon="mdi-cash-remove" size="20" :color="roundData.remaining_to_collect > 0 ? 'orange-darken-2' : 'grey'" />
                            <div>
                                <div class="text-caption text-grey">Reste à encaisser</div>
                                <div
                                    class="text-subtitle-2 font-weight-bold"
                                    :class="roundData.remaining_to_collect > 0 ? 'text-orange-darken-2' : 'text-grey'"
                                >
                                    {{ formatAmount(roundData.remaining_to_collect) }}
                                </div>
                            </div>
                        </div>
                        <div class="encaissement-chip pa-3 border rounded-lg bg-white d-flex align-center gap-2">
                            <v-icon icon="mdi-bullseye-arrow" size="20" :color="strikeRateColor" />
                            <div>
                                <div class="text-caption text-grey">Taux de réussite</div>
                                <div class="text-subtitle-2 font-weight-bold" :class="`text-${strikeRateColor}`">
                                    {{ roundData.strike_rate }}%
                                    <span class="text-caption font-weight-regular ml-1">
                                        ({{ roundData.buying_customers_count }}/{{ roundData.total }})
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="encaissement-chip pa-3 border rounded-lg bg-white d-flex align-center gap-2">
                            <v-icon icon="mdi-map-marker-distance" size="20" color="purple-darken-2" />
                            <div>
                                <div class="text-caption text-grey">Distance parcourue</div>
                                <div class="text-subtitle-2 font-weight-bold text-purple-darken-2">
                                    {{ roundData.distance_km != null ? roundData.distance_km + ' km' : '— km' }}
                                </div>
                            </div>
                        </div>
                        <div v-if="roundData.vehicle" class="encaissement-chip pa-3 border rounded-lg bg-white d-flex align-center gap-2">
                            <v-icon icon="mdi-truck-outline" size="20" color="grey-darken-2" />
                            <div>
                                <div class="text-caption text-grey">Véhicule</div>
                                <div class="text-subtitle-2 font-weight-bold text-grey-darken-2">
                                    {{ roundData.vehicle.name }}
                                    <span v-if="roundData.vehicle.plate_number" class="text-caption font-weight-regular ml-1">
                                        ({{ roundData.vehicle.plate_number }})
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active filters hint -->
                <div v-if="activeStatusFilters.length > 0" class="px-4 py-2 bg-blue-lighten-5 d-flex align-center gap-2 border-b">
                    <v-icon icon="mdi-filter" size="14" color="primary" />
                    <span class="text-caption text-primary">
                        Filtre actif — {{ filteredCustomers.length }} arrêt(s) affiché(s)
                    </span>
                    <v-btn size="x-small" variant="text" color="primary" @click="activeStatusFilters = []">
                        Effacer
                    </v-btn>
                </div>

                <!-- Stops list -->
                <v-card-text class="pa-0">
                    <div v-if="filteredCustomers.length === 0" class="pa-10 text-center text-grey">
                        <v-icon icon="mdi-map-marker-off-outline" size="48" class="mb-3" />
                        <p>Aucun arrêt pour ce filtre.</p>
                    </div>

                    <v-list v-else lines="two" class="pa-0">
                        <v-list-item
                            v-for="(stop, index) in filteredCustomers"
                            :key="stop.stop_id"
                            :class="['border-b', index % 2 === 0 ? 'bg-white' : 'bg-grey-lighten-5']"
                        >
                            <template #prepend>
                                <div class="stop-position mr-3 text-center">
                                    <span class="text-caption font-weight-bold text-grey">
                                        {{ stop.display_position != null ? stop.display_position + 1 : index + 1 }}
                                    </span>
                                </div>
                            </template>

                            <template #title>
                                <div class="d-flex align-center gap-2 flex-wrap">
                                    <span class="font-weight-medium">{{ stop.name }}</span>
                                    <v-chip
                                        :color="statusColor(stop.status)"
                                        size="x-small"
                                        variant="tonal"
                                        :prepend-icon="statusIcon(stop.status)"
                                    >
                                        {{ statusLabel(stop.status) }}
                                    </v-chip>
                                    <v-chip
                                        v-if="stop.debt > 0"
                                        color="blue"
                                        size="x-small"
                                        variant="outlined"
                                        prepend-icon="mdi-cash"
                                    >
                                        {{ formatAmount(stop.debt) }}
                                    </v-chip>
                                </div>
                            </template>

                            <template #subtitle>
                                <div class="d-flex flex-column gap-1 mt-1">
                                    <div class="d-flex align-center gap-3 text-caption text-grey flex-wrap">
                                        <span v-if="stop.address">
                                            <v-icon icon="mdi-map-marker-outline" size="12" class="mr-1" />{{ stop.address }}
                                        </span>
                                        <span v-if="stop.phone_number">
                                            <v-icon icon="mdi-phone-outline" size="12" class="mr-1" />{{ stop.phone_number }}
                                        </span>
                                        <span v-if="stop.visited_at" class="text-success">
                                            <v-icon icon="mdi-clock-check-outline" size="12" class="mr-1" />{{ formatVisitTime(stop.visited_at) }}
                                        </span>
                                    </div>
                                    <div v-if="stop.notes" class="text-caption text-grey-darken-1 font-italic">
                                        <v-icon icon="mdi-note-text-outline" size="12" class="mr-1" />{{ stop.notes }}
                                    </div>
                                </div>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card-text>
            </template>

            <v-card-actions class="pa-3 border-t">
                <span v-if="roundData" class="text-caption text-grey">
                    {{ filteredCustomers.length }} / {{ roundData.customers.length }} arrêt(s)
                </span>
                <v-spacer />
                <v-btn variant="text" @click="close">Fermer</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: Boolean,
        required: true,
    },
    beatId: {
        type: Number,
        default: null,
    },
    roundDate: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const loading = ref(false);
const error = ref(false);
const roundData = ref(null);
const activeStatusFilters = ref([]);

// ─── Derived counts & filtering ───────────────────────────────────────────────

const statusCounts = computed(() => {
    if (!roundData.value) return {};
    return roundData.value.customers.reduce((acc, stop) => {
        acc[stop.status] = (acc[stop.status] || 0) + 1;
        return acc;
    }, {});
});

const filteredCustomers = computed(() => {
    if (!roundData.value) return [];
    if (activeStatusFilters.value.length === 0) return roundData.value.customers;
    return roundData.value.customers.filter((stop) => activeStatusFilters.value.includes(stop.status));
});

const completionPercentage = computed(() => {
    if (!roundData.value || roundData.value.total === 0) return 0;
    return Math.round(((roundData.value.completed + (roundData.value.no_sale ?? 0)) / roundData.value.total) * 100);
});

const strikeRateColor = computed(() => {
    if (!roundData.value) return 'grey';
    const rate = roundData.value.strike_rate ?? 0;
    if (rate >= 75) return 'green-darken-2';
    if (rate >= 50) return 'orange-darken-2';
    return 'red-darken-2';
});

const toggleStatusFilter = (status) => {
    const index = activeStatusFilters.value.indexOf(status);
    if (index === -1) {
        activeStatusFilters.value.push(status);
    } else {
        activeStatusFilters.value.splice(index, 1);
    }
};

// ─── Status display helpers ───────────────────────────────────────────────────

const statusColor = (status) => {
    if (status === 'completed') return 'success';
    if (status === 'cancelled') return 'error';
    if (status === 'planned') return 'warning';
    return 'orange-darken-2';
};

const statusIcon = (status) => {
    if (status === 'completed') return 'mdi-check-circle';
    if (status === 'cancelled') return 'mdi-close-circle';
    if (status === 'planned') return 'mdi-clock-outline';
    if (status === 'stock_restant') return 'mdi-package-variant';
    if (status === 'restaurant_ferme') return 'mdi-store-off';
    if (status === 'produits_non_disponibles') return 'mdi-cart-off';
    if (status === 'dette_non_acceptee') return 'mdi-cash-remove';
    if (status === 'reprogramme') return 'mdi-calendar-clock';
    return 'mdi-help-circle';
};

const statusLabel = (status) => {
    if (!roundData.value) return status;
    const found = roundData.value.available_statuses.find((s) => s.status === status);
    return found ? found.label : status;
};

// ─── Formatting helpers ───────────────────────────────────────────────────────

const formatAmount = (amount) => {
    if (!amount || amount === 0) return '0 XOF';
    return new Intl.NumberFormat('fr-FR').format(amount) + ' XOF';
};

const formatVisitTime = (visitedAt) => {
    if (!visitedAt) return '';
    return new Date(visitedAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
};

// ─── Data loading ─────────────────────────────────────────────────────────────

const loadRoundDetail = async () => {
    if (!props.beatId || !props.roundDate) return;

    loading.value = true;
    error.value = false;
    roundData.value = null;
    activeStatusFilters.value = [];

    try {
        const response = await axios.get(route('beats.rounds.detail', { beat: props.beatId, date: props.roundDate }));
        roundData.value = response.data;
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
};

const close = () => {
    isOpen.value = false;
};

watch(
    () => props.modelValue,
    (opened) => {
        if (opened) {
            loadRoundDetail();
        }
    }
);
</script>

<style scoped>
.status-count-chip {
    min-width: 120px;
    flex: 1;
    cursor: pointer;
    background: white;
    transition: background-color 0.15s, border-color 0.15s;
}
.status-count-chip:hover {
    background-color: rgb(var(--v-theme-surface-variant));
}
.chip-active {
    background-color: rgb(var(--v-theme-primary-lighten-5), 0.15) !important;
    border-color: rgb(var(--v-theme-primary)) !important;
}
.encaissement-chip {
    min-width: 180px;
    flex: 1;
}
.stop-position {
    width: 24px;
    min-width: 24px;
}
</style>
