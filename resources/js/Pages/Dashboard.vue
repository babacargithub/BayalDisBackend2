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
            total_amount_grss: 0,
            total_amount_paid: 0,
            total_amount_unpaid: 0,
            total_profit: 0,
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
            total_payments: 0
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
            total_payments: 0
        })
    },
    selectedDate: {
        type: String,
        required: true
    }
});
// today
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
        // Format the date as YYYY-MM-DD
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

// Add computed properties for weekly and monthly sections
const weeklyVentesSection = `
    <div class="text-center">
        <div class="text-h5">{{ weeklyStats.total_ventes }}</div>
        <div class="text-caption">Total</div>
    </div>
    <div class="text-center">
        <div class="text-h5 primary--text">{{ formatCurrency(weeklyStats.total_amount_gross) }}</div>
        <div class="text-caption">Brut</div>
    </div>
    <div class="text-center">
        <div class="text-h5 success--text">{{ formatCurrency(weeklyStats.total_amount_paid) }}</div>
        <div class="text-caption">Payé</div>
    </div>
    <div class="text-center">
        <div class="text-h5 error--text">{{ formatCurrency(weeklyStats.total_amount_unpaid) }}</div>
        <div class="text-caption">Impayé</div>
    </div>
`;

const monthlyVentesSection = `
    <div class="text-center">
        <div class="text-h5">{{ monthlyStats.total_ventes }}</div>
        <div class="text-caption">Total</div>
    </div>
    <div class="text-center">
        <div class="text-h5 primary--text">{{ formatCurrency(monthlyStats.total_amount_gross) }}</div>
        <div class="text-caption">Brut</div>
    </div>
    <div class="text-center">
        <div class="text-h5 success--text">{{ formatCurrency(monthlyStats.total_amount_paid) }}</div>
        <div class="text-caption">Payé</div>
    </div>
    <div class="text-center">
        <div class="text-h5 error--text">{{ formatCurrency(monthlyStats.total_amount_unpaid) }}</div>
        <div class="text-caption">Impayé</div>
    </div>
`;
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tableau de bord</h2>
                <div class="d-flex align-center">
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
                            >
                                {{ formattedDate }}
                            </v-btn>
                        </template>
                        
                        <v-card>
                            <v-card-text>
                                <v-date-picker
                                    :key="datePickerKey"
                                    :max="today"
                                    :first-day-of-week="1"
                                    locale="fr"
                                    @update:model-value="handleDateChange"
                                />
                            </v-card-text>
                        </v-card>
                    </v-menu>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Overall Stats Summary -->
                <v-row>
                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    TOTAL CLIENTS
                                </div>
                                <div class="text-h4 mb-2">
                                    {{ overallStats.total_customers }}
                                </div>
                                <div class="d-flex justify-space-between">
                                    <div>
                                        <v-icon color="success" class="mr-1">mdi-account-check</v-icon>
                                        {{ overallStats.total_confirmed_customers }}
                                    </div>
                                    <div>
                                        <v-icon color="warning" class="mr-1">mdi-account-question</v-icon>
                                        {{ overallStats.total_prospects }}
                                    </div>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    TOTAL VENTES
                                </div>
                                <div class="text-h4 mb-2">
                                    {{ overallStats.total_ventes }}
                                </div>
                                <div class="d-flex justify-space-between">
                                    <div>
                                        <v-icon color="success" class="mr-1">mdi-check-circle</v-icon>
                                        {{ overallStats.total_ventes_paid }}
                                    </div>
                                    <div>
                                        <v-icon color="error" class="mr-1">mdi-alert-circle</v-icon>
                                        {{ overallStats.total_ventes_unpaid }}
                                    </div>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    TOTAL BÉNÉFICES
                                </div>
                                <div class="text-h4 mb-2 success--text">
                                    {{ formatCurrency(overallStats.total_profit) }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    TOTAL COMMERCIAUX
                                </div>
                                <div class="text-h4 mb-2">
                                    {{ overallStats.total_commerciaux }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    TOTAL ENCAISSEMENTS
                                </div>
                                <div class="text-h4 mb-2 warning--text">
                                    {{ formatCurrency(overallStats.total_payments) }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Period Stats -->
                <v-tabs
                    v-model="tab"
                    bg-color="primary"
                >
                    <v-tab value="daily">Aujourd'hui</v-tab>
                    <v-tab value="weekly">Cette Semaine</v-tab>
                    <v-tab value="monthly">Ce Mois</v-tab>
                </v-tabs>

                <v-window v-model="tab">
                    <v-window-item value="daily">
                        <v-card>
                            <v-card-text>
                                <v-row>
                                    <v-col cols="12" md="6">
                                        <v-card variant="outlined">
                                            <v-card-title class="text-subtitle-1">
                                                <v-icon start>mdi-account-group</v-icon>
                                                Clients
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex justify-space-between">
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ dailyStats.total_customers }}</div>
                                                        <div class="text-caption">Total</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ dailyStats.total_prospects }}</div>
                                                        <div class="text-caption">Prospects</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ dailyStats.total_confirmed_customers }}</div>
                                                        <div class="text-caption">Confirmés</div>
                                                    </div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-col>
                                    <v-col cols="12" md="6">
                                        <v-card variant="outlined">
                                            <v-card-title class="text-subtitle-1">
                                                <v-icon start>mdi-cash-register</v-icon>
                                                Ventes
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex justify-space-between">
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ dailyStats.total_ventes }}</div>
                                                        <div class="text-caption">Total</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold primary--text">{{ formatCurrency(dailyStats.total_amount_gross) }}</div>
                                                        <div class="text-caption">Brut</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold success--text">{{ formatCurrency(dailyStats.total_amount_paid) }}</div>
                                                        <div class="text-caption">Payé</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold error--text">{{ formatCurrency(dailyStats.total_amount_unpaid) }}</div>
                                                        <div class="text-caption">Impayé</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold warning--text">{{ formatCurrency(dailyStats.total_payments) }}</div>
                                                        <div class="text-caption">Encaissements</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold success--text">{{ formatCurrency(dailyStats.total_profit) }}</div>
                                                        <div class="text-caption">Bénéfice</div>
                                                    </div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-col>
                                </v-row>
                            </v-card-text>
                        </v-card>
                    </v-window-item>

                    <v-window-item value="weekly">
                        <v-card>
                            <v-card-text>
                                <v-row>
                                    <v-col cols="12" md="6">
                                        <v-card variant="outlined">
                                            <v-card-title class="text-subtitle-1">
                                                <v-icon start>mdi-account-group</v-icon>
                                                Clients
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex justify-space-between">
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ weeklyStats.total_customers }}</div>
                                                        <div class="text-caption">Total</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ weeklyStats.total_prospects }}</div>
                                                        <div class="text-caption">Prospects</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ weeklyStats.total_confirmed_customers }}</div>
                                                        <div class="text-caption">Confirmés</div>
                                                    </div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-col>
                                    <v-col cols="12" md="6">
                                        <v-card variant="outlined">
                                            <v-card-title class="text-subtitle-1">
                                                <v-icon start>mdi-cash-register</v-icon>
                                                Ventes
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex justify-space-between">
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ weeklyStats.total_ventes }}</div>
                                                        <div class="text-caption">Total</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold primary--text">{{ formatCurrency(weeklyStats.total_amount_gross) }}</div>
                                                        <div class="text-caption">Brut</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold success--text">{{ formatCurrency(weeklyStats.total_amount_paid) }}</div>
                                                        <div class="text-caption">Payé</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold error--text">{{ formatCurrency(weeklyStats.total_amount_unpaid) }}</div>
                                                        <div class="text-caption">Impayé</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold warning--text">{{ formatCurrency(weeklyStats.total_payments) }}</div>
                                                        <div class="text-caption">Encaissements</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold success--text">{{ formatCurrency(weeklyStats.total_profit) }}</div>
                                                        <div class="text-caption">Bénéfice</div>
                                                    </div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-col>
                                </v-row>
                            </v-card-text>
                        </v-card>
                    </v-window-item>

                    <v-window-item value="monthly">
                        <v-card>
                            <v-card-text>
                                <v-row>
                                    <v-col cols="12" md="6">
                                        <v-card variant="outlined">
                                            <v-card-title class="text-subtitle-1">
                                                <v-icon start>mdi-account-group</v-icon>
                                                Clients
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex justify-space-between">
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ monthlyStats.total_customers }}</div>
                                                        <div class="text-caption">Total</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ monthlyStats.total_prospects }}</div>
                                                        <div class="text-caption">Prospects</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ monthlyStats.total_confirmed_customers }}</div>
                                                        <div class="text-caption">Confirmés</div>
                                                    </div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-col>
                                    <v-col cols="12" md="6">
                                        <v-card variant="outlined">
                                            <v-card-title class="text-subtitle-1">
                                                <v-icon start>mdi-cash-register</v-icon>
                                                Ventes
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex justify-space-between">
                                                    <div class="text-center">
                                                        <div class="text-h5">{{ monthlyStats.total_ventes }}</div>
                                                        <div class="text-caption">Total</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold primary--text">{{ formatCurrency(monthlyStats.total_amount_gross) }}</div>
                                                        <div class="text-caption">Brut</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold success--text">{{ formatCurrency(monthlyStats.total_amount_paid) }}</div>
                                                        <div class="text-caption">Payé</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold error--text">{{ formatCurrency(monthlyStats.total_amount_unpaid) }}</div>
                                                        <div class="text-caption">Impayé</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold warning--text">{{ formatCurrency(monthlyStats.total_payments) }}</div>
                                                        <div class="text-caption">Encaissements</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-h6 font-weight-bold success--text">{{ formatCurrency(monthlyStats.total_profit) }}</div>
                                                        <div class="text-caption">Bénéfice</div>
                                                    </div>
                                                </div>
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
    </AuthenticatedLayout>
</template>

<style scoped>
.text-h5 {
    font-size: 1.15rem !important;
    line-height: 1.2 !important;
}

.text-h6 {
    font-size: 1rem !important;
    line-height: 1.2 !important;
}
</style>
