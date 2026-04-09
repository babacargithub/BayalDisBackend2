<template>
    <Head title="Comptes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Gestion des Comptes</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        Solde total :
                        <strong class="text-gray-800">{{ formatAmount(totalBalance) }}</strong>
                    </p>
                </div>
                <div class="flex gap-2">
                    <v-btn color="secondary" variant="tonal" @click="openTransferDialog">
                        <v-icon start>mdi-bank-transfer</v-icon>
                        Transfert entre comptes
                    </v-btn>
                    <v-btn color="primary" @click="openCreateDialog">
                        <v-icon start>mdi-plus</v-icon>
                        Nouveau Compte
                    </v-btn>
                </div>
            </div>
        </template>

        <!-- Success snackbar -->
        <v-snackbar v-model="successSnackbar" :timeout="3500" color="success" location="top right">
            {{ successMessage }}
            <template #actions>
                <v-btn variant="text" color="white" @click="successSnackbar = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <!-- Error snackbar -->
        <v-snackbar v-model="errorSnackbar" :timeout="5000" color="error" location="top right">
            {{ errorMessage }}
            <template #actions>
                <v-btn variant="text" color="white" @click="errorSnackbar = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-data-table
                        :headers="tableHeaders"
                        :items="accounts"
                        :sort-by="[{ key: 'balance', order: 'desc' }]"
                        items-per-page="25"
                        class="elevation-0"
                    >
                        <!-- Account type chip -->
                        <template #item.account_type_label="{ item }">
                            <v-chip
                                :color="accountTypeColor(item.account_type)"
                                size="small"
                                variant="tonal"
                            >
                                {{ item.account_type_label }}
                            </v-chip>
                        </template>

                        <!-- Balance -->
                        <template #item.balance="{ item }">
                            <span
                                class="font-semibold"
                                :class="item.balance < 0 ? 'text-error' : ''"
                            >
                                {{ formatAmount(item.balance) }}
                            </span>
                        </template>

                        <!-- Linked entity -->
                        <template #item.linked_to="{ item }">
                            <span v-if="item.linked_to" class="text-caption text-grey-darken-1">
                                {{ item.linked_to }}
                            </span>
                            <span v-else class="text-caption text-grey-lighten-1">—</span>
                        </template>

                        <!-- Active status -->
                        <template #item.is_active="{ item }">
                            <v-chip
                                :color="item.is_active ? 'success' : 'default'"
                                size="small"
                                variant="tonal"
                            >
                                {{ item.is_active ? 'Actif' : 'Inactif' }}
                            </v-chip>
                        </template>

                        <!-- Last updated -->
                        <template #item.updated_at="{ item }">
                            <span class="text-caption text-grey-darken-1">
                                {{ formatDate(item.updated_at) }}
                            </span>
                        </template>

                        <!-- Actions -->
                        <template #item.actions="{ item }">
                            <div class="flex gap-1">
                                <v-btn
                                    icon="mdi-receipt-text-outline"
                                    variant="text"
                                    color="info"
                                    size="small"
                                    title="Voir les transactions"
                                    @click="openTransactionsDialog(item)"
                                />
                                <v-btn
                                    icon="mdi-pencil"
                                    variant="text"
                                    color="primary"
                                    size="small"
                                    title="Modifier"
                                    @click="openEditDialog(item)"
                                />
                                <v-btn
                                    icon="mdi-delete"
                                    variant="text"
                                    color="error"
                                    size="small"
                                    title="Supprimer"
                                    @click="openDeleteDialog(item)"
                                />
                            </div>
                        </template>

                        <template #no-data>
                            <div class="text-center py-8 text-grey-darken-1">
                                Aucun compte trouvé.
                            </div>
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </div>

        <!-- ── Create / Edit Dialog ──────────────────────────────────── -->
        <v-dialog v-model="formDialog" max-width="520px" persistent>
            <v-card>
                <v-card-title class="text-h6 pa-6 pb-2">
                    {{ editingAccount ? 'Modifier le compte' : 'Nouveau compte' }}
                </v-card-title>

                <v-card-text class="pa-6 pt-2">
                    <v-form ref="accountForm" @submit.prevent="submitForm">
                        <v-text-field
                            v-model="form.name"
                            label="Nom du compte *"
                            variant="outlined"
                            :error-messages="form.errors.name"
                            class="mb-4"
                        />

                        <!-- Account type — only editable on create -->
                        <v-select
                            v-if="!editingAccount"
                            v-model="form.account_type"
                            :items="accountTypes"
                            item-title="label"
                            item-value="value"
                            label="Type de compte *"
                            variant="outlined"
                            :error-messages="form.errors.account_type"
                            class="mb-4"
                            @update:model-value="onAccountTypeChange"
                        />
                        <v-text-field
                            v-else
                            :model-value="editingAccount.account_type_label"
                            label="Type de compte"
                            variant="outlined"
                            readonly
                            class="mb-4"
                            hint="Le type de compte ne peut pas être modifié."
                            persistent-hint
                        />

                        <!-- Vehicle selector (shown when type requires a vehicle) -->
                        <v-select
                            v-if="!editingAccount && selectedTypeRequiresVehicle"
                            v-model="form.vehicle_id"
                            :items="vehicles"
                            item-title="name"
                            item-value="id"
                            label="Véhicule *"
                            variant="outlined"
                            :error-messages="form.errors.vehicle_id"
                            class="mb-4"
                        />

                        <!-- Commercial selector (shown when type requires a commercial) -->
                        <v-select
                            v-if="!editingAccount && selectedTypeRequiresCommercial"
                            v-model="form.commercial_id"
                            :items="commercials"
                            item-title="name"
                            item-value="id"
                            label="Commercial *"
                            variant="outlined"
                            :error-messages="form.errors.commercial_id"
                            class="mb-4"
                        />

                        <v-switch
                            v-model="form.is_active"
                            label="Compte actif"
                            color="primary"
                            hide-details
                        />
                    </v-form>
                </v-card-text>

                <v-card-actions class="pa-6 pt-0">
                    <v-spacer />
                    <v-btn variant="text" color="error" @click="closeFormDialog">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        :loading="form.processing"
                        @click="submitForm"
                    >
                        {{ editingAccount ? 'Enregistrer' : 'Créer' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Delete Confirmation Dialog ──────────────────────────────── -->
        <v-dialog v-model="deleteDialog" max-width="480px">
            <v-card>
                <v-card-title class="text-h6 pa-6 pb-2">Supprimer le compte</v-card-title>
                <v-card-text class="pa-6 pt-0">
                    <p>Êtes-vous sûr de vouloir supprimer ce compte ?</p>
                    <v-alert
                        v-if="accountToDelete && accountToDelete.balance !== 0"
                        type="warning"
                        variant="tonal"
                        class="mt-3"
                    >
                        Ce compte a un solde de
                        <strong>{{ formatAmount(accountToDelete.balance) }}</strong>.
                        Seuls les comptes à solde nul peuvent être supprimés.
                    </v-alert>
                    <div v-if="accountToDelete" class="mt-4 text-body-2 text-grey-darken-2">
                        <div><strong>Nom :</strong> {{ accountToDelete.name }}</div>
                        <div><strong>Type :</strong> {{ accountToDelete.account_type_label }}</div>
                        <div><strong>Solde :</strong> {{ formatAmount(accountToDelete.balance) }}</div>
                    </div>
                </v-card-text>
                <v-card-actions class="pa-6 pt-0">
                    <v-spacer />
                    <v-btn variant="text" color="primary" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        :loading="deleteForm.processing"
                        :disabled="accountToDelete && accountToDelete.balance !== 0"
                        @click="confirmDelete"
                    >
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Inter-Account Transfer Dialog ─────────────────────────────── -->
        <v-dialog v-model="transferDialog" max-width="520px" persistent>
            <v-card>
                <v-card-title class="text-h6 pa-6 pb-2">Transfert entre comptes</v-card-title>

                <v-card-text class="pa-6 pt-2">
                    <v-alert
                        type="info"
                        variant="tonal"
                        density="compact"
                        class="mb-4 text-body-2"
                    >
                        Ce transfert réalloue des fonds entre deux comptes sans modifier les caisses.
                    </v-alert>

                    <v-form ref="transferForm" @submit.prevent="submitTransfer">
                        <v-autocomplete
                            v-model="transferFormData.from_account_id"
                            :items="accountsWithBalance"
                            item-title="displayName"
                            item-value="id"
                            label="Compte source *"
                            variant="outlined"
                            :error-messages="transferFormData.errors.from_account_id"
                            class="mb-4"
                            no-data-text="Aucun compte avec solde disponible"
                        />

                        <v-autocomplete
                            v-model="transferFormData.to_account_id"
                            :items="transferDestinationAccounts"
                            item-title="displayName"
                            item-value="id"
                            label="Compte destination *"
                            variant="outlined"
                            :error-messages="transferFormData.errors.to_account_id"
                            class="mb-4"
                            no-data-text="Aucun compte disponible"
                        />

                        <v-text-field
                            v-model.number="transferFormData.amount"
                            label="Montant (F CFA) *"
                            type="number"
                            min="1"
                            variant="outlined"
                            :error-messages="transferFormData.errors.amount"
                            :hint="selectedSourceAccountBalance !== null ? `Solde disponible : ${formatAmount(selectedSourceAccountBalance)}` : ''"
                            persistent-hint
                            class="mb-4"
                        />

                        <v-text-field
                            v-model="transferFormData.label"
                            label="Libellé *"
                            variant="outlined"
                            :error-messages="transferFormData.errors.label"
                        />
                    </v-form>
                </v-card-text>

                <v-card-actions class="pa-6 pt-0">
                    <v-spacer />
                    <v-btn variant="text" color="error" @click="closeTransferDialog">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        :loading="transferFormData.processing"
                        @click="submitTransfer"
                    >
                        Transférer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Transactions Dialog ──────────────────────────────────────── -->
        <v-dialog v-model="transactionsDialog" max-width="900px" scrollable>
            <v-card v-if="selectedAccount">
                <v-card-title class="pa-6 pb-3">
                    <div class="flex justify-between items-start flex-wrap gap-2">
                        <div>
                            <div class="text-h6">{{ selectedAccount.name }}</div>
                            <div class="text-body-2 text-grey-darken-1 mt-1">
                                {{ selectedAccount.account_type_label }}
                                <span v-if="selectedAccount.linked_to"> — {{ selectedAccount.linked_to }}</span>
                            </div>
                            <div class="text-subtitle-2 mt-1">
                                Solde : <strong>{{ formatAmount(selectedAccount.balance) }}</strong>
                            </div>
                        </div>
                        <v-btn variant="text" icon="mdi-close" @click="transactionsDialog = false" />
                    </div>
                </v-card-title>

                <!-- Filters -->
                <v-card-text class="pa-6 pb-0">
                    <div class="flex gap-3 flex-wrap items-end">
                        <v-text-field
                            v-model="txFilters.date_from"
                            label="Du"
                            type="date"
                            variant="outlined"
                            density="compact"
                            hide-details
                            style="max-width: 180px;"
                            clearable
                        />
                        <v-text-field
                            v-model="txFilters.date_to"
                            label="Au"
                            type="date"
                            variant="outlined"
                            density="compact"
                            hide-details
                            style="max-width: 180px;"
                            clearable
                        />
                        <v-select
                            v-model="txFilters.type"
                            :items="transactionTypeOptions"
                            item-title="label"
                            item-value="value"
                            label="Type"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            style="max-width: 160px;"
                        />
                        <v-btn
                            color="primary"
                            variant="tonal"
                            :loading="txLoading"
                            @click="applyTransactionFilters"
                        >
                            <v-icon start>mdi-filter</v-icon>
                            Filtrer
                        </v-btn>
                    </div>
                </v-card-text>

                <v-card-text class="pa-6 pt-3">
                    <v-data-table
                        :headers="transactionHeaders"
                        :items="transactions"
                        :loading="txLoading"
                        items-per-page="20"
                    >
                        <template #item.created_at="{ item }">
                            <span class="text-caption">{{ formatDate(item.created_at) }}</span>
                        </template>

                        <template #item.transaction_type="{ item }">
                            <v-chip
                                :color="item.transaction_type === 'CREDIT' ? 'success' : 'error'"
                                size="small"
                                variant="tonal"
                            >
                                {{ item.transaction_type === 'CREDIT' ? 'Crédit' : 'Débit' }}
                            </v-chip>
                        </template>

                        <template #item.amount="{ item }">
                            <span
                                class="font-semibold"
                                :class="item.transaction_type === 'CREDIT' ? 'text-success' : 'text-error'"
                            >
                                {{ item.transaction_type === 'CREDIT' ? '+' : '−' }}
                                {{ formatAmount(item.amount) }}
                            </span>
                        </template>

                        <template #item.reference="{ item }">
                            <span v-if="item.reference_type" class="text-caption text-grey-darken-1">
                                {{ item.reference_type }}
                                <span v-if="item.reference_id">#{{ item.reference_id }}</span>
                            </span>
                            <span v-else class="text-caption text-grey-lighten-1">—</span>
                        </template>

                        <template #no-data>
                            <div class="text-center py-6 text-grey-darken-1">
                                Aucune transaction trouvée pour ces critères.
                            </div>
                        </template>

                        <template #loading>
                            <div class="text-center py-6 text-grey-darken-1">
                                Chargement des transactions…
                            </div>
                        </template>
                    </v-data-table>
                </v-card-text>

                <v-card-actions class="pa-6 pt-0">
                    <v-spacer />
                    <v-btn color="primary" @click="transactionsDialog = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

// ── Props ──────────────────────────────────────────────────────────────────

const props = defineProps({
    accounts: Array,
    totalBalance: Number,
    vehicles: Array,
    commercials: Array,
    accountTypes: Array,
});

// ── Flash messages ─────────────────────────────────────────────────────────

const flash = computed(() => usePage().props.flash || {});

const successSnackbar = ref(false);
const successMessage = ref('');
const errorSnackbar = ref(false);
const errorMessage = ref('');

watch(
    () => flash.value,
    (newFlash) => {
        if (newFlash.success) {
            successMessage.value = newFlash.success;
            successSnackbar.value = true;
        }
        if (newFlash.error) {
            errorMessage.value = newFlash.error;
            errorSnackbar.value = true;
        }
    },
    { deep: true }
);

// ── Formatters ─────────────────────────────────────────────────────────────

const formatAmount = (amount) =>
    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XOF' }).format(amount);

const formatDate = (dateString) =>
    new Date(dateString).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

// ── Table headers ──────────────────────────────────────────────────────────

const tableHeaders = [
    { title: 'Nom', key: 'name', minWidth: '200px' },
    { title: 'Type', key: 'account_type_label', sortable: true },
    { title: 'Solde', key: 'balance', sortable: true, align: 'end' },
    { title: 'Lié à', key: 'linked_to', sortable: false },
    { title: 'Statut', key: 'is_active', sortable: true },
    { title: 'Mis à jour', key: 'updated_at', sortable: true },
    { title: 'Actions', key: 'actions', sortable: false, align: 'center', minWidth: '130px' },
];

const transactionHeaders = [
    { title: 'Date', key: 'created_at', sortable: true },
    { title: 'Type', key: 'transaction_type', sortable: true },
    { title: 'Montant', key: 'amount', sortable: true, align: 'end' },
    { title: 'Libellé', key: 'label', sortable: false },
    { title: 'Référence', key: 'reference', sortable: false },
];

const transactionTypeOptions = [
    { label: 'Crédit', value: 'CREDIT' },
    { label: 'Débit', value: 'DEBIT' },
];

// ── Account type colour coding ─────────────────────────────────────────────

const accountTypeColor = (type) => {
    const colourMap = {
        MERCHANDISE_SALES: 'blue',
        VEHICLE_DEPRECIATION: 'orange',
        VEHICLE_INSURANCE: 'deep-orange',
        VEHICLE_REPAIR_RESERVE: 'amber',
        VEHICLE_MAINTENANCE: 'brown',
        VEHICLE_FUEL: 'red',
        COMMERCIAL_COMMISSION: 'purple',
        COMMERCIAL_COLLECTED: 'indigo',
        FIXED_COST: 'teal',
    };
    return colourMap[type] ?? 'grey';
};

// ── Create / Edit dialog ───────────────────────────────────────────────────

const formDialog = ref(false);
const editingAccount = ref(null);

const form = useForm({
    name: '',
    account_type: null,
    vehicle_id: null,
    commercial_id: null,
    is_active: true,
});

const selectedTypeDefinition = computed(() =>
    props.accountTypes.find((t) => t.value === form.account_type) ?? null
);
const selectedTypeRequiresVehicle = computed(
    () => selectedTypeDefinition.value?.requires_vehicle ?? false
);
const selectedTypeRequiresCommercial = computed(
    () => selectedTypeDefinition.value?.requires_commercial ?? false
);

const onAccountTypeChange = () => {
    form.vehicle_id = null;
    form.commercial_id = null;
};

const openCreateDialog = () => {
    editingAccount.value = null;
    form.reset();
    form.is_active = true;
    formDialog.value = true;
};

const openEditDialog = (account) => {
    editingAccount.value = account;
    form.name = account.name;
    form.account_type = account.account_type;
    form.vehicle_id = account.vehicle_id;
    form.commercial_id = account.commercial_id;
    form.is_active = account.is_active;
    formDialog.value = true;
};

const closeFormDialog = () => {
    formDialog.value = false;
    form.reset();
    editingAccount.value = null;
};

const submitForm = () => {
    if (editingAccount.value) {
        form.put(route('accounts.update', editingAccount.value.id), {
            preserveScroll: true,
            onSuccess: () => closeFormDialog(),
        });
    } else {
        form.post(route('accounts.store'), {
            preserveScroll: true,
            onSuccess: () => closeFormDialog(),
        });
    }
};

// ── Delete dialog ──────────────────────────────────────────────────────────

const deleteDialog = ref(false);
const accountToDelete = ref(null);
const deleteForm = useForm({});

const openDeleteDialog = (account) => {
    accountToDelete.value = account;
    deleteDialog.value = true;
};

const confirmDelete = () => {
    deleteForm.delete(route('accounts.destroy', accountToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialog.value = false;
            accountToDelete.value = null;
        },
        onError: () => {
            deleteDialog.value = false;
        },
    });
};

// ── Inter-account transfer dialog ─────────────────────────────────────────

const transferDialog = ref(false);
const transferForm = ref(null);

const transferFormData = useForm({
    from_account_id: null,
    to_account_id: null,
    amount: null,
    label: '',
});

const accountsWithBalance = computed(() =>
    props.accounts
        .filter((account) => account.balance > 0)
        .map((account) => ({
            ...account,
            displayName: `${account.name} — ${formatAmount(account.balance)}`,
        }))
);

const transferDestinationAccounts = computed(() =>
    props.accounts
        .filter((account) => account.id !== transferFormData.from_account_id)
        .map((account) => ({
            ...account,
            displayName: `${account.name} — ${formatAmount(account.balance)}`,
        }))
);

const selectedSourceAccountBalance = computed(() => {
    if (!transferFormData.from_account_id) {
        return null;
    }
    const sourceAccount = props.accounts.find((account) => account.id === transferFormData.from_account_id);
    return sourceAccount?.balance ?? null;
});

const openTransferDialog = () => {
    transferFormData.reset();
    transferDialog.value = true;
};

const closeTransferDialog = () => {
    transferDialog.value = false;
    transferFormData.reset();
};

const submitTransfer = () => {
    transferFormData.post(route('accounts.transfer'), {
        preserveScroll: true,
        onSuccess: () => closeTransferDialog(),
    });
};

// ── Transactions dialog ────────────────────────────────────────────────────

const transactionsDialog = ref(false);
const selectedAccount = ref(null);
const transactions = ref([]);
const txLoading = ref(false);

const txFilters = ref({
    date_from: '',
    date_to: '',
    type: null,
});

const fetchTransactions = async () => {
    if (!selectedAccount.value) {
        return;
    }

    txLoading.value = true;

    try {
        const params = {};
        if (txFilters.value.date_from) {
            params.date_from = txFilters.value.date_from;
        }
        if (txFilters.value.date_to) {
            params.date_to = txFilters.value.date_to;
        }
        if (txFilters.value.type) {
            params.type = txFilters.value.type;
        }

        const response = await axios.get(route('accounts.transactions', selectedAccount.value.id), { params });
        transactions.value = response.data.transactions;
    } catch {
        errorMessage.value = 'Erreur lors du chargement des transactions.';
        errorSnackbar.value = true;
    } finally {
        txLoading.value = false;
    }
};

const openTransactionsDialog = (account) => {
    selectedAccount.value = account;
    transactions.value = [];
    txFilters.value = { date_from: '', date_to: '', type: null };
    transactionsDialog.value = true;
    fetchTransactions();
};

const applyTransactionFilters = () => fetchTransactions();
</script>
