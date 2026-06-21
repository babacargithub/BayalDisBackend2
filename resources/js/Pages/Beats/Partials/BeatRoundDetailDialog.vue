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
                <!-- Counts + encaissements summary bar -->
                <div class="pa-4 border-b bg-grey-lighten-5">
                    <!-- Visit counts row -->
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <div class="summary-stat-chip bg-white border rounded-lg pa-3 d-flex align-center gap-2">
                            <v-icon icon="mdi-account-group-outline" size="20" color="primary" />
                            <div>
                                <div class="text-caption text-grey">Total arrêts</div>
                                <div class="text-subtitle-2 font-weight-bold">{{ roundData.total }}</div>
                            </div>
                        </div>
                        <div class="summary-stat-chip bg-white border rounded-lg pa-3 d-flex align-center gap-2">
                            <v-icon icon="mdi-check-circle-outline" size="20" color="success" />
                            <div>
                                <div class="text-caption text-grey">Visités</div>
                                <div class="text-subtitle-2 font-weight-bold text-success">{{ roundData.completed }}</div>
                            </div>
                        </div>
                        <div class="summary-stat-chip bg-white border rounded-lg pa-3 d-flex align-center gap-2">
                            <v-icon icon="mdi-close-circle-outline" size="20" color="error" />
                            <div>
                                <div class="text-caption text-grey">Annulés</div>
                                <div class="text-subtitle-2 font-weight-bold text-error">{{ roundData.cancelled }}</div>
                            </div>
                        </div>
                        <div class="summary-stat-chip bg-white border rounded-lg pa-3 d-flex align-center gap-2">
                            <v-icon icon="mdi-cart-off" size="20" color="orange-darken-2" />
                            <div>
                                <div class="text-caption text-grey">Non vendus</div>
                                <div class="text-subtitle-2 font-weight-bold text-orange-darken-2">{{ roundData.no_sale }}</div>
                            </div>
                        </div>
                        <div class="summary-stat-chip bg-white border rounded-lg pa-3 d-flex align-center gap-2">
                            <v-icon icon="mdi-clock-outline" size="20" color="warning" />
                            <div>
                                <div class="text-caption text-grey">Prévus</div>
                                <div class="text-subtitle-2 font-weight-bold text-warning">{{ roundData.planned }}</div>
                            </div>
                        </div>
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
                    </div>
                </div>

                <!-- Stops list -->
                <v-card-text class="pa-0">
                    <div v-if="roundData.customers.length === 0" class="pa-10 text-center text-grey">
                        <v-icon icon="mdi-map-marker-off-outline" size="48" class="mb-3" />
                        <p>Aucun arrêt pour cette tournée.</p>
                    </div>

                    <v-list v-else lines="two" class="pa-0">
                        <v-list-item
                            v-for="(stop, index) in roundData.customers"
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
                    {{ roundData.customers.length }} arrêt(s)
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

const completionPercentage = computed(() => {
    if (!roundData.value || roundData.value.total === 0) return 0;
    return Math.round(((roundData.value.completed + (roundData.value.no_sale ?? 0)) / roundData.value.total) * 100);
});

const NO_SALE_STATUS_LABELS = {
    stock_restant: 'Stock restant',
    restaurant_ferme: 'Restaurant fermé',
    produits_non_disponibles: 'Produits non disponibles',
    dette_non_acceptee: 'Dette non acceptée',
    reprogramme: 'Reprogrammé',
};

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
    return 'mdi-cart-off';
};

const statusLabel = (status) => {
    if (status === 'completed') return 'Visité';
    if (status === 'cancelled') return 'Annulé';
    if (status === 'planned') return 'Prévu';
    return NO_SALE_STATUS_LABELS[status] ?? status;
};

const formatAmount = (amount) => {
    if (!amount || amount === 0) return '0 XOF';
    return new Intl.NumberFormat('fr-FR').format(amount) + ' XOF';
};

const formatVisitTime = (visitedAt) => {
    if (!visitedAt) return '';
    return new Date(visitedAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
};

const loadRoundDetail = async () => {
    if (!props.beatId || !props.roundDate) return;

    loading.value = true;
    error.value = false;
    roundData.value = null;

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
.summary-stat-chip {
    min-width: 110px;
    flex: 1;
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
