<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    statistics: Object
});

const formatAmount = (amount) => {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' F';
};
</script>

<template>
    <Head title="Rapports" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Rapports</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Stock Value -->
                    <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.stock_value) }}</div>
                            <div class="text-gray-600">Valeur du stock</div>
                        </v-card-text>
                    </v-card>

                    <!-- Total Debt -->
                    <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.total_debt) }}</div>
                            <div class="text-gray-600">Total des dettes</div>
                        </v-card-text>
                    </v-card>

                    <!-- Total Investments -->
                    <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.total_investments) }}</div>
                            <div class="text-gray-600">Total des investissements</div>
                        </v-card-text>
                    </v-card>

                    <!-- Total Expenses -->
                    <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.total_depenses) }}</div>
                            <div class="text-gray-600">Total des dépenses</div>
                        </v-card-text>
                    </v-card>

                    <!-- Total Sales -->
                    <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.total_sales) }}</div>
                            <div class="text-gray-600">Total des ventes</div>
                        </v-card-text>
                    </v-card>

                    <!-- Total Profits -->
                    <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.total_profits) }}</div>
                            <div class="text-gray-600">Total des profits</div>
                        </v-card-text>
                    </v-card>
                  <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.total_caisses) }}</div>
                            <div class="text-gray-600">Solde Caisses</div>
                        </v-card-text>
                    </v-card>
                  <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.value_of_business) }}</div>
                            <div class="text-gray-600">Valeur Business</div>
                        </v-card-text>
                    </v-card>
                  <v-card class="rounded-lg">
                        <v-card-text class="text-center">
                            <div class="text-3xl font-bold mb-2">{{ formatAmount(statistics.net_profit) }}</div>
                            <div class="text-gray-600">Bénéfice net</div>
                        </v-card-text>
                    </v-card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 