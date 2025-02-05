<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    caisse: Object,
    transactions: Array
});

const flash = computed(() => usePage().props.flash || {});
const flashMessage = computed(() => flash.value.success || '');
const snackbar = ref(false);
const dialog = ref(false);
const transactionType = ref('DEPOSIT');

watch(() => flash.value.success, (message) => {
    if (message) {
        snackbar.value = true;
    }
});

const form = useForm({
    amount: null,
    label: ''
});

const formatAmount = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(amount);
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const submit = () => {
    // Convert amount to negative if it's a withdrawal
    const amount = transactionType.value === 'WITHDRAW' ? -Math.abs(form.amount) : Math.abs(form.amount);
    form.amount = amount;

    form.post(route('caisses.transactions.store', props.caisse.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = false;
            form.reset();
            transactionType.value = 'DEPOSIT';
        }
    });
};

const deleteTransaction = (transaction) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette transaction ?')) {
        form.delete(route('caisses.transactions.destroy', [props.caisse.id, transaction.id]), {
            preserveScroll: true
        });
    }
};
</script>

<template>
    <Head title="Transactions de la caisse" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                        Transactions - {{ caisse.name }}
                    </h2>
                    <div class="text-sm text-gray-600 mt-1">
                        Balance actuelle: {{ formatAmount(caisse.balance) }}
                    </div>
                </div>
                <v-btn color="primary" @click="dialog = true">
                    <v-icon start>mdi-plus</v-icon>
                    Nouvelle Transaction
                </v-btn>
            </div>
        </template>

        <!-- Flash Message Snackbar -->
        <v-snackbar
            v-model="snackbar"
            :timeout="3000"
            color="success"
        >
            {{ flashMessage }}
            <template v-slot:actions>
                <v-btn
                    color="white"
                    variant="text"
                    @click="snackbar = false"
                >
                    Fermer
                </v-btn>
            </template>
        </v-snackbar>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-data-table
                        :headers="[
                            { title: 'Date', key: 'created_at' },
                            { title: 'Type', key: 'transaction_type' },
                            { title: 'Montant', key: 'amount' },
                            { title: 'Label', key: 'label' },
                            { title: 'Actions', key: 'actions', sortable: false },
                        ]"
                        :items="transactions"
                    >
                        <template v-slot:item.created_at="{ item }">
                            {{ formatDate(item.created_at) }}
                        </template>

                        <template v-slot:item.transaction_type="{ item }">
                            <v-chip
                                :color="item.transaction_type === 'WITHDRAW' ? 'error' : 'success'"
                                :text="item.transaction_type === 'WITHDRAW' ? 'Retrait' : 'Dépôt'"
                            />
                        </template>

                        <template v-slot:item.amount="{ item }">
                            <span :class="item.transaction_type === 'WITHDRAW' ? 'text-error' : 'text-success'">
                                {{ formatAmount(Math.abs(item.amount)) }}
                            </span>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <v-btn 
                                icon="mdi-delete" 
                                variant="text" 
                                color="error"
                                @click="deleteTransaction(item)"
                            />
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </div>

        <!-- Add Transaction Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>Nouvelle Transaction</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-select
                            v-model="transactionType"
                            :items="[
                                { title: 'Dépôt', value: 'DEPOSIT' },
                                { title: 'Retrait', value: 'WITHDRAW' }
                            ]"
                            item-title="title"
                            item-value="value"
                            label="Type de transaction"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-text-field
                            v-model.number="form.amount"
                            label="Montant"
                            type="number"
                            :error-messages="form.errors.amount"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-text-field
                            v-model="form.label"
                            label="Label"
                            :error-messages="form.errors.label"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                            <v-btn 
                                color="primary" 
                                type="submit" 
                                :loading="form.processing"
                            >
                                Ajouter
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 