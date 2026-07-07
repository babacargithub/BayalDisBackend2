<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    parentGoal: Object,
    childGoals: Array,
    commerciaux: Array,
    availableMetrics: Array,
    linkableGoals: Array,
});

// ── Add sub-goal dialog ───────────────────────────────────────────────────────

const addSubGoalDialogVisible = ref(false);
const addSubGoalTab = ref('new'); // 'new' | 'existing'

// Tab: create new sub-goal
const subGoalForm = useForm({
    assignee_type: 'commercial',
    assignee_id: null,
    metric: props.parentGoal.metric,
    target_value: null,
    period_start: props.parentGoal.period_start,
    period_end: props.parentGoal.period_end,
    parent_goal_id: props.parentGoal.goal_id,
});

const selectedMetricMeta = computed(() =>
    props.availableMetrics.find((m) => m.value === subGoalForm.metric) ?? null,
);

const submitSubGoal = () => {
    subGoalForm.post(route('goals.store'), {
        onSuccess: () => {
            addSubGoalDialogVisible.value = false;
            subGoalForm.reset('target_value', 'assignee_id');
            subGoalForm.metric = props.parentGoal.metric;
            subGoalForm.period_start = props.parentGoal.period_start;
            subGoalForm.period_end = props.parentGoal.period_end;
            subGoalForm.parent_goal_id = props.parentGoal.goal_id;
        },
    });
};

// Tab: link existing goal
const linkForm = useForm({ child_goal_id: null });

const selectedLinkableGoal = computed(() =>
    props.linkableGoals.find((g) => g.id === linkForm.child_goal_id) ?? null,
);

const submitLinkExisting = () => {
    linkForm.post(route('goals.attach-child', props.parentGoal.goal_id), {
        onSuccess: () => {
            addSubGoalDialogVisible.value = false;
            linkForm.reset();
        },
    });
};

const openAddDialog = () => {
    addSubGoalTab.value = 'new';
    addSubGoalDialogVisible.value = true;
};

// ── Detach sub-goal (unlink only — does not delete the goal) ─────────────────

const goalToDetach = ref(null);
const detachDialogVisible = ref(false);
const isDetaching = ref(false);

const confirmDetach = (goal) => {
    goalToDetach.value = goal;
    detachDialogVisible.value = true;
};

const detachGoal = () => {
    isDetaching.value = true;
    router.post(route('goals.detach', goalToDetach.value.goal_id), {}, {
        onSuccess: () => {
            detachDialogVisible.value = false;
            goalToDetach.value = null;
        },
        onFinish: () => {
            isDetaching.value = false;
        },
    });
};

// ── Summary stats ─────────────────────────────────────────────────────────────

const achievedChildCount = computed(() => props.childGoals.filter((g) => g.achieved).length);
const childTotalCount = computed(() => props.childGoals.length);
const childOverallRate = computed(() =>
    childTotalCount.value > 0
        ? Math.round((achievedChildCount.value / childTotalCount.value) * 100)
        : 0,
);

// ── Helpers ───────────────────────────────────────────────────────────────────

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
    if (salesMetrics.includes(metric)) { return 'indigo'; }
    if (coverageMetrics.includes(metric)) { return 'teal'; }
    return 'purple';
};

const statusLabel = (goal) => {
    if (goal.achieved) { return { label: 'Atteint', color: 'success', icon: 'mdi-check-circle' }; }
    if (isPastPeriod(goal.period_end)) { return { label: 'Non atteint', color: 'error', icon: 'mdi-close-circle' }; }
    if (isCurrentPeriod(goal.period_start, goal.period_end)) { return { label: 'En cours', color: 'blue', icon: 'mdi-clock-outline' }; }
    return { label: 'À venir', color: 'grey', icon: 'mdi-calendar-future' };
};

const parentStatus = computed(() => statusLabel(props.parentGoal));
</script>

