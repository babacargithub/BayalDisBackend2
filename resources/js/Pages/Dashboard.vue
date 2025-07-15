<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const tab = ref('daily');
const menu = ref(false);
const datePickerKey = ref(0);

const props = defineProps({
    dailyStats: {
        type: Object,
        default: () => ({
            total_customers: 0,
            total_prospects: 0,
            total_confirmed_customers: 0,
            total_ventes: 0,
            total_ventes_paid: 0,
            total_ventes_unpaid: 0,
            total_amount_gross: 0,
            total_amount_paid: 0,
            total_amount_unpaid: 0,
            total_profit: 0,
            total_net_profit: 0,
            total_payments: 0
        })
    },
    weeklyStats: {
        type: Object,
        default: () => ({
            total_customers: 0,
            total_prospects: 0,
            total_confirmed_customers: 0,
            total_ventes: 0,
            total_ventes_paid: 0,
            total_ventes_unpaid: 0,
            total_amount_gross: 0,
            total_amount_paid: 0,
            total_amount_unpaid: 0,
            total_profit: 0,
            total_net_profit: 0,
            total_payments: 0
        })
    },
    monthlyStats: {
        type: Object,
        default: () => ({
            total_customers: 0,
            total_prospects: 0,
            total_confirmed_customers: 0,
            total_ventes: 0,
            total_ventes_paid: 0,
            total_ventes_unpaid: 0,
            total_amount_gross: 0,
            total_amount_paid: 0,
            total_amount_unpaid: 0,
            total_profit: 0,
            total_payments: 0,
            total_net_profit: 0,
        })
    },
    overallStats: {
        type: Object,
        default: () => ({
            total_customers: 0,
            total_prospects: 0,
            total_confirmed_customers: 0,
            total_ventes: 0,
            total_ventes_paid: 0,
            total_ventes_unpaid: 0,
            total_amount_gross: 0,
            total_amount_paid: 0,
            total_amount_unpaid: 0,
            total_profit: 0,
            total_commerciaux: 0,
            total_payments: 0,
            total_net_profit: 0
        })
    },
    selectedDate: {
        type: String,
        required: true
    }
});

const date = ref(props.selectedDate);

const formattedDate = computed(() => {
    try {
        return new Date(date.value).toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    } catch (e) {
        return new Date().toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
});

const today = computed(() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
});

