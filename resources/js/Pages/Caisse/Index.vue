<template>
    <Head title="Caisses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Gestion des Caisses</h2>
                <v-btn color="primary" @click="openDialog()">
                    <v-icon start>mdi-plus</v-icon>
                    Nouvelle Caisse
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
                            { title: 'Nom', key: 'name' },
                            { title: 'Balance', key: 'balance' },
                            { title: 'Status', key: 'closed' },
                            { title: 'Actions', key: 'actions', sortable: false },
                        ]"
                        :items="caisses"
                    >
                        <template v-slot:item.balance="{ item }">
                            {{ formatAmount(item.balance) }}
                        </template>

                        <template v-slot:item.closed="{ item }">
                            <v-chip
                                :color="item.closed ? 'error' : 'success'"
                                :text="item.closed ? 'Fermée' : 'Ouverte'"
                            />
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <div class="flex gap-2">
                                <v-btn 
                                    icon="mdi-pencil" 
                                    variant="text" 
                                    color="primary"
                                    @click="openDialog(item)"
                                    title="Modifier"
                                />
                                <v-btn 
                                    icon="mdi-delete" 
                                    variant="text" 
                                    color="error"
                                    @click="openDeleteDialog(item)"
                                    title="Supprimer"
                                />
                                <v-btn 
                                    icon="mdi-cash-multiple" 
                                    variant="text" 
                                    color="info"
                                    @click="openTransactionsDialog(item)"
                                    title="Voir les transactions"
                                />
                                <v-btn 
                                    icon="mdi-plus" 
                                    variant="text" 
                                    color="success"
                                    @click="openNewTransactionDialog(item)"
                                    title="Nouvelle transaction"
                                />
                            </div>
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </div>

        <!-- Add/Edit Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>{{ editedItem ? 'Modifier la Caisse' : 'Nouvelle Caisse' }}</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            :error-messages="form.errors.name"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-text-field
                            v-model.number="form.balance"
                            label="Balance"
                            type="number"
                            :error-messages="form.errors.balance"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-checkbox
                            v-model="form.closed"
                            label="Fermée"
                            :error-messages="form.errors.closed"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                            <v-btn 
                                color="primary" 
                                type="submit" 
                                :loading="form.processing"
                            >
                                {{ editedItem ? 'Modifier' : 'Ajouter' }}
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Supprimer la caisse</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette caisse ?
                    <div v-if="itemToDelete" class="mt-4">
                        <strong>Détails de la caisse :</strong>
                        <div>Nom : {{ itemToDelete.name }}</div>
                        <div>Balance : {{ formatAmount(itemToDelete.balance) }}</div>
                        <div>Status : {{ itemToDelete.closed ? 'Fermée' : 'Ouverte' }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn 
                        color="primary" 
                        variant="text" 
                        @click="deleteDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn 
                        color="error" 
                        variant="text" 
                        @click="deleteCaisse"
                        :loading="form.processing"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Transactions Dialog -->
        <v-dialog v-model="transactionsDialog" max-width="900px">
            <v-card v-if="selectedCaisse">
                <v-card-title>
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="text-h5">Transactions - {{ selectedCaisse.name }}</span>
                            <div class="text-subtitle-1 mt-1">
                                Balance: {{ formatAmount(selectedCaisse.balance) }}
                            </div>
                        </div>
                        <v-btn
                            color="primary"
                            @click="openNewTransactionDialog(selectedCaisse)"
                        >
                            <v-icon start>mdi-plus</v-icon>
                            Nouvelle Transaction
                        </v-btn>
                    </div>
                </v-card-title>
                <v-card-text>
                    <v-data-table
                        :headers="[
                            { title: 'Date', key: 'created_at' },
                            { title: 'Type', key: 'transaction_type' },
                            { title: 'Montant', key: 'amount' },
                            { title: 'Label', key: 'label' },
                            { title: 'Actions', key: 'actions', sortable: false },
                        ]"
                        :items="selectedCaisse.transactions || []"
                        :loading="!selectedCaisse.transactions"
                        class="mt-4"
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
                                title="Supprimer"
                            />
                        </template>

                        <template v-slot:no-data>
                            Aucune transaction trouvée
                        </template>
                    </v-data-table>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="transactionsDialog = false">
                        Fermer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- New Transaction Dialog -->
        <v-dialog v-model="transactionDialog" max-width="500px">
            <v-card>
                <v-card-title>Nouvelle Transaction</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submitTransaction">
                        <div class="mb-4">
                            <label class="text-subtitle-1 mb-2 block">Type de transaction*</label>
                            <v-radio-group
                                v-model="transactionType"
                                inline
                                :error-messages="!transactionType ? 'Le type de transaction est obligatoire' : ''"
                                class="mt-0"
                            >
                                <v-radio
                                    label="Dépôt"
                                    value="DEPOSIT"
                                    color="success"
                                />
                                <v-radio
                                    label="Retrait"
                                    value="WITHDRAW"
                                    color="error"
                                />
                            </v-radio-group>
                        </div>
                       
                        <v-text-field
                            v-model="transactionForm.label"
                            label="Libellé*"
                            :error-messages="transactionForm.errors.label"
                            variant="outlined"
                            class="mb-4"
                            :rules="[v => !!v || 'Le libellé est obligatoire']"
                        />
                         <v-text-field
                            v-model.number="transactionForm.amount"
                            label="Montant*"
                            type="number"
                            :error-messages="transactionForm.errors.amount"
                            variant="outlined"
                            class="mb-4"
                            :rules="[v => !!v || 'Le montant est obligatoire']"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn 
                                color="error" 
                                @click="() => {
                                    transactionDialog = false;
                                    transactionForm.reset();
                                    transactionType = null;
                                }"
                            >
                                Annuler
                            </v-btn>
                            <v-btn 
                                color="primary" 
                                type="submit" 
                                :loading="transactionForm.processing"
                                :disabled="!transactionType || !transactionForm.amount || !transactionForm.label"
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

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    caisses: Array
});

