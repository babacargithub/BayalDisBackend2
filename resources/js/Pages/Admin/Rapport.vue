<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    statistics: Object,
});

const formatAmount = (amount) => {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' F';
};

const netPlusValueIsPositive = computed(() => props.statistics.net_plus_value >= 0);

const netPlusValueColor = computed(() => netPlusValueIsPositive.value ? 'success' : 'error');

const netPlusValueIcon = computed(() => netPlusValueIsPositive.value ? 'mdi-trending-up' : 'mdi-trending-down');

const unpaidInvoicesStartDateFormatted = computed(() =>
    new Date(props.statistics.unpaid_invoices_start_date).toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    })
);
</script>

<template>
    <Head title="Rapport" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tableau de bord financier</h2>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Hero: Business Value -->
                <v-card color="primary" class="rounded-xl" elevation="4">
                    <v-card-text class="pa-6">
                        <div class="d-flex align-center justify-space-between flex-wrap gap-4">
                            <div>
                                <div class="text-overline text-white opacity-80 mb-1">Valeur actuelle du business</div>
                                <div class="text-h3 font-weight-bold text-white">
                                    {{ formatAmount(statistics.business_value) }}
                                </div>
                                <div class="text-caption text-white opacity-70 mt-1">
                                    Stock entrepôt + Stock tournées + Factures impayées + Caisses
                                </div>
                            </div>
                            <v-icon size="72" color="white" class="opacity-20">mdi-briefcase-variant</v-icon>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Stock & Caisses Breakdown -->
                <div class="text-subtitle-1 font-weight-medium text-grey-darken-2 px-1">Composition du business</div>
                <v-row>
                    <v-col cols="12" md="4">
                        <v-card class="rounded-xl h-100" elevation="2">
                            <v-card-text class="pa-5">
                                <div class="d-flex align-center gap-3 mb-3">
                                    <v-avatar color="blue-lighten-4" size="44">
                                        <v-icon color="blue-darken-2">mdi-warehouse</v-icon>
                                    </v-avatar>
                                    <span class="text-subtitle-2 text-grey-darken-2">Stock entrepôt</span>
                                </div>
                                <div class="text-h5 font-weight-bold text-blue-darken-2">
                                    {{ formatAmount(statistics.warehouse_stock_value) }}
                                </div>
                                <div class="text-caption text-grey mt-1">Valeur des produits en stock</div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="4">
                        <v-card class="rounded-xl h-100" elevation="2">
                            <v-card-text class="pa-5">
                                <div class="d-flex align-center gap-3 mb-3">
                                    <v-avatar color="orange-lighten-4" size="44">
                                        <v-icon color="orange-darken-2">mdi-truck-cargo-container</v-icon>
                                    </v-avatar>
                                    <span class="text-subtitle-2 text-grey-darken-2">Stock tournées actives</span>
                                </div>
                                <div class="text-h5 font-weight-bold text-orange-darken-2">
                                    {{ formatAmount(statistics.car_loads_stock_value) }}
                                </div>
                                <div class="text-caption text-grey mt-1">Valeur des chargements en cours</div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="4">
                        <v-card class="rounded-xl h-100" elevation="2">
                            <v-card-text class="pa-5">
                                <div class="d-flex align-center gap-3 mb-3">
                                    <v-avatar color="green-lighten-4" size="44">
                                        <v-icon color="green-darken-2">mdi-cash-multiple</v-icon>
                                    </v-avatar>
                                    <span class="text-subtitle-2 text-grey-darken-2">Solde des caisses</span>
                                </div>
                                <div class="text-h5 font-weight-bold text-green-darken-2">
                                    {{ formatAmount(statistics.total_caisses_balance) }}
                                </div>
                                <div class="text-caption text-grey mt-1">Total de tous les comptes de trésorerie</div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Unpaid Invoices -->
                <v-card class="rounded-xl" elevation="2">
                    <v-card-text class="pa-5">
                        <div class="d-flex align-center gap-3 mb-4">
                            <v-avatar color="red-lighten-4" size="44">
                                <v-icon color="red-darken-2">mdi-file-clock-outline</v-icon>
                            </v-avatar>
                            <div>
                                <div class="text-subtitle-1 font-weight-medium">Factures impayées</div>
                                <div class="text-caption text-grey">Depuis le {{ unpaidInvoicesStartDateFormatted }}</div>
                            </div>
                            <v-spacer />
                            <v-chip color="red" variant="tonal" size="small">
                                {{ statistics.total_unpaid_invoices_count }} facture{{ statistics.total_unpaid_invoices_count > 1 ? 's' : '' }}
                            </v-chip>
                        </div>
                        <div class="text-h5 font-weight-bold text-red-darken-2">
                            {{ formatAmount(statistics.total_unpaid_invoices_amount) }}
                        </div>
                        <div class="text-caption text-grey mt-1">Montant restant à encaisser</div>
                    </v-card-text>
                </v-card>

                <!-- Net Plus Value vs Fond de Roulement -->
                <v-card :color="netPlusValueColor" variant="tonal" class="rounded-xl" elevation="2">
                    <v-card-text class="pa-6">
                        <div class="d-flex align-start justify-space-between flex-wrap gap-4">
                            <div class="flex-grow-1">
                                <div class="text-overline text-grey-darken-1 mb-1">Plus-value nette</div>
                                <div :class="['text-h4 font-weight-bold', netPlusValueIsPositive ? 'text-success' : 'text-error']">
                                    {{ netPlusValueIsPositive ? '+' : '' }}{{ formatAmount(statistics.net_plus_value) }}
                                </div>
                                <div class="text-caption text-grey mt-1">
                                    Valeur business ({{ formatAmount(statistics.business_value) }}) − Fond de roulement ({{ formatAmount(statistics.fond_de_roulement) }})
                                </div>
                            </div>
                            <v-icon size="56" :color="netPlusValueIsPositive ? 'success' : 'error'" class="opacity-40">
                                {{ netPlusValueIcon }}
                            </v-icon>
                        </div>

                        <v-divider class="my-4" />

                        <div class="d-flex align-center gap-2">
                            <v-icon size="18" color="grey">mdi-information-outline</v-icon>
                            <span class="text-caption text-grey-darken-1">
                                Fond de roulement de référence : {{ formatAmount(statistics.fond_de_roulement) }}
                            </span>
                        </div>
                    </v-card-text>
                </v-card>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