const handleDateChange = (newDate) => {
    if (newDate) {
        const formattedNewDate = new Date(newDate).toISOString().split('T')[0];
        date.value = formattedNewDate;
        menu.value = false;
        router.get(route('dashboard'), { date: formattedNewDate }, {
            preserveState: true,
            preserveScroll: true,
            only: ['dailyStats', 'weeklyStats', 'monthlyStats', 'selectedDate']
        });
    }
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(amount);
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    📊 Tableau de bord
                </h2>
                <div class="w-full sm:w-auto">
                    <v-menu
                        v-model="menu"
                        :close-on-content-click="false"
                        min-width="auto"
                        transition="scale-transition"
                    >
                        <template v-slot:activator="{ props }">
                            <v-btn
                                color="primary"
                                v-bind="props"
                                prepend-icon="mdi-calendar"
                                variant="elevated"
                                class="w-full sm:w-auto"
                                size="large"
                            >
                                {{ formattedDate }}
                            </v-btn>
                        </template>

                        <v-card elevation="8" class="rounded-lg">
                            <v-card-text class="pa-2">
                                <v-date-picker
                                    :key="datePickerKey"
                                    :max="today"
                                    :first-day-of-week="1"
                                    locale="fr"
                                    @update:model-value="handleDateChange"
                                    color="primary"
                                    elevation="0"
                                />
                            </v-card-text>
                        </v-card>
                    </v-menu>
                </div>
            </div>
        </template>

        <div class="py-6 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto space-y-8">
                <!-- Overall Stats Summary -->
                <div class="mb-8">
                    <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                        <v-icon class="mr-2" color="primary">mdi-chart-line</v-icon>
                        Vue d'ensemble
                    </h3>
                    <v-row class="ma-0">
                        <v-col cols="12" sm="6" md="4" lg="2" class="pa-2">
                            <v-card
                                class="stat-card h-100"
                                elevation="3"
                                :ripple="false"
                            >
                                <v-card-text class="text-center pa-4">
                                    <div class="stat-icon mb-2">
                                        <v-icon size="32" color="primary">mdi-account-group</v-icon>
                                    </div>
                                    <div class="text-overline text-gray-600 mb-1">
                                        TOTAL CLIENTS
                                    </div>
                                    <div class="text-h4 font-weight-bold text-gray-900 mb-3">
                                        {{ overallStats.total_customers }}
                                    </div>
                                    <div class="d-flex justify-center gap-4">
                                        <div class="text-center">
                                            <div class="text-sm font-medium text-green-600">
                                                {{ overallStats.total_confirmed_customers }}
                                            </div>
                                            <div class="text-xs text-gray-500">Confirmés</div>
                                        </div>
                                        <div class="text-center">
                                            <div class="text-sm font-medium text-amber-600">
                                                {{ overallStats.total_prospects }}
                                            </div>
                                            <div class="text-xs text-gray-500">Prospects</div>
                                        </div>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" md="4" lg="2" class="pa-2">
                            <v-card
                                class="stat-card h-100"
                                elevation="3"
                                :ripple="false"
                            >
                                <v-card-text class="text-center pa-4">
                                    <div class="stat-icon mb-2">
                                        <v-icon size="32" color="indigo">mdi-cart</v-icon>
                                    </div>
                                    <div class="text-overline text-gray-600 mb-1">
                                        TOTAL VENTES
                                    </div>
                                    <div class="text-h4 font-weight-bold text-gray-900 mb-3">
                                        {{ overallStats.ventes_count }}
                                    </div>
                                    <div class="d-flex justify-center gap-4">
                                        <div class="text-center">
                                            <div class="text-sm font-medium text-green-600">
                                                {{ overallStats.total_ventes_paid }}
                                            </div>
                                            <div class="text-xs text-gray-500">Payées</div>
                                        </div>
                                        <div class="text-center">
                                            <div class="text-sm font-medium text-red-600">
                                                {{ overallStats.total_ventes_unpaid }}
                                            </div>
                                            <div class="text-xs text-gray-500">Impayées</div>
                                        </div>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" md="4" lg="2" class="pa-2">
                            <v-card
                                class="stat-card h-100"
                                elevation="3"
                                :ripple="false"
                            >
                                <v-card-text class="text-center pa-4">
                                    <div class="stat-icon mb-2">
                                        <v-icon size="32" color="success">mdi-trending-up</v-icon>
                                    </div>
                                    <div class="text-overline text-gray-600 mb-1">
                                        TOTAL BÉNÉFICES
                                    </div>
                                    <div class="text-h4 font-weight-bold text-green-600 mb-3">
                                        {{ formatCurrency(overallStats.total_profit) }}
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" md="4" lg="2" class="pa-2">
                            <v-card
                                class="stat-card h-100"
                                elevation="3"
                                :ripple="false"
                            >
                                <v-card-text class="text-center pa-4">
                                    <div class="stat-icon mb-2">
                                        <v-icon size="32" color="purple">mdi-account-tie</v-icon>
                                    </div>
                                    <div class="text-overline text-gray-600 mb-1">
                                        Total Ventes
                                    </div>
                                    <div class="text-h4 font-weight-bold text-gray-900 mb-3">
                                        {{ overallStats.total_ventes?.toLocaleString() }}
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>

                        <v-col cols="12" sm="6" md="4" lg="2" class="pa-2">
                            <v-card
                                class="stat-card h-100"
                                elevation="3"
                                :ripple="false"
                            >
                                <v-card-text class="text-center pa-4">
                                    <div class="stat-icon mb-2">
                                        <v-icon size="32" color="warning">mdi-cash-multiple</v-icon>
                                    </div>
                                    <div class="text-overline text-gray-600 mb-1">
                                        ENCAISSEMENTS
                                    </div>
                                    <div class="text-h4 font-weight-bold text-amber-600 mb-3">
                                        {{ formatCurrency(overallStats.total_payments) }}
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </div>

                <!-- Period Stats -->
                <div>
                    <v-tabs
                        v-model="tab"
                        bg-color="transparent"
                        color="primary"
                        class="mb-4"
                        slider-color="primary"
                        grow
                    >
                        <v-tab
                            value="daily"
                            class="text-none font-weight-medium"
                            prepend-icon="mdi-calendar-today"
                        >
                            Aujourd'hui
                        </v-tab>
                        <v-tab
                            value="weekly"
                            class="text-none font-weight-medium"
                            prepend-icon="mdi-calendar-week"
                        >
                            Cette Semaine
                        </v-tab>
                        <v-tab
                            value="monthly"
                            class="text-none font-weight-medium"
                            prepend-icon="mdi-calendar-month"
                        >
                            Ce Mois
                        </v-tab>
                    </v-tabs>

                    <v-window v-model="tab" class="mt-4">
                        <v-window-item value="daily">
                            <v-card elevation="4" class="rounded-lg">
                                <v-card-title class="bg-gradient-to-r from-blue-50 to-indigo-50 text-lg font-medium">
                                    <v-icon class="mr-2" color="primary">mdi-calendar-today</v-icon>
                                    Statistiques du jour
                                </v-card-title>
                                <v-card-text class="pa-6">
                                    <v-row>
                                        <!-- Clients Card -->
                                        <v-col cols="12" lg="6" class="mb-4">
                                            <v-card variant="outlined" class="h-100 detail-card">
                                                <v-card-title class="text-subtitle-1 bg-gray-50">
                                                    <v-icon start color="primary">mdi-account-group</v-icon>
                                                    Clients
                                                </v-card-title>
                                                <v-card-text class="pa-4">
                                                    <v-row class="text-center">
                                                        <v-col cols="4">
                                                            <div class="metric-value">{{ dailyStats.total_customers }}</div>
                                                            <div class="metric-label">Total</div>
                                                        </v-col>
                                                        <v-col cols="4">
                                                            <div class="metric-value text-amber-600">{{ dailyStats.total_prospects }}</div>
                                                            <div class="metric-label">Prospects</div>
                                                        </v-col>
                                                        <v-col cols="4">
                                                            <div class="metric-value text-green-600">{{ dailyStats.total_confirmed_customers }}</div>
                                                            <div class="metric-label">Confirmés</div>
                                                        </v-col>
                                                    </v-row>
                                                </v-card-text>
                                            </v-card>
                                        </v-col>

                                        <!-- Ventes Card -->
                                        <v-col cols="12" lg="6" class="mb-4">
                                            <v-card variant="outlined" class="h-100 detail-card">
                                                <v-card-title class="text-subtitle-1 bg-gray-50">
                                                    <v-icon start color="indigo">mdi-cash-register</v-icon>
                                                    Ventes
                                                </v-card-title>
                                                <v-card-text class="pa-4">
                                                    <v-row class="text-center">
                                                        <v-col cols="6" sm="4" md="6" lg="4" xl="3">
                                                            <div class="metric-value">{{ dailyStats.total_ventes }}</div>
                                                            <div class="metric-label">Total</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="4" xl="3">
                                                            <div class="metric-value text-primary text-sm">
                                                                {{ formatCurrency(dailyStats.total_amount_gross) }}
                                                            </div>
                                                            <div class="metric-label">Brut</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="4" xl="3">
                                                            <div class="metric-value text-green-600 text-sm">
                                                                {{ formatCurrency(dailyStats.total_amount_paid) }}
                                                            </div>
                                                            <div class="metric-label">Payé</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="4" xl="3">
                                                            <div class="metric-value text-red-600 text-sm">
                                                                {{ formatCurrency(dailyStats.total_amount_unpaid) }}
                                                            </div>
                                                            <div class="metric-label">Impayé</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="4" xl="3">
                                                            <div class="metric-value text-amber-600 text-sm">
                                                                {{ formatCurrency(dailyStats.total_payments) }}
                                                            </div>
                                                            <div class="metric-label">Encaissements</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="4" xl="3">
                                                            <div class="metric-value text-green-600 text-sm">
                                                                {{ formatCurrency(dailyStats.total_profit) }}
                                                            </div>
                                                            <div class="metric-label">Bénéfice</div>
                                                        </v-col>
                                                        <v-col cols="12" sm="4" md="12" lg="4" xl="6">
                                                            <div class="metric-value text-green-700 text-sm">
                                                                {{ formatCurrency(dailyStats.total_net_profit) }}
                                                            </div>
                                                            <div class="metric-label">Bénéfice net</div>
                                                        </v-col>
                                                    </v-row>
                                                </v-card-text>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                            </v-card>
                        </v-window-item>

                        <v-window-item value="weekly">
                            <v-card elevation="4" class="rounded-lg">
                                <v-card-title class="bg-gradient-to-r from-green-50 to-emerald-50 text-lg font-medium">
                                    <v-icon class="mr-2" color="success">mdi-calendar-week</v-icon>
                                    Statistiques de la semaine
                                </v-card-title>
                                <v-card-text class="pa-6">
                                    <v-row>
                                        <!-- Clients Card -->
                                        <v-col cols="12" lg="6" class="mb-4">
                                            <v-card variant="outlined" class="h-100 detail-card">
                                                <v-card-title class="text-subtitle-1 bg-gray-50">
                                                    <v-icon start color="primary">mdi-account-group</v-icon>
                                                    Clients
                                                </v-card-title>
                                                <v-card-text class="pa-4">
                                                    <v-row class="text-center">
                                                        <v-col cols="4">
                                                            <div class="metric-value">{{ weeklyStats.total_customers }}</div>
                                                            <div class="metric-label">Total</div>
                                                        </v-col>
                                                        <v-col cols="4">
                                                            <div class="metric-value text-amber-600">{{ weeklyStats.total_prospects }}</div>
                                                            <div class="metric-label">Prospects</div>
                                                        </v-col>
                                                        <v-col cols="4">
                                                            <div class="metric-value text-green-600">{{ weeklyStats.total_confirmed_customers }}</div>
                                                            <div class="metric-label">Confirmés</div>
                                                        </v-col>
                                                    </v-row>
                                                </v-card-text>
                                            </v-card>
                                        </v-col>

                                        <!-- Ventes Card -->
                                        <v-col cols="12" lg="6" class="mb-4">
                                            <v-card variant="outlined" class="h-100 detail-card">
                                                <v-card-title class="text-subtitle-1 bg-gray-50">
                                                    <v-icon start color="indigo">mdi-cash-register</v-icon>
                                                    Ventes
                                                </v-card-title>
                                                <v-card-text class="pa-4">
                                                    <v-row class="text-center">
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value">{{ weeklyStats.total_ventes }}</div>
                                                            <div class="metric-label">Total</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-primary text-sm">
                                                                {{ formatCurrency(weeklyStats.total_amount_gross) }}
                                                            </div>
                                                            <div class="metric-label">Brut</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-green-600 text-sm">
                                                                {{ formatCurrency(weeklyStats.total_amount_paid) }}
                                                            </div>
                                                            <div class="metric-label">Payé</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-red-600 text-sm">
                                                                {{ formatCurrency(weeklyStats.total_amount_unpaid) }}
                                                            </div>
                                                            <div class="metric-label">Impayé</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-amber-600 text-sm">
                                                                {{ formatCurrency(weeklyStats.total_payments) }}
                                                            </div>
                                                            <div class="metric-label">Encaissements</div>
                                                        </v-col>
                                                        <v-col cols="12" sm="4" md="12" lg="12" xl="4">
                                                            <div class="metric-value text-green-600 text-sm">
                                                                {{ formatCurrency(weeklyStats.total_profit) }}
                                                            </div>
                                                            <div class="metric-label">Bénéfice</div>
                                                        </v-col>
                                                        <v-col cols="12" md="12" lg="12" xl="12">
                                                            <div class="metric-value text-green-700 text-sm">
                                                                {{ formatCurrency(weeklyStats.total_net_profit) }}
                                                            </div>
                                                            <div class="metric-label">Bénéfice net</div>
                                                        </v-col>
                                                    </v-row>
                                                </v-card-text>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                            </v-card>
                        </v-window-item>

                        <v-window-item value="monthly">
                            <v-card elevation="4" class="rounded-lg">
                                <v-card-title class="bg-gradient-to-r from-purple-50 to-pink-50 text-lg font-medium">
                                    <v-icon class="mr-2" color="purple">mdi-calendar-month</v-icon>
                                    Statistiques du mois
                                </v-card-title>
                                <v-card-text class="pa-6">
                                    <v-row>
                                        <!-- Clients Card -->
                                        <v-col cols="12" lg="6" class="mb-4">
                                            <v-card variant="outlined" class="h-100 detail-card">
                                                <v-card-title class="text-subtitle-1 bg-gray-50">
                                                    <v-icon start color="primary">mdi-account-group</v-icon>
                                                    Clients
                                                </v-card-title>
                                                <v-card-text class="pa-4">
                                                    <v-row class="text-center">
                                                        <v-col cols="4">
                                                            <div class="metric-value">{{ monthlyStats.total_customers }}</div>
                                                            <div class="metric-label">Total</div>
                                                        </v-col>
                                                        <v-col cols="4">
                                                            <div class="metric-value text-amber-600">{{ monthlyStats.total_prospects }}</div>
                                                            <div class="metric-label">Prospects</div>
                                                        </v-col>
                                                        <v-col cols="4">
                                                            <div class="metric-value text-green-600">{{ monthlyStats.total_confirmed_customers }}</div>
                                                            <div class="metric-label">Confirmés</div>
                                                        </v-col>
                                                    </v-row>
                                                </v-card-text>
                                            </v-card>
                                        </v-col>

                                        <!-- Ventes Card -->
                                        <v-col cols="12" lg="6" class="mb-4">
                                            <v-card variant="outlined" class="h-100 detail-card">
                                                <v-card-title class="text-subtitle-1 bg-gray-50">
                                                    <v-icon start color="indigo">mdi-cash-register</v-icon>
                                                    Ventes
                                                </v-card-title>
                                                <v-card-text class="pa-4">
                                                    <v-row class="text-center">
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value">{{ monthlyStats.total_ventes }}</div>
                                                            <div class="metric-label">Total</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-primary text-sm">
                                                                {{ formatCurrency(monthlyStats.total_amount_gross) }}
                                                            </div>
                                                            <div class="metric-label">Brut</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-green-600 text-sm">
                                                                {{ formatCurrency(monthlyStats.total_amount_paid) }}
                                                            </div>
                                                            <div class="metric-label">Payé</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-red-600 text-sm">
                                                                {{ formatCurrency(monthlyStats.total_amount_unpaid) }}
                                                            </div>
                                                            <div class="metric-label">Impayé</div>
                                                        </v-col>
                                                        <v-col cols="6" sm="4" md="6" lg="6" xl="4">
                                                            <div class="metric-value text-amber-600 text-sm">
                                                                {{ formatCurrency(monthlyStats.total_payments) }}
                                                            </div>
                                                            <div class="metric-label">Encaissements</div>
                                                        </v-col>
                                                        <v-col cols="12" sm="4" md="12" lg="12" xl="4">
                                                            <div class="metric-value text-green-600 text-sm">
                                                                {{ formatCurrency(monthlyStats.total_profit) }}
                                                            </div>
                                                            <div class="metric-label">Bénéfice</div>
                                                        </v-col>
                                                        <v-col cols="12" md="12" lg="12" xl="12">
                                                            <div class="metric-value text-green-700 text-sm">
                                                                {{ formatCurrency(monthlyStats.total_net_profit) }}
                                                            </div>
                                                            <div class="metric-label">Bénéfice net</div>
                                                        </v-col>
                                                    </v-row>
                                                </v-card-text>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                            </v-card>
                        </v-window-item>
                    </v-window>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* Enhanced styling for better visual appeal */
.font-medium{
    font-size: 12px;
}
.stat-card {
    transition: all 0.3s ease;
    border-radius: 12px !important;
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1) !important;
}

