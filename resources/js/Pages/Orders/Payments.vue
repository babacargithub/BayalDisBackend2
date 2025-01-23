<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    order: {
        type: Object,
        required: true
    }
});

const formatDate = (date) => {
    return new Date(date).toLocaleString('fr-FR');
};

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
    <Head title="Historique des paiements" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Historique des paiements - {{ order.customer?.name }}
                </h2>
                <v-btn color="primary" :href="route('orders.index')">
                    Retour aux commandes
                </v-btn>
            </div>
        </template>

        <v-container>
            <v-card class="mb-4">
                <v-card-text>
                    <div class="text-h6 mb-2">Détails de la commande</div>
                    <v-row>
                        <v-col cols="12" sm="6" md="3">
                            <div class="text-subtitle-2">Total</div>
                            <div class="text-h6">{{ formatCurrency(order.total_amount) }}</div>
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <div class="text-subtitle-2">Déjà payé</div>
                            <div class="text-h6">{{ formatCurrency(order.paid_amount) }}</div>
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <div class="text-subtitle-2">Reste à payer</div>
                            <div class="text-h6">{{ formatCurrency(order.remaining_amount) }}</div>
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <div class="text-subtitle-2">Statut</div>
                            <v-chip
                                :color="order.is_fully_paid ? 'success' : 'warning'"
                                class="mt-1"
                            >
                                {{ order.is_fully_paid ? 'Payé' : 'Paiement partiel' }}
                            </v-chip>
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card>
                <v-card-title>Liste des paiements</v-card-title>
                <v-card-text>
                    <v-table v-if="order.payments?.length">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Montant</th>
                                <th>Mode de paiement</th>
                                <th>Commentaire</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="payment in order.payments" :key="payment.id">
                                <td>{{ formatDate(payment.created_at) }}</td>
                                <td>{{ formatCurrency(payment.amount) }}</td>
                                <td>{{ payment.payment_method }}</td>
                                <td>{{ payment.comment || '-' }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <div v-else class="text-center py-4">
                        Aucun paiement enregistré
                    </div>
                </v-card-text>
            </v-card>
        </v-container>
    </AuthenticatedLayout>
</template> 