<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    goals: Array,
    commerciaux: Array,
    availableMetrics: Array,
    selectedCommercialId: Number,
    currentScope: String,
});

// ── Scope + Filter ───────────────────────────────────────────────────────────

const activeScope = ref(props.currentScope ?? 'company');
const selectedCommercialId = ref(props.selectedCommercialId ?? null);

const applyFilter = () => {
    const params = { scope: activeScope.value };
    if (activeScope.value === 'commercial' && selectedCommercialId.value) {
        params.commercial_id = selectedCommercialId.value;
    }
    router.get(route('goals.index'), params, { preserveState: true, replace: true });
};

watch(activeScope, (newScope) => {
    selectedCommercialId.value = null;
    createForm.assignee_type = newScope === 'company' ? 'company' : 'commercial';
    createForm.assignee_id = null;
    applyFilter();
});

watch(selectedCommercialId, () => {
    applyFilter();
});

// ── Create dialog ────────────────────────────────────────────────────────────

const createDialogVisible = ref(false);

const createForm = useForm({
    assignee_type: props.currentScope === 'company' ? 'company' : 'commercial',
    assignee_id: null,
    metric: null,
    target_value: null,
    period_start: new Date().toISOString().slice(0, 7) + '-01',
    period_end: (() => {
        const d = new Date();
        d.setMonth(d.getMonth() + 1, 0);
        return d.toISOString().slice(0, 10);
    })(),
});

const selectedMetricMeta = computed(() =>
    props.availableMetrics.find((m) => m.value === createForm.metric) ?? null,
);

const submitCreateGoal = () => {
    createForm.post(route('goals.store'), {
        onSuccess: () => {
            createDialogVisible.value = false;
            createForm.reset();
        },
    });
};

// ── Delete ───────────────────────────────────────────────────────────────────

const goalToDelete = ref(null);
const deleteDialogVisible = ref(false);
const isDeleting = ref(false);

const confirmDelete = (goal) => {
    goalToDelete.value = goal;
    deleteDialogVisible.value = true;
};