.stat-icon {
    padding: 8px;
    border-radius: 50%;
    background: rgba(79, 70, 229, 0.1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.detail-card {
    border-radius: 8px !important;
    transition: all 0.2s ease;
}

.detail-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.metric-value {
    font-size: 1.5rem !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    margin-bottom: 4px;
}

.metric-label {
    font-size: 0.75rem !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b7280 !important;
    font-weight: 500 !important;
}

/* Mobile-specific improvements */
@media (max-width: 768px) {
    .metric-value {
        font-size: 1.25rem !important;
    }

    .stat-card .v-card-text {
        padding: 16px !important;
    }

    .detail-card .v-card-text {
        padding: 16px !important;
    }
}

@media (max-width: 640px) {
    .metric-value {
        font-size: 1.1rem !important;
    }

    .metric-value.text-sm {
        font-size: 0.95rem !important;
    }

    .metric-label {
        font-size: 0.7rem !important;
    }
}

/* Tab styling improvements */
.v-tab {
    border-radius: 8px !important;
    margin: 0 4px !important;
    text-transform: none !important;
}

.v-tab--selected {
    background: rgba(79, 70, 229, 0.1) !important;
    color: #4f46e5 !important;
}

/* Card title styling */
.v-card-title {
    border-radius: 8px 8px 0 0 !important;
    font-weight: 600 !important;
}

/* Gradient backgrounds */
.bg-gradient-to-r {
    background: linear-gradient(to right, var(--tw-gradient-stops));
}

.from-blue-50 {
    --tw-gradient-from: #eff6ff;
    --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(239, 246, 255, 0));
}

.to-indigo-50 {
    --tw-gradient-to: #eef2ff;
}

.from-green-50 {
    --tw-gradient-from: #f0fdf4;
    --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(240, 253, 244, 0));
}

