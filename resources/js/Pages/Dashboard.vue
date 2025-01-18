<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const tab = ref('daily');

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
            total_amount_unpaid: 0
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
            total_amount_unpaid: 0
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
            total_amount_unpaid: 0
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
            total_commerciaux: 0
        })
    }
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
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
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tableau de bord</h2>
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
                                    MONTANT BRUT
                                </div>
                                <div class="text-h5 mb-2 primary--text font-weight-bold">
                                    {{ formatCurrency(overallStats.total_amount_gross || 0) }}
                                </div>
                                <v-icon color="primary">mdi-cash-multiple</v-icon>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    MONTANT PAYÉ
                                </div>
                                <div class="text-h5 mb-2 success--text font-weight-bold">
                                    {{ formatCurrency(overallStats.total_amount_paid || 0) }}
                                </div>
                                <v-icon color="success">mdi-trending-up</v-icon>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="2">
                        <v-card class="mx-auto" elevation="2">
                            <v-card-text>
                                <div class="text-overline mb-1">
                                    MONTANT IMPAYÉ
                                </div>
                                <div class="text-h5 mb-2 error--text font-weight-bold">
                                    {{ formatCurrency(overallStats.total_amount_unpaid || 0) }}
                                </div>
                                <v-icon color="error">mdi-cash-remove</v-icon>
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