const deleteGoal = () => {
    isDeleting.value = true;
    router.delete(route('goals.destroy', goalToDelete.value.goal_id), {
        onSuccess: () => {
            deleteDialogVisible.value = false;
            goalToDelete.value = null;
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const attainmentColor = (rate, achieved) => {
    if (achieved) { return 'success'; }
    if (rate >= 75) { return 'warning'; }
    if (rate >= 40) { return 'orange'; }
    return 'error';
};

const attainmentProgressColor = (rate, achieved) => {
    if (achieved) { return '#4CAF50'; }
    if (rate >= 75) { return '#FF9800'; }
    if (rate >= 40) { return '#FF5722'; }
    return '#F44336';
};

const clampedRate = (rate) => Math.min(rate, 100);

const formatDate = (dateString) =>
    new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(dateString));

const formatPeriod = (start, end) => `${formatDate(start)} → ${formatDate(end)}`;

const isCurrentPeriod = (start, end) => {
    const today = new Date();
    return new Date(start) <= today && today <= new Date(end);
};

const isPastPeriod = (end) => new Date(end) < new Date();

const metricCategoryIcon = (metric) => {
    const icons = {
        total_revenue: 'mdi-currency-usd',
        average_basket_size: 'mdi-cart',
        total_outstanding_amount: 'mdi-clock-alert',
        push_score: 'mdi-chart-bubble',
        visit_strike_rate: 'mdi-map-marker-check',
        acquisition_strike_rate: 'mdi-account-plus',
        new_confirmed_customers: 'mdi-account-star',
        on_time_payment_rate: 'mdi-check-circle',
        collection_effectiveness_index: 'mdi-cash-sync',
        customer_retention_rate: 'mdi-account-heart',
        churning_rate: 'mdi-account-arrow-right',
        average_days_to_payment: 'mdi-calendar-clock',
        median_days_to_payment: 'mdi-calendar-clock',
        average_days_delinquent: 'mdi-calendar-remove',
        median_days_delinquent: 'mdi-calendar-remove',
    };
    return icons[metric] ?? 'mdi-target';
};

const metricCategoryColor = (metric) => {
    const salesMetrics = ['total_revenue', 'average_basket_size', 'push_score'];
    const coverageMetrics = ['visit_strike_rate', 'acquisition_strike_rate', 'new_confirmed_customers', 'customer_retention_rate', 'churning_rate'];
    const arMetrics = ['total_outstanding_amount', 'on_time_payment_rate', 'collection_effectiveness_index', 'average_days_to_payment', 'median_days_to_payment', 'average_days_delinquent', 'median_days_delinquent'];

    if (salesMetrics.includes(metric)) { return 'indigo'; }
    if (coverageMetrics.includes(metric)) { return 'teal'; }
    if (arMetrics.includes(metric)) { return 'purple'; }
    return 'blue-grey';
};

const achievedCount = computed(() => props.goals.filter((g) => g.achieved).length);
const totalCount = computed(() => props.goals.length);
const overallRate = computed(() =>
    totalCount.value > 0 ? Math.round((achievedCount.value / totalCount.value) * 100) : 0,
);
</script>

<template>
    <Head title="Objectifs" />

    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex align-center justify-space-between">
                <h2 class="font-semibold text-xl leading-tight">Objectifs Commerciaux</h2>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="createDialogVisible = true">
                    Nouvel objectif
                </v-btn>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- ── Scope selector + Filter bar ───────────────────────────── -->
                <v-card class="mb-6" elevation="1">
                    <!-- Scope radio buttons -->
                    <div class="px-4 pt-4 pb-2">
                        <v-btn-toggle
                            v-model="activeScope"
                            mandatory
                            color="primary"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                        >
                            <v-btn value="company" prepend-icon="mdi-domain">
                                Objectifs Entreprise
                            </v-btn>
                            <v-btn value="commercial" prepend-icon="mdi-account-tie">
                                Objectifs Commerciaux
                            </v-btn>
                        </v-btn-toggle>
                    </div>

                    <v-divider />

                    <!-- Commercial filter (only when in commercial scope) -->
                    <div class="pa-4 d-flex align-center gap-4 flex-wrap">
                        <template v-if="activeScope === 'commercial'">
                            <v-icon color="grey-darken-1">mdi-filter-variant</v-icon>
                            <v-select
                                v-model="selectedCommercialId"
                                :items="[{ id: null, name: 'Tous les commerciaux' }, ...commerciaux]"
                                item-title="name"
                                item-value="id"
                                label="Filtrer par commercial"
                                density="compact"
                                hide-details
                                clearable
                                style="max-width: 320px"
                                variant="outlined"
                            />
                        </template>
                        <template v-else>
                            <v-icon color="grey-darken-1">mdi-domain</v-icon>
                            <span class="text-body-2 text-grey-darken-1">
                                Objectifs globaux définis pour l'ensemble de l'entreprise
                            </span>
                        </template>

                        <v-spacer />

                        <div v-if="goals.length > 0" class="d-flex align-center gap-6 text-body-2">
                            <div class="d-flex align-center gap-1">
                                <v-icon color="success" size="18">mdi-check-circle</v-icon>
                                <span>
                                    <strong>{{ achievedCount }}</strong> / {{ totalCount }} atteints
                                </span>
                            </div>
                            <v-chip
                                :color="overallRate >= 80 ? 'success' : overallRate >= 50 ? 'warning' : 'error'"
                                size="small"
                                variant="tonal"
                            >
                                {{ overallRate }}% de réussite
                            </v-chip>
                        </div>
                    </div>
                </v-card>

                <!-- ── Empty state ────────────────────────────────────────────── -->
                <div v-if="goals.length === 0" class="text-center py-16">
                    <v-icon size="72" color="grey-lighten-1">mdi-target</v-icon>
                    <p class="text-h6 text-grey mt-4">Aucun objectif défini</p>
                    <p class="text-body-2 text-grey-darken-1 mt-1">
                        Créez un premier objectif pour commencer à suivre les performances.
                    </p>
                    <v-btn color="primary" class="mt-4" prepend-icon="mdi-plus" @click="createDialogVisible = true">
                        Créer un objectif
                    </v-btn>
                </div>

                <!-- ── Goals grid ─────────────────────────────────────────────── -->
                <v-row v-else>
                    <v-col
                        v-for="goal in goals"
                        :key="goal.goal_id"
                        cols="12"
                        sm="6"
                        lg="4"
                    >
                        <v-card
                            :href="route('goals.show', goal.goal_id)"
                            elevation="2"
                            rounded="lg"
                            class="goal-card h-100"
                            :class="{ 'goal-card--achieved': goal.achieved }"
                            style="cursor: pointer; text-decoration: none"
                        >
                            <!-- Colored top accent bar -->
                            <div
                                class="goal-card__accent"
                                :style="`background: ${attainmentProgressColor(goal.attainment_rate, goal.achieved)}`"
                            />

                            <v-card-text class="pa-5">
                                <!-- Header row: icon + metric label + status chip -->
                                <div class="d-flex align-start justify-space-between mb-3">
                                    <div class="d-flex align-center gap-2">
                                        <v-avatar
                                            :color="metricCategoryColor(goal.metric)"
                                            size="38"
                                            variant="tonal"
                                        >
                                            <v-icon size="20">{{ metricCategoryIcon(goal.metric) }}</v-icon>
                                        </v-avatar>
                                        <div>
                                            <p class="text-body-1 font-weight-semibold leading-tight">
                                                {{ goal.metric_label }}
                                            </p>
                                            <p class="text-caption text-grey-darken-1">
                                                {{ goal.assignee_name }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column align-end gap-1">
                                        <v-chip
                                            v-if="goal.achieved"
                                            color="success"
                                            size="small"
                                            prepend-icon="mdi-check"
                                            variant="tonal"
                                        >
                                            Atteint
                                        </v-chip>
                                        <v-chip
                                            v-else-if="isPastPeriod(goal.period_end)"
                                            color="error"
                                            size="small"
                                            prepend-icon="mdi-close"
                                            variant="tonal"
                                        >
                                            Non atteint
                                        </v-chip>
                                        <v-chip
                                            v-else-if="isCurrentPeriod(goal.period_start, goal.period_end)"
                                            color="blue"
                                            size="small"
                                            prepend-icon="mdi-clock-outline"
                                            variant="tonal"
                                        >
                                            En cours
                                        </v-chip>
                                        <v-chip
                                            v-else
                                            color="grey"
                                            size="small"
                                            prepend-icon="mdi-calendar-future"
                                            variant="tonal"
                                        >
                                            À venir
                                        </v-chip>
                                        <v-chip
                                            v-if="goal.child_goals_count > 0"
                                            color="indigo"
                                            size="x-small"
                                            prepend-icon="mdi-sitemap"
                                            variant="tonal"
                                        >
                                            {{ goal.child_goals_count }} sous-objectif{{ goal.child_goals_count > 1 ? 's' : '' }}
                                        </v-chip>
                                    </div>
                                </div>

                                <!-- Progress bar -->
                                <div class="mb-3">
                                    <div class="d-flex justify-space-between align-center mb-1">
                                        <span class="text-caption text-grey-darken-1">Progression</span>
                                        <span
                                            class="text-body-2 font-weight-bold"
                                            :style="`color: ${attainmentProgressColor(goal.attainment_rate, goal.achieved)}`"
                                        >
                                            {{ goal.attainment_rate }}%
                                        </span>
                                    </div>
                                    <v-progress-linear
                                        :model-value="clampedRate(goal.attainment_rate)"
                                        :color="attainmentProgressColor(goal.attainment_rate, goal.achieved)"
                                        bg-color="grey-lighten-3"
                                        height="8"
                                        rounded
                                    />
                                </div>

                                <!-- Actual vs target values -->
                                <div class="d-flex justify-space-between mb-4">
                                    <div class="text-center">
                                        <p class="text-caption text-grey-darken-1 mb-0">Réalisé</p>
                                        <p class="text-body-2 font-weight-bold">
                                            {{ goal.formatted_actual }}
                                        </p>
                                    </div>
                                    <v-divider vertical class="mx-2" />
                                    <div class="text-center">
                                        <p class="text-caption text-grey-darken-1 mb-0">
                                            {{ goal.lower_is_better ? 'Plafond' : 'Objectif' }}
                                        </p>
                                        <p class="text-body-2 font-weight-bold text-grey-darken-2">
                                            {{ goal.formatted_target }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Period + actions -->
                                <div class="d-flex align-center justify-space-between">
                                    <div class="d-flex align-center gap-1 text-caption text-grey">
                                        <v-icon size="13">mdi-calendar-range</v-icon>
                                        {{ formatPeriod(goal.period_start, goal.period_end) }}
                                    </div>
                                    <v-btn
                                        icon="mdi-delete-outline"
                                        size="x-small"
                                        variant="text"
                                        color="error"
                                        @click.prevent="confirmDelete(goal)"
                                    />
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </div>
        </div>

        <!-- ── Create Goal Dialog ──────────────────────────────────────────────── -->
        <v-dialog v-model="createDialogVisible" max-width="520">
            <v-card rounded="lg">
                <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
                    <v-icon color="primary">mdi-target</v-icon>
                    Nouvel objectif
                </v-card-title>
                <v-card-subtitle class="px-5 pb-3 text-grey-darken-1">
                    Définissez une cible mesurable sur une période donnée.
                </v-card-subtitle>

                <v-divider />

                <v-card-text class="pa-5">
                    <v-form @submit.prevent="submitCreateGoal">
                        <v-select
                            v-model="createForm.assignee_type"
                            :items="[
                                { title: 'Commercial', value: 'commercial' },
                                { title: 'Équipe', value: 'team' },
                                { title: 'Entreprise', value: 'company' },
                            ]"
                            item-title="title"
                            item-value="value"
                            label="Assigné à"
                            variant="outlined"
                            density="comfortable"
                            class="mb-3"
                            :error-messages="createForm.errors.assignee_type"
                        />

                        <v-select
                            v-if="createForm.assignee_type === 'commercial'"
                            v-model="createForm.assignee_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            variant="outlined"
                            density="comfortable"
                            class="mb-3"
                            :error-messages="createForm.errors.assignee_id"
                        />

                        <v-autocomplete
                            v-model="createForm.metric"
                            :items="availableMetrics"
                            item-title="label"
                            item-value="value"
                            label="Métrique"
                            variant="outlined"
                            density="comfortable"
                            class="mb-1"
                            :error-messages="createForm.errors.metric"
                        />

                        <p v-if="selectedMetricMeta" class="text-caption text-grey-darken-1 mb-3 px-1">
                            {{ selectedMetricMeta.description }}
                            <v-chip
                                v-if="selectedMetricMeta.lower_is_better"
                                size="x-small"
                                color="purple"
                                variant="tonal"
                                class="ml-1"
                            >
                                Plus bas = mieux
                            </v-chip>
                        </p>

                        <v-text-field
                            v-model.number="createForm.target_value"
                            label="Valeur cible"
                            type="number"
                            min="0"
                            step="any"
                            variant="outlined"
                            density="comfortable"
                            class="mb-3"
                            :error-messages="createForm.errors.target_value"
                            :hint="selectedMetricMeta?.lower_is_better ? 'La valeur cible est un plafond à ne pas dépasser.' : ''"
                            persistent-hint
                        />

                        <v-row dense class="mb-2">
                            <v-col cols="6">
                                <v-text-field
                                    v-model="createForm.period_start"
                                    label="Début de période"
                                    type="date"
                                    variant="outlined"
                                    density="comfortable"
                                    :error-messages="createForm.errors.period_start"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field
                                    v-model="createForm.period_end"
                                    label="Fin de période"
                                    type="date"
                                    variant="outlined"
                                    density="comfortable"
                                    :error-messages="createForm.errors.period_end"
                                />
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="createDialogVisible = false">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="createForm.processing"
                        @click="submitCreateGoal"
                    >
                        Créer l'objectif
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Delete Confirmation Dialog ─────────────────────────────────────── -->
        <v-dialog v-model="deleteDialogVisible" max-width="420">
            <v-card rounded="lg">
                <v-card-title class="pa-5 d-flex align-center gap-2">
                    <v-icon color="error">mdi-delete-alert</v-icon>
                    Supprimer l'objectif
                </v-card-title>
                <v-card-text class="px-5 pb-4 text-body-2">
                    <span v-if="goalToDelete">
                        Voulez-vous supprimer l'objectif
                        <strong>{{ goalToDelete.metric_label }}</strong>
                        pour <strong>{{ goalToDelete.assignee_name }}</strong> ?
                        Cette action est irréversible.
                    </span>
                </v-card-text>
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="deleteDialogVisible = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="isDeleting"
                        @click="deleteGoal"
                    >
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.goal-card {
    position: relative;
    overflow: hidden;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.goal-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12) !important;
}

.goal-card--achieved {
    background: linear-gradient(135deg, rgba(76, 175, 80, 0.04) 0%, transparent 60%);
}

.goal-card__accent {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}
</style>