<template>
    <Head :title="`Objectif — ${parentGoal.metric_label}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex align-center gap-3">
                <Link :href="route('goals.index')" class="text-decoration-none">
                    <v-btn icon="mdi-arrow-left" variant="text" size="small" />
                </Link>
                <div>
                    <h2 class="font-semibold text-xl leading-tight">{{ parentGoal.metric_label }}</h2>
                    <p class="text-caption text-grey-darken-1 mt-0">{{ parentGoal.assignee_name }}</p>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- ── Parent goal hero card ──────────────────────────────────── -->
                <v-card rounded="xl" elevation="3" class="mb-8 overflow-hidden">
                    <!-- Gradient header band -->
                    <div
                        class="parent-hero-band d-flex align-center pa-6 pb-8"
                        :style="`background: linear-gradient(135deg, ${attainmentProgressColor(parentGoal.attainment_rate, parentGoal.achieved)} 0%, #1a237e 100%)`"
                    >
                        <v-avatar
                            color="white"
                            size="56"
                            class="mr-4"
                        >
                            <v-icon
                                size="28"
                                :color="metricCategoryColor(parentGoal.metric)"
                            >
                                {{ metricCategoryIcon(parentGoal.metric) }}
                            </v-icon>
                        </v-avatar>
                        <div class="flex-grow-1">
                            <p class="text-h5 font-weight-bold text-white mb-0">
                                {{ parentGoal.metric_label }}
                            </p>
                            <p class="text-body-2 text-white-70 mt-1">
                                {{ parentGoal.metric_description }}
                            </p>
                        </div>
                        <v-chip
                            :color="parentStatus.color"
                            size="default"
                            :prepend-icon="parentStatus.icon"
                            variant="flat"
                            class="font-weight-bold"
                        >
                            {{ parentStatus.label }}
                        </v-chip>
                    </div>

                    <!-- Stats row -->
                    <v-card-text class="pa-0">
                        <v-row no-gutters>
                            <!-- Attainment rate -->
                            <v-col cols="12" sm="4" class="pa-6 text-center border-r">
                                <p class="text-caption text-grey-darken-1 mb-1 text-uppercase tracking-wide">
                                    Progression
                                </p>
                                <p
                                    class="text-h3 font-weight-black mb-0"
                                    :style="`color: ${attainmentProgressColor(parentGoal.attainment_rate, parentGoal.achieved)}`"
                                >
                                    {{ parentGoal.attainment_rate }}%
                                </p>
                                <v-progress-linear
                                    :model-value="clampedRate(parentGoal.attainment_rate)"
                                    :color="attainmentProgressColor(parentGoal.attainment_rate, parentGoal.achieved)"
                                    bg-color="grey-lighten-3"
                                    height="6"
                                    rounded
                                    class="mt-2 mx-auto"
                                    style="max-width: 160px"
                                />
                            </v-col>

                            <!-- Actual vs target -->
                            <v-col cols="12" sm="4" class="pa-6 text-center border-r">
                                <p class="text-caption text-grey-darken-1 mb-1 text-uppercase tracking-wide">
                                    Réalisé
                                </p>
                                <p class="text-h5 font-weight-bold mb-1">
                                    {{ parentGoal.formatted_actual }}
                                </p>
                                <p class="text-caption text-grey">
                                    sur {{ parentGoal.formatted_target }}
                                    {{ parentGoal.lower_is_better ? '(plafond)' : '(objectif)' }}
                                </p>
                            </v-col>

                            <!-- Period -->
                            <v-col cols="12" sm="4" class="pa-6 text-center">
                                <p class="text-caption text-grey-darken-1 mb-1 text-uppercase tracking-wide">
                                    Période
                                </p>
                                <p class="text-body-1 font-weight-bold mb-1">
                                    {{ formatPeriod(parentGoal.period_start, parentGoal.period_end) }}
                                </p>
                                <v-chip
                                    v-if="isCurrentPeriod(parentGoal.period_start, parentGoal.period_end)"
                                    color="blue"
                                    size="x-small"
                                    variant="tonal"
                                >
                                    Période active
                                </v-chip>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <!-- ── Sub-goals section ──────────────────────────────────────── -->
                <div class="d-flex align-center justify-space-between mb-4">
                    <div>
                        <h3 class="text-h6 font-weight-bold">
                            Sous-objectifs
                            <v-chip
                                v-if="childGoals.length > 0"
                                size="x-small"
                                color="indigo"
                                variant="tonal"
                                class="ml-2"
                            >
                                {{ childGoals.length }}
                            </v-chip>
                        </h3>
                        <p v-if="childGoals.length > 0" class="text-body-2 text-grey-darken-1 mt-0">
                            <v-icon color="success" size="14">mdi-check-circle</v-icon>
                            {{ achievedChildCount }}/{{ childTotalCount }} atteints —
                            <strong>{{ childOverallRate }}%</strong> de réussite
                        </p>
                    </div>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-plus"
                        @click="openAddDialog"
                    >
                        Ajouter un sous-objectif
                    </v-btn>
                </div>

                <!-- Empty sub-goals state -->
                <v-card
                    v-if="childGoals.length === 0"
                    rounded="lg"
                    elevation="0"
                    class="text-center py-12 border-dashed"
                    style="border: 2px dashed #e0e0e0"
                >
                    <v-icon size="48" color="grey-lighten-1">mdi-sitemap</v-icon>
                    <p class="text-body-1 text-grey mt-3">Aucun sous-objectif défini</p>
                    <p class="text-body-2 text-grey-darken-1">
                        Déclinez cet objectif en cibles individuelles par commercial.
                    </p>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        class="mt-4"
                        prepend-icon="mdi-plus"
                        @click="openAddDialog"
                    >
                        Créer un premier sous-objectif
                    </v-btn>
                </v-card>

                <!-- Sub-goals grid -->
                <v-row v-else>
                    <v-col
                        v-for="child in childGoals"
                        :key="child.goal_id"
                        cols="12"
                        sm="6"
                        lg="4"
                    >
                        <v-card elevation="2" rounded="lg" class="child-goal-card h-100">
                            <!-- Accent bar -->
                            <div
                                class="child-goal-card__accent"
                                :style="`background: ${attainmentProgressColor(child.attainment_rate, child.achieved)}`"
                            />

                            <v-card-text class="pa-4">
                                <!-- Assignee + status -->
                                <div class="d-flex align-center justify-space-between mb-3">
                                    <div class="d-flex align-center gap-2">
                                        <v-avatar color="indigo" size="32" variant="tonal">
                                            <v-icon size="16">mdi-account-tie</v-icon>
                                        </v-avatar>
                                        <div>
                                            <p class="text-body-2 font-weight-semibold mb-0">
                                                {{ child.assignee_name }}
                                            </p>
                                            <p class="text-caption text-grey-darken-1 mb-0">
                                                {{ child.metric_label }}
                                            </p>
                                        </div>
                                    </div>
                                    <v-chip
                                        :color="statusLabel(child).color"
                                        size="x-small"
                                        :prepend-icon="statusLabel(child).icon"
                                        variant="tonal"
                                    >
                                        {{ statusLabel(child).label }}
                                    </v-chip>
                                </div>

                                <!-- Progress bar -->
                                <div class="mb-3">
                                    <div class="d-flex justify-space-between align-center mb-1">
                                        <span class="text-caption text-grey">Progression</span>
                                        <span
                                            class="text-caption font-weight-bold"
                                            :style="`color: ${attainmentProgressColor(child.attainment_rate, child.achieved)}`"
                                        >
                                            {{ child.attainment_rate }}%
                                        </span>
                                    </div>
                                    <v-progress-linear
                                        :model-value="clampedRate(child.attainment_rate)"
                                        :color="attainmentProgressColor(child.attainment_rate, child.achieved)"
                                        bg-color="grey-lighten-3"
                                        height="6"
                                        rounded
                                    />
                                </div>

                                <!-- Values -->
                                <div class="d-flex justify-space-between align-center">
                                    <div>
                                        <p class="text-caption text-grey mb-0">Réalisé</p>
                                        <p class="text-body-2 font-weight-bold mb-0">
                                            {{ child.formatted_actual }}
                                        </p>
                                    </div>
                                    <v-icon color="grey-lighten-1" size="16">mdi-arrow-right</v-icon>
                                    <div class="text-right">
                                        <p class="text-caption text-grey mb-0">
                                            {{ child.lower_is_better ? 'Plafond' : 'Objectif' }}
                                        </p>
                                        <p class="text-body-2 font-weight-bold text-grey-darken-2 mb-0">
                                            {{ child.formatted_target }}
                                        </p>
                                    </div>
                                    <v-btn
                                        icon="mdi-link-off"
                                        size="x-small"
                                        variant="text"
                                        color="warning"
                                        title="Délier ce sous-objectif"
                                        @click="confirmDetach(child)"
                                    />
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </div>
        </div>

        <!-- ── Add sub-goal dialog (tabbed) ─────────────────────────────────── -->
        <v-dialog v-model="addSubGoalDialogVisible" max-width="540">
            <v-card rounded="lg">
                <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
                    <v-icon color="indigo">mdi-sitemap</v-icon>
                    Ajouter un sous-objectif
                </v-card-title>
                <v-card-subtitle class="px-5 pb-0 text-grey-darken-1">
                    Déclinaison de « {{ parentGoal.metric_label }} » — {{ parentGoal.assignee_name }}
                </v-card-subtitle>

                <!-- Tab switcher -->
                <v-tabs v-model="addSubGoalTab" color="primary" class="px-4 mt-2">
                    <v-tab value="new" prepend-icon="mdi-plus-circle-outline">
                        Créer un nouveau
                    </v-tab>
                    <v-tab value="existing" prepend-icon="mdi-link-variant">
                        Lier un existant
                        <v-chip
                            v-if="linkableGoals.length > 0"
                            size="x-small"
                            color="indigo"
                            variant="tonal"
                            class="ml-2"
                        >
                            {{ linkableGoals.length }}
                        </v-chip>
                    </v-tab>
                </v-tabs>

                <v-divider />

                <v-tabs-window v-model="addSubGoalTab">

                    <!-- ── Tab: Create new ── -->
                    <v-tabs-window-item value="new">
                        <v-card-text class="pa-5">
                            <v-form @submit.prevent="submitSubGoal">
                                <v-select
                                    v-model="subGoalForm.assignee_id"
                                    :items="commerciaux"
                                    item-title="name"
                                    item-value="id"
                                    label="Commercial"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-3"
                                    :error-messages="subGoalForm.errors.assignee_id"
                                />

                                <v-autocomplete
                                    v-model="subGoalForm.metric"
                                    :items="availableMetrics"
                                    item-title="label"
                                    item-value="value"
                                    label="Métrique"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-1"
                                    :error-messages="subGoalForm.errors.metric"
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
                                    v-model.number="subGoalForm.target_value"
                                    label="Valeur cible"
                                    type="number"
                                    min="0"
                                    step="any"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-3"
                                    :error-messages="subGoalForm.errors.target_value"
                                />

                                <v-row dense>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model="subGoalForm.period_start"
                                            label="Début"
                                            type="date"
                                            variant="outlined"
                                            density="comfortable"
                                            :error-messages="subGoalForm.errors.period_start"
                                        />
                                    </v-col>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model="subGoalForm.period_end"
                                            label="Fin"
                                            type="date"
                                            variant="outlined"
                                            density="comfortable"
                                            :error-messages="subGoalForm.errors.period_end"
                                        />
                                    </v-col>
                                </v-row>
                            </v-form>
                        </v-card-text>

                        <v-divider />

                        <v-card-actions class="pa-4">
                            <v-spacer />
                            <v-btn variant="text" @click="addSubGoalDialogVisible = false">Annuler</v-btn>
                            <v-btn
                                color="primary"
                                variant="flat"
                                :loading="subGoalForm.processing"
                                @click="submitSubGoal"
                            >
                                Créer le sous-objectif
                            </v-btn>
                        </v-card-actions>
                    </v-tabs-window-item>

                    <!-- ── Tab: Link existing ── -->
                    <v-tabs-window-item value="existing">
                        <v-card-text class="pa-5">

                            <!-- Empty state when nothing to link -->
                            <div v-if="linkableGoals.length === 0" class="text-center py-6">
                                <v-icon size="40" color="grey-lighten-1">mdi-link-off</v-icon>
                                <p class="text-body-2 text-grey mt-2">
                                    Aucun objectif disponible à lier.
                                </p>
                                <p class="text-caption text-grey-darken-1">
                                    Seuls les objectifs sans parent peuvent être liés.
                                </p>
                            </div>

                            <template v-else>
                                <v-autocomplete
                                    v-model="linkForm.child_goal_id"
                                    :items="linkableGoals"
                                    item-title="display_title"
                                    item-value="id"
                                    label="Sélectionner un objectif existant"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-3"
                                    clearable
                                    :error-messages="linkForm.errors.child_goal_id"
                                >
                                    <template #item="{ props: itemProps, item }">
                                        <v-list-item v-bind="itemProps" :subtitle="item.raw.display_subtitle">
                                            <template #prepend>
                                                <v-avatar color="indigo" size="32" variant="tonal" class="mr-2">
                                                    <v-icon size="16">mdi-target</v-icon>
                                                </v-avatar>
                                            </template>
                                        </v-list-item>
                                    </template>
                                </v-autocomplete>

                                <!-- Preview of selected goal -->
                                <v-card
                                    v-if="selectedLinkableGoal"
                                    variant="tonal"
                                    color="indigo"
                                    rounded="lg"
                                    class="pa-3"
                                >
                                    <div class="d-flex align-center gap-2">
                                        <v-icon size="18" color="indigo">mdi-information-outline</v-icon>
                                        <div>
                                            <p class="text-body-2 font-weight-bold mb-0">
                                                {{ selectedLinkableGoal.label }}
                                            </p>
                                            <p class="text-caption mb-0">
                                                {{ selectedLinkableGoal.assignee_name }} ·
                                                {{ selectedLinkableGoal.display_subtitle }}
                                            </p>
                                        </div>
                                    </div>
                                </v-card>
                            </template>
                        </v-card-text>

                        <v-divider />

                        <v-card-actions class="pa-4">
                            <v-spacer />
                            <v-btn variant="text" @click="addSubGoalDialogVisible = false">Annuler</v-btn>
                            <v-btn
                                color="primary"
                                variant="flat"
                                :loading="linkForm.processing"
                                :disabled="!linkForm.child_goal_id"
                                @click="submitLinkExisting"
                            >
                                Lier comme sous-objectif
                            </v-btn>
                        </v-card-actions>
                    </v-tabs-window-item>
                </v-tabs-window>
            </v-card>
        </v-dialog>

        <!-- ── Detach Confirmation Dialog ─────────────────────────────────────── -->
        <v-dialog v-model="detachDialogVisible" max-width="420">
            <v-card rounded="lg">
                <v-card-title class="pa-5 d-flex align-center gap-2">
                    <v-icon color="warning">mdi-link-off</v-icon>
                    Délier le sous-objectif
                </v-card-title>
                <v-card-text class="px-5 pb-4 text-body-2">
                    <span v-if="goalToDetach">
                        Voulez-vous délier l'objectif
                        <strong>{{ goalToDetach.metric_label }}</strong>
                        de <strong>{{ goalToDetach.assignee_name }}</strong> ?
                    </span>
                    <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                        L'objectif ne sera pas supprimé — il redeviendra simplement un objectif indépendant.
                    </v-alert>
                </v-card-text>
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="detachDialogVisible = false">Annuler</v-btn>
                    <v-btn
                        color="warning"
                        variant="flat"
                        :loading="isDetaching"
                        @click="detachGoal"
                    >
                        Délier
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.text-white-70 {
    color: rgba(255, 255, 255, 0.75);
}

.border-r {
    border-right: 1px solid rgba(0, 0, 0, 0.08);
}

.child-goal-card {
    position: relative;
    overflow: hidden;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.child-goal-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1) !important;
}

.child-goal-card__accent {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.parent-hero-band {
    min-height: 120px;
}
</style>
