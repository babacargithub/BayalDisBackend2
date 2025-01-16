<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    commercial: Object,
    stats: Object,
});

const tab = ref('daily');

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
};
</script>

<template>
    <Head :title="'Activité - ' + commercial.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Activité de {{ commercial.name }}
                </h2>
            </div>
        </template>

        <v-container>
            <!-- Overall Stats -->
            <v-row>
                <v-col cols="12" md="3">
                    <v-card elevation="2" class="rounded-lg">
                        <v-card-text class="text-center pa-4">
                            <v-icon size="36" color="primary" class="mb-2">mdi-account-group</v-icon>
                            <div class="text-h6 font-weight-bold mb-4">Total Clients</div>
                            <div class="text-h4 font-weight-bold mb-4">{{ stats.overall.customers_count_all }}</div>
                            <v-row>
                                <v-col cols="6">
                                    <div class="text-caption">Confirmés</div>
                                    <div class="text-subtitle-1 font-weight-bold text-success">
                                        {{ stats.overall.customers_count_confirmed }}
                                    </div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="text-caption">Prospects</div>
                                    <div class="text-subtitle-1 font-weight-bold text-warning">
                                        {{ stats.overall.customers_count_prospects }}
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="3">
                    <v-card elevation="2" class="rounded-lg">
                        <v-card-text class="text-center pa-4">
                            <v-icon size="36" color="primary" class="mb-2">mdi-cart</v-icon>
                            <div class="text-h6 font-weight-bold mb-4">Total Ventes</div>
                            <div class="text-h4 font-weight-bold mb-4">{{ stats.overall.ventes_count_all }}</div>
                            <v-row>
                                <v-col cols="6">
                                    <div class="text-caption">Payées</div>
                                    <div class="text-subtitle-1 font-weight-bold text-success">
                                        {{ stats.overall.ventes_count_paid }}
                                    </div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="text-caption">Non Payées</div>
                                    <div class="text-subtitle-1 font-weight-bold text-error">
                                        {{ stats.overall.ventes_count_unpaid }}
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="3">
                    <v-card elevation="2" class="rounded-lg">
                        <v-card-text class="text-center pa-4">
                            <v-icon size="36" color="primary" class="mb-2">mdi-cash-multiple</v-icon>
                            <div class="text-h6 font-weight-bold mb-4">Montant Total</div>
                            <div class="text-h4 font-weight-bold mb-4">{{ formatCurrency(stats.overall.total_ventes_all) }}</div>
                            <v-row>
                                <v-col cols="6">
                                    <div class="text-caption">Payé</div>
                                    <div class="text-subtitle-1 font-weight-bold text-success">
                                        {{ formatCurrency(stats.overall.total_ventes_paid) }}
                                    </div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="text-caption">Non Payé</div>
                                    <div class="text-subtitle-1 font-weight-bold text-error">
                                        {{ formatCurrency(stats.overall.total_ventes_unpaid) }}
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="3">
                    <v-card elevation="2" class="rounded-lg">
                        <v-card-text class="text-center pa-4">
                            <v-icon size="36" color="primary" class="mb-2">mdi-cash</v-icon>
                            <div class="text-h6 font-weight-bold mb-4">Commission</div>
                            <div class="text-h4 font-weight-bold text-primary">?</div>
                            <div class="text-caption mt-4">Calculée sur les ventes payées</div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Period Stats -->
            <v-row class="mt-6">
                <v-col cols="12">
                    <v-card elevation="2" class="rounded-lg">
                        <v-card-text>
                            <v-tabs v-model="tab" color="primary" grow>
                                <v-tab value="daily" class="text-subtitle-1">Aujourd'hui</v-tab>
                                <v-tab value="weekly" class="text-subtitle-1">Cette Semaine</v-tab>
                                <v-tab value="monthly" class="text-subtitle-1">Ce Mois</v-tab>
                            </v-tabs>

                            <v-window v-model="tab">
                                <v-window-item v-for="period in ['daily', 'weekly', 'monthly']" :key="period" :value="period">
                                    <v-row class="mt-6">
                                        <v-col cols="12" md="3">
                                            <div class="text-h6 font-weight-bold mb-4">
                                                <v-icon color="primary" class="mr-2">mdi-account-group</v-icon>
                                                Clients
                                            </div>
                                            <v-list density="compact">
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="grey">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Total: {{ stats[period].customers_count_all }}</v-list-item-title>
                                                </v-list-item>
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="success">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Confirmés: {{ stats[period].customers_count_confirmed }}</v-list-item-title>
                                                </v-list-item>
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="warning">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Prospects: {{ stats[period].customers_count_prospects }}</v-list-item-title>
                                                </v-list-item>
                                            </v-list>
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <div class="text-h6 font-weight-bold mb-4">
                                                <v-icon color="primary" class="mr-2">mdi-cart</v-icon>
                                                Ventes
                                            </div>
                                            <v-list density="compact">
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="grey">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Total: {{ stats[period].ventes_count_all }}</v-list-item-title>
                                                </v-list-item>
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="success">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Payées: {{ stats[period].ventes_count_paid }}</v-list-item-title>
                                                </v-list-item>
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="error">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Non Payées: {{ stats[period].ventes_count_unpaid }}</v-list-item-title>
                                                </v-list-item>
                                            </v-list>
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <div class="text-h6 font-weight-bold mb-4">
                                                <v-icon color="primary" class="mr-2">mdi-cash-multiple</v-icon>
                                                Montants
                                            </div>
                                            <v-list density="compact">
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="grey">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Total: {{ formatCurrency(stats[period].total_ventes_all) }}</v-list-item-title>
                                                </v-list-item>
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="success">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Payé: {{ formatCurrency(stats[period].total_ventes_paid) }}</v-list-item-title>
                                                </v-list-item>
                                                <v-list-item>
                                                    <template v-slot:prepend>
                                                        <v-icon color="error">mdi-circle-small</v-icon>
                                                    </template>
                                                    <v-list-item-title>Non Payé: {{ formatCurrency(stats[period].total_ventes_unpaid) }}</v-list-item-title>
                                                </v-list-item>
                                            </v-list>
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <div class="text-h6 font-weight-bold mb-4">
                                                <v-icon color="primary" class="mr-2">mdi-cash</v-icon>
                                                Commission
                                            </div>
                                            <div class="text-h5 font-weight-bold text-primary mt-4">?</div>
                                            <div class="text-caption">Calculée sur les ventes payées</div>
                                        </v-col>
                                    </v-row>
                                </v-window-item>
                            </v-window>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Action Buttons -->
            <v-row class="mt-6">
                <v-col cols="12" md="6">
                    <v-btn 
                        color="primary" 
                        block
                        :href="route('clients.index', { commercial_id: commercial.id })"
                        elevation="2"
                        class="text-subtitle-1"
                    >
                        <v-icon start>mdi-account-group</v-icon>
                        Voir les Clients
                    </v-btn>
                </v-col>
                <v-col cols="12" md="6">
                    <v-btn 
                        color="primary" 
                        block
                        :href="route('ventes.index', { commercial_id: commercial.id })"
                        elevation="2"
                        class="text-subtitle-1"
                    >
                        <v-icon start>mdi-cart</v-icon>
                        Voir les Ventes
                    </v-btn>
                </v-col>
            </v-row>
        </v-container>
    </AuthenticatedLayout>
</template> 