const flash = computed(() => usePage().props.flash || {});
const flashMessage = computed(() => flash.value.success || '');
const snackbar = ref(false);
const dialog = ref(false);
const transactionsDialog = ref(false);
const transactionDialog = ref(false);
const editedItem = ref(null);
const deleteDialog = ref(false);
const selectedCaisse = ref(null);
const transactionType = ref(null);

const transactionForm = useForm({
    amount: null,
    label: ''
});

watch(() => flash.value.success, (message) => {
    if (message) {
        snackbar.value = true;
    }
});

const form = useForm({
    name: '',
    balance: 0,
    closed: false
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

const openDialog = (item = null) => {
    editedItem.value = item;
    if (item) {
        form.name = item.name;
        form.balance = item.balance;
        form.closed = item.closed;
    } else {
        form.reset();
    }
    dialog.value = true;
};

const openTransactionsDialog = async (caisse) => {
    try {
        selectedCaisse.value = {
            ...caisse,
            transactions: null // Reset transactions to show loading state
        };
        transactionsDialog.value = true;
        
        const response = await axios.get(route('caisses.transactions', caisse.id));
        selectedCaisse.value = {
            ...caisse,
            transactions: response.data.transactions
        };
    } catch (error) {
        console.error('Error fetching transactions:', error);
        // Show error message in snackbar
        snackbar.value = true;
        flashMessage.value = 'Erreur lors du chargement des transactions';
    }
};

const openNewTransactionDialog = (caisse) => {
    selectedCaisse.value = caisse;
    transactionForm.reset();
    transactionType.value = null;
    transactionDialog.value = true;
};

const submitTransaction = () => {
    if (!transactionType.value || !transactionForm.amount || !transactionForm.label) {
        return;
    }

    const amount = transactionType.value === 'WITHDRAW' ? -Math.abs(transactionForm.amount) : Math.abs(transactionForm.amount);
    transactionForm.amount = amount;

    transactionForm.post(route('caisses.transactions.store', selectedCaisse.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            transactionDialog.value = false;
            transactionForm.reset();
            transactionType.value = null;
            // Refresh transactions if the dialog is open
            if (transactionsDialog.value) {
                openTransactionsDialog(selectedCaisse.value);
            }
        }
    });
};

const deleteTransaction = (transaction) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette transaction ?')) {
        transactionForm.delete(route('caisses.transactions.destroy', [selectedCaisse.value.id, transaction.id]), {
            preserveScroll: true,
            onSuccess: () => {
                openTransactionsDialog(selectedCaisse.value);
            }
        });
    }
};

const openDeleteDialog = (item) => {
    itemToDelete.value = item;
    deleteDialog.value = true;
};

const submit = () => {
    if (editedItem.value) {
        form.put(route('caisses.update', editedItem.value.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                editedItem.value = null;
            },
            onError: (errors) => {
                console.error('Update failed:', errors);
            }
        });
    } else {
        form.post(route('caisses.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            },
            onError: (errors) => {
                console.error('Create failed:', errors);
            }
        });
    }
};

const deleteCaisse = () => {
    if (itemToDelete.value) {
        form.delete(route('caisses.destroy', itemToDelete.value.id), {
            onSuccess: () => {
                deleteDialog.value = false;
                itemToDelete.value = null;
            },
        });
    }
};
</script>