.to-emerald-50 {
    --tw-gradient-to: #ecfdf5;
}

.from-purple-50 {
    --tw-gradient-from: #faf5ff;
    --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(250, 245, 255, 0));
}

.to-pink-50 {
    --tw-gradient-to: #fdf2f8;
}

/* Color utilities */
.text-gray-900 {
    color: #111827 !important;
}

.text-gray-600 {
    color: #4b5563 !important;
}

.text-gray-500 {
    color: #6b7280 !important;
}

.text-green-600 {
    color: #059669 !important;
}

.text-green-700 {
    color: #047857 !important;
}

.text-amber-600 {
    color: #d97706 !important;
}

.text-red-600 {
    color: #dc2626 !important;
}

.bg-gray-50 {
    background-color: #f9fafb !important;
}

/* Responsive grid improvements */
.v-row.ma-0 {
    margin: 0 !important;
}

.v-col.pa-2 {
    padding: 8px !important;
}

/* Loading and transition effects */
.v-window-item {
    transition: all 0.3s ease-in-out;
}

/* Enhanced button styling */
.v-btn--variant-elevated {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12) !important;
}

.v-btn--variant-elevated:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
    transform: translateY(-1px);
}

/* Date picker styling */
.v-date-picker {
    border-radius: 8px !important;
}

/* Improved spacing for mobile */
@media (max-width: 768px) {
    .py-6 {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
    }

    .space-y-8 > * + * {
        margin-top: 1.5rem !important;
    }

    .mb-8 {
        margin-bottom: 1.5rem !important;
    }
}
</style>
