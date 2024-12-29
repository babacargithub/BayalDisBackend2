<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Dashboard</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-container>
                    <v-row>
                        <v-col cols="12" md="6" lg="3">
                            <v-card>
                                <v-card-title>Ventes</v-card-title>
                                <v-card-text>
                                    <v-icon size="48" color="primary">mdi-cart</v-icon>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="12" md="6" lg="3">
                            <v-card>
                                <v-card-title>Clients</v-card-title>
                                <v-card-text>
                                    <v-icon size="48" color="info">mdi-account-group</v-icon>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="12" md="6" lg="3">
                            <v-card>
                                <v-card-title>Produits</v-card-title>
                                <v-card-text>
                                    <v-icon size="48" color="success">mdi-package-variant</v-icon>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="12" md="6" lg="3">
                            <v-card>
                                <v-card-title>Commerciaux</v-card-title>
                                <v-card-text>
                                    <v-icon size="48" color="warning">mdi-account-tie</v-icon>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-container>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
