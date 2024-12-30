<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    statistics: Object
});

const formatNumber = (number) => {
    return new Intl.NumberFormat('fr-FR').format(number);
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', { 
        style: 'currency', 
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tableau de bord</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-container>
                    <v-row>
                        <v-col cols="12" md="6" lg="3">
                            <v-card elevation="2" class="rounded-lg">
                                <v-card-item>
                                    <div class="d-flex justify-space-between align-center">
                                        <div>
                                            <div class="text-subtitle-2 mb-1">Total Ventes</div>
                                            <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics.total_sales) }}</div>
                                            <div class="text-caption mt-1">
                                                {{ formatNumber(statistics.total_ventes) }} ventes
                                            </div>
                                        </div>
                                        <v-icon size="48" color="primary">mdi-cart</v-icon>
                                    </div>
                                </v-card-item>
                            </v-card>
                        </v-col>

                        <v-col cols="12" md="6" lg="3">
                            <v-card elevation="2" class="rounded-lg">
                                <v-card-item>
                                    <div class="d-flex justify-space-between align-center">
                                        <div>
                                            <div class="text-subtitle-2 mb-1">Clients</div>
                                            <div class="text-h5 font-weight-bold">{{ formatNumber(statistics.customers_count) }}</div>
                                            <div class="text-caption mt-1">
                                                clients enregistrés
                                            </div>
                                        </div>
                                        <v-icon size="48" color="info">mdi-account-group</v-icon>
                                    </div>
                                </v-card-item>
                            </v-card>
                        </v-col>

                        <v-col cols="12" md="6" lg="3">
                            <v-card elevation="2" class="rounded-lg">
                                <v-card-item>
                                    <div class="d-flex justify-space-between align-center">
                                        <div>
                                            <div class="text-subtitle-2 mb-1">Impayés</div>
                                            <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics.total_unpaid_amount) }}</div>
                                            <div class="text-caption mt-1">
                                                {{ formatNumber(statistics.unpaid_ventes) }} ventes impayées
                                            </div>
                                        </div>
                                        <v-icon size="48" color="error">mdi-cash-remove</v-icon>
                                    </div>
                                </v-card-item>
                            </v-card>
                        </v-col>

                        <v-col cols="12" md="6" lg="3">
                            <v-card elevation="2" class="rounded-lg">
                                <v-card-item>
                                    <div class="d-flex justify-space-between align-center">
                                        <div>
                                            <div class="text-subtitle-2 mb-1">Commerciaux</div>
                                            <div class="text-h5 font-weight-bold">{{ formatNumber(statistics.commercials_count) }}</div>
                                            <div class="text-caption mt-1">
                                                commerciaux actifs
                                            </div>
                                        </div>
                                        <v-icon size="48" color="warning">mdi-account-tie</v-icon>
                                    </div>
                                </v-card-item>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-container>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
