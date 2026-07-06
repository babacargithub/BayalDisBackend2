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
                    <v-btn color="deep-purple" variant="tonal" @click="openAllDettesDialog">
                        <v-icon start>mdi-handshake-outline</v-icon>
                        Dettes Inter-compte
                    </v-btn>
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

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <!-- Merchandises -->
                    <div class="rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon icon="mdi-shopping-outline" size="20" color="white" />
                            </div>
                            <span class="text-sm font-semibold text-blue-100 uppercase tracking-wide">Marchandises</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatAmount(balanceSummary.merchandise_sales_balance) }}
                        </div>
                        <div class="mt-2 text-xs text-blue-200">Compte ventes marchandises</div>
                    </div>

                    <!-- Compte Bénéfice -->
                    <div class="rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon icon="mdi-trending-up" size="20" color="white" />
                            </div>
                            <span class="text-sm font-semibold text-emerald-100 uppercase tracking-wide">Compte Bénéfice</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatAmount(balanceSummary.profit_account_balance) }}
                        </div>
                        <div class="mt-2 text-xs text-emerald-200">Bénéfices accumulés</div>
                    </div>

                    <!-- Réserves -->
                    <div class="rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon icon="mdi-piggy-bank-outline" size="20" color="white" />
                            </div>
                            <span class="text-sm font-semibold text-amber-100 uppercase tracking-wide">Réserves</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatAmount(balanceSummary.reserves_balance) }}
                        </div>
                        <div class="mt-2 text-xs text-amber-200">Tous les autres comptes de charges</div>
                    </div>

                    <!-- Total Non Utilisable -->
                    <div class="rounded-2xl bg-gradient-to-br from-violet-500 to-violet-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon icon="mdi-lock-outline" size="20" color="white" />
                            </div>
                            <span class="text-sm font-semibold text-violet-100 uppercase tracking-wide">Total Non Utilisable</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatAmount(balanceSummary.total_non_utilisable) }}
                        </div>
                        <div class="mt-2 text-xs text-violet-200">Bénéfice + Réserves</div>
                    </div>
                </div>

                <v-card>
                    <v-data-table
                        :headers="tableHeaders"
                        :items="accounts"
                        :group-by="[{ key: 'vehicle_id' }]"
                        v-model:opened="openedVehicleGroups"
                        :sort-by="[{ key: 'balance', order: 'desc' }]"
                        items-per-page="25"
                        class="elevation-0"
                    >
                        <!-- Vehicle group header (null = no vehicle → render nothing so those rows appear normally) -->
                        <template #group-header="{ item, columns, toggleGroup, isGroupOpen }">
                            <tr
                                v-if="item.value !== null"
                                class="cursor-pointer select-none"
                                style="background: linear-gradient(to right, #e8eaf6, #ede7f6);"
                                @click="toggleGroup(item)"
                            >
                                <td :colspan="columns.length" class="pa-0">
                                    <div class="flex items-center gap-3 px-4 py-3">
                                        <v-icon
                                            :icon="isGroupOpen(item) ? 'mdi-chevron-down' : 'mdi-chevron-right'"
                                            color="deep-purple"
                                            size="20"
                                        />
                                        <v-icon color="deep-purple" size="18">mdi-truck-outline</v-icon>
                                        <span class="font-semibold text-deep-purple-darken-2 text-sm">
                                            {{ item.items[0].raw.linked_to }}
                                        </span>
                                        <v-chip size="x-small" color="deep-purple" variant="tonal">
                                            {{ item.items.length }} compte{{ item.items.length > 1 ? 's' : '' }}
                                        </v-chip>
                                        <v-spacer />
                                        <span class="font-bold text-deep-purple-darken-2">
                                            {{ formatAmount(vehicleGroupTotals[item.value] ?? 0) }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </template>

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
                                    icon="mdi-hand-coin-outline"
                                    variant="text"
                                    color="warning"
                                    size="small"
                                    title="Dettes & Emprunts"
                                    @click="openDebtDialog(item)"
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

        <!-- ── Debt & Borrow Dialog ────────────────────────────────────── -->
        <v-dialog v-model="debtDialog" max-width="680px" scrollable>
            <v-card v-if="debtDialogAccount">
                <v-card-title class="pa-6 pb-2">
                    <div class="flex justify-between items-start">
                        <div>
                            <div class="text-h6">Dettes & Emprunts</div>
                            <div class="text-body-2 text-grey-darken-1 mt-1">
                                {{ debtDialogAccount.name }}
                                — Solde : <strong>{{ formatAmount(debtDialogAccount.balance) }}</strong>
                            </div>
                        </div>
                        <v-btn variant="text" icon="mdi-close" @click="debtDialog = false" />
                    </div>
                </v-card-title>

                <!-- Totals summary -->
                <div class="flex gap-4 px-6 pb-4" v-if="!debtDebtsLoading">
                    <div class="flex-1 rounded-lg bg-orange-50 border border-orange-200 px-4 py-3">
                        <div class="text-xs text-orange-600 font-semibold uppercase tracking-wide mb-1">Total Dettes</div>
                        <div class="text-lg font-bold text-orange-700">{{ formatAmount(totalOutstandingOwed) }}</div>
                        <div class="text-xs text-orange-500 mt-0.5">Ce compte doit rembourser</div>
                    </div>
                    <div class="flex-1 rounded-lg bg-blue-50 border border-blue-200 px-4 py-3">
                        <div class="text-xs text-blue-600 font-semibold uppercase tracking-wide mb-1">Total Créances</div>
                        <div class="text-lg font-bold text-blue-700">{{ formatAmount(totalOutstandingCreances) }}</div>
                        <div class="text-xs text-blue-500 mt-0.5">Ce compte doit recevoir</div>
                    </div>
                </div>

                <v-tabs v-model="debtActiveTab" color="warning" class="px-4">
                    <v-tab value="borrow">
                        <v-icon start>mdi-bank-minus</v-icon>
                        Emprunter
                    </v-tab>
                    <v-tab value="repay">
                        <v-icon start>mdi-bank-plus</v-icon>
                        Rembourser
                        <v-chip
                            v-if="debtsAsDebtor.length > 0"
                            size="x-small"
                            color="warning"
                            class="ml-2"
                        >
                            {{ debtsAsDebtor.length }}
                        </v-chip>
                    </v-tab>
                    <v-tab value="lent">
                        <v-icon start>mdi-bank-check</v-icon>
                        Prêts accordés
                        <v-chip
                            v-if="debtsAsCreditor.length > 0"
                            size="x-small"
                            color="info"
                            class="ml-2"
                        >
                            {{ debtsAsCreditor.length }}
                        </v-chip>
                    </v-tab>
                </v-tabs>

                <v-divider />

                <v-card-text class="pa-0" style="min-height: 320px;">
                    <v-tabs-window v-model="debtActiveTab">

                        <!-- ── TAB: Borrow ── -->
                        <v-tabs-window-item value="borrow" class="pa-6">
                            <v-alert
                                type="info"
                                variant="tonal"
                                density="compact"
                                class="mb-5 text-body-2"
                            >
                                Les fonds seront prélevés des comptes prêteurs et crédités sur
                                <strong>{{ debtDialogAccount.name }}</strong>.
                                Une dette est créée pour chaque ligne.
                            </v-alert>

                            <v-text-field
                                v-model="borrowReason"
                                label="Motif de l'emprunt *"
                                variant="outlined"
                                density="compact"
                                class="mb-4"
                                placeholder="Ex : Achat marchandises — solde insuffisant"
                            />

                            <!-- Borrow lines -->
                            <div
                                v-for="(borrowLine, borrowLineIndex) in borrowLines"
                                :key="borrowLineIndex"
                                class="flex gap-3 items-start mb-3"
                            >
                                <v-autocomplete
                                    v-model="borrowLine.creditor_account_id"
                                    :items="availableCreditorAccountsForLine(borrowLineIndex)"
                                    item-title="displayName"
                                    item-value="id"
                                    label="Compte prêteur *"
                                    variant="outlined"
                                    density="compact"
                                    no-data-text="Aucun compte avec solde disponible"
                                    style="flex: 1;"
                                    @update:model-value="autoFillBorrowAmount(borrowLine)"
                                />
                                <v-text-field
                                    v-model.number="borrowLine.amount"
                                    label="Montant (F CFA) *"
                                    type="number"
                                    min="1"
                                    :max="creditorAvailableBalance(borrowLine.creditor_account_id)"
                                    variant="outlined"
                                    density="compact"
                                    :hint="creditorBalanceHint(borrowLine.creditor_account_id)"
                                    :rules="[v => !borrowLine.creditor_account_id || !v || v <= creditorAvailableBalance(borrowLine.creditor_account_id) || `Maximum : ${formatAmount(creditorAvailableBalance(borrowLine.creditor_account_id))}`]"
                                    persistent-hint
                                    style="max-width: 200px;"
                                    @update:model-value="clampBorrowAmount(borrowLine)"
                                />
                                <v-btn
                                    v-if="borrowLines.length > 1"
                                    icon="mdi-close"
                                    variant="text"
                                    color="error"
                                    size="small"
                                    class="mt-1"
                                    @click="removeBorrowLine(borrowLineIndex)"
                                />
                            </div>

                            <!-- Balance preview summary -->
                            <div v-if="borrowLinesTotalAmount > 0" class="flex gap-3 mb-4 mt-1">
                                <div class="flex-1 rounded-lg bg-blue-50 border border-blue-200 px-3 py-2 text-center">
                                    <div class="text-xs text-blue-500 font-semibold uppercase tracking-wide mb-0.5">Solde actuel</div>
                                    <div class="text-sm font-bold text-blue-700">{{ formatAmount(debtDialogAccount.balance) }}</div>
                                </div>
                                <div class="flex items-center text-grey-darken-1 font-bold text-lg">+</div>
                                <div class="flex-1 rounded-lg bg-orange-50 border border-orange-200 px-3 py-2 text-center">
                                    <div class="text-xs text-orange-500 font-semibold uppercase tracking-wide mb-0.5">Total emprunté</div>
                                    <div class="text-sm font-bold text-orange-700">{{ formatAmount(borrowLinesTotalAmount) }}</div>
                                </div>
                                <div class="flex items-center text-grey-darken-1 font-bold text-lg">=</div>
                                <div class="flex-1 rounded-lg bg-green-50 border border-green-200 px-3 py-2 text-center">
                                    <div class="text-xs text-green-600 font-semibold uppercase tracking-wide mb-0.5">Solde après emprunt</div>
                                    <div class="text-sm font-bold text-green-700">{{ formatAmount(debtDialogAccount.balance + borrowLinesTotalAmount) }}</div>
                                </div>
                            </div>

                            <v-btn
                                variant="tonal"
                                color="warning"
                                size="small"
                                class="mb-4"
                                @click="addBorrowLine"
                            >
                                <v-icon start>mdi-plus</v-icon>
                                Ajouter un compte prêteur
                            </v-btn>

                            <div class="flex justify-end gap-2 pt-2">
                                <v-btn variant="text" color="error" @click="debtDialog = false">Annuler</v-btn>
                                <v-btn
                                    color="warning"
                                    :loading="borrowSubmitting"
                                    :disabled="!borrowFormIsValid"
                                    @click="submitBorrow"
                                >
                                    <v-icon start>mdi-bank-minus</v-icon>
                                    Confirmer l'emprunt
                                </v-btn>
                            </div>
                        </v-tabs-window-item>

                        <!-- ── TAB: Repay ── -->
                        <v-tabs-window-item value="repay" class="pa-6">
                            <div v-if="debtDebtsLoading" class="text-center py-10 text-grey-darken-1">
                                Chargement des dettes…
                            </div>

                            <div v-else-if="debtsAsDebtor.length === 0" class="text-center py-10 text-grey-darken-1">
                                <v-icon size="48" color="success" class="mb-3">mdi-check-circle-outline</v-icon>
                                <div>Aucune dette en cours pour ce compte.</div>
                            </div>

                            <div v-else>
                                <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                                    <div class="text-body-2 text-grey-darken-1">
                                        Total restant dû :
                                        <strong class="text-warning">{{ formatAmount(totalOutstandingOwed) }}</strong>
                                        — Solde disponible :
                                        <strong>{{ formatAmount(debtDialogAccount.balance) }}</strong>
                                    </div>
                                    <v-btn
                                        size="small"
                                        variant="tonal"
                                        color="grey"
                                        @click="toggleSelectAllDebts"
                                    >
                                        {{ selectedDebtIds.length === debtsAsDebtor.length ? 'Tout déselectionner' : 'Tout sélectionner' }}
                                    </v-btn>
                                </div>

                                <v-card
                                    v-for="debtItem in debtsAsDebtor"
                                    :key="debtItem.id"
                                    :variant="selectedDebtIds.includes(debtItem.id) ? 'tonal' : 'outlined'"
                                    :color="selectedDebtIds.includes(debtItem.id) ? 'success' : undefined"
                                    class="mb-3 cursor-pointer"
                                    @click="toggleDebtSelection(debtItem)"
                                >
                                    <v-card-text class="pa-4">
                                        <div class="flex gap-3 items-start">
                                            <v-checkbox
                                                :model-value="selectedDebtIds.includes(debtItem.id)"
                                                color="success"
                                                hide-details
                                                density="compact"
                                                class="mt-0 flex-shrink-0"
                                                @click.stop="toggleDebtSelection(debtItem)"
                                            />
                                            <div class="flex-1 min-w-0">
                                                <div class="flex justify-between items-start flex-wrap gap-2">
                                                    <div>
                                                        <div class="text-body-2 font-semibold">
                                                            {{ debtItem.creditor_account_name }}
                                                        </div>
                                                        <div class="text-caption text-grey-darken-1 mt-1">
                                                            {{ debtItem.reason }}
                                                        </div>
                                                        <div class="text-caption text-grey-darken-1">
                                                            Créé le {{ formatDate(debtItem.created_at) }}
                                                        </div>
                                                    </div>
                                                    <div class="text-right flex-shrink-0">
                                                        <v-chip
                                                            :color="debtStatusColor(debtItem.status)"
                                                            size="small"
                                                            variant="tonal"
                                                            class="mb-1"
                                                        >
                                                            {{ debtItem.status_label }}
                                                        </v-chip>
                                                        <div class="text-caption text-grey-darken-1">
                                                            Emprunté : {{ formatAmount(debtItem.original_amount) }}
                                                        </div>
                                                        <div class="text-body-2 font-semibold text-warning">
                                                            Restant : {{ formatAmount(debtItem.remaining_amount) }}
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Amount field shown only when selected -->
                                                <div
                                                    v-if="selectedDebtIds.includes(debtItem.id)"
                                                    class="mt-3 pt-3 border-t"
                                                    @click.stop
                                                >
                                                    <v-text-field
                                                        v-model.number="repayAmounts[debtItem.id]"
                                                        label="Montant à rembourser (F CFA)"
                                                        type="number"
                                                        min="1"
                                                        :max="maxRepayableAmountForDebt(debtItem)"
                                                        variant="outlined"
                                                        density="compact"
                                                        :hint="`Max : ${formatAmount(maxRepayableAmountForDebt(debtItem))}`"
                                                        :rules="[v => !v || v <= maxRepayableAmountForDebt(debtItem) || `Maximum : ${formatAmount(maxRepayableAmountForDebt(debtItem))}`]"
                                                        persistent-hint
                                                        @update:model-value="clampRepayAmount(debtItem)"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>

                                <!-- Selection summary + submit -->
                                <div
                                    v-if="selectedDebtIds.length > 0"
                                    class="mt-4 pt-4 border-t flex justify-between items-center flex-wrap gap-3"
                                >
                                    <div class="text-body-2">
                                        <span class="text-grey-darken-1">{{ selectedDebtIds.length }} dette(s) sélectionnée(s) — Total :</span>
                                        <strong class="text-success ml-1">{{ formatAmount(repaySelectionTotalAmount) }}</strong>
                                    </div>
                                    <v-btn
                                        color="success"
                                        :loading="bulkRepaySubmitting"
                                        :disabled="!bulkRepayFormIsValid"
                                        @click="submitBulkRepay"
                                    >
                                        <v-icon start>mdi-bank-plus</v-icon>
                                        Rembourser la sélection
                                    </v-btn>
                                </div>
                            </div>
                        </v-tabs-window-item>

                        <!-- ── TAB: Lent ── -->
                        <v-tabs-window-item value="lent" class="pa-6">
                            <div v-if="debtDebtsLoading" class="text-center py-10 text-grey-darken-1">
                                Chargement…
                            </div>

                            <div v-else-if="debtsAsCreditor.length === 0" class="text-center py-10 text-grey-darken-1">
                                <v-icon size="48" color="grey" class="mb-3">mdi-hand-coin-outline</v-icon>
                                <div>Ce compte n'a pas de prêts en cours.</div>
                            </div>

                            <v-card
                                v-for="debtItem in debtsAsCreditor"
                                :key="debtItem.id"
                                variant="outlined"
                                class="mb-3"
                            >
                                <v-card-text class="pa-4">
                                    <div class="flex justify-between items-start flex-wrap gap-2">
                                        <div>
                                            <div class="text-body-2 font-semibold">
                                                Prêté à : {{ debtItem.debtor_account_name }}
                                            </div>
                                            <div class="text-caption text-grey-darken-1 mt-1">
                                                {{ debtItem.reason }}
                                            </div>
                                            <div class="text-caption text-grey-darken-1">
                                                Créé le {{ formatDate(debtItem.created_at) }}
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <v-chip
                                                :color="debtStatusColor(debtItem.status)"
                                                size="small"
                                                variant="tonal"
                                                class="mb-1"
                                            >
                                                {{ debtItem.status_label }}
                                            </v-chip>
                                            <div class="text-caption text-grey-darken-1">
                                                Prêté : {{ formatAmount(debtItem.original_amount) }}
                                            </div>
                                            <div class="text-body-2 font-semibold text-info">
                                                À récupérer : {{ formatAmount(debtItem.remaining_amount) }}
                                            </div>
                                        </div>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-tabs-window-item>

                    </v-tabs-window>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- ── Global Dettes Inter-compte Dialog ─────────────────────────── -->
        <v-dialog v-model="allDettesDialog" max-width="860px" scrollable>
            <v-card>
                <v-card-title class="pa-6 pb-2 flex items-center gap-2">
                    <v-icon color="deep-purple">mdi-handshake-outline</v-icon>
                    <span class="text-h6">Dettes Inter-compte</span>
                </v-card-title>
                <v-card-text class="pa-6 pt-2">
                    <div v-if="allDettesLoading" class="flex justify-center py-10">
                        <v-progress-circular indeterminate color="deep-purple" />
                    </div>
                    <template v-else>
                        <v-alert
                            v-if="allDettes.length === 0"
                            type="success"
                            variant="tonal"
                            icon="mdi-check-circle"
                            class="mb-4"
                        >
                            Aucune dette en cours entre les comptes.
                        </v-alert>
                        <v-data-table
                            v-else
                            :headers="allDettesTableHeaders"
                            :items="allDettes"
                            density="compact"
                            class="rounded-lg border"
                        >
                            <template #item.debtor_account_name="{ item }">
                                <span class="font-medium text-error">{{ item.debtor_account_name }}</span>
                            </template>

                            <template #item.creditor_account_name="{ item }">
                                <span class="font-medium text-success">{{ item.creditor_account_name }}</span>
                            </template>

                            <template #item.original_amount="{ item }">
                                {{ formatAmount(item.original_amount) }}
                            </template>

                            <template #item.remaining_amount="{ item }">
                                <span class="font-semibold text-error">
                                    {{ formatAmount(item.remaining_amount) }}
                                </span>
                            </template>

                            <template #item.status_label="{ item }">
                                <v-chip
                                    :color="debtStatusColor(item.status)"
                                    size="small"
                                    variant="tonal"
                                >
                                    {{ item.status_label }}
                                </v-chip>
                            </template>

                            <template #item.actions="{ item }">
                                <v-btn
                                    size="small"
                                    color="deep-purple"
                                    variant="tonal"
                                    @click="openGlobalRepayDialog(item)"
                                >
                                    <v-icon start size="16">mdi-cash-refund</v-icon>
                                    Rembourser
                                </v-btn>
                            </template>

                            <template #no-data>
                                Aucune dette trouvée
                            </template>
                        </v-data-table>
                    </template>
                </v-card-text>
                <v-card-actions class="pa-6 pt-0">
                    <v-spacer />
                    <v-btn color="deep-purple" variant="flat" @click="allDettesDialog = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Global Repay Debt Dialog ───────────────────────────────────── -->
        <v-dialog v-model="globalRepayDialog" max-width="460px" persistent>
            <v-card v-if="globalRepayDebt">
                <v-card-title class="text-h6 pa-6 pb-2">Remboursement de dette</v-card-title>
                <v-card-text class="pa-6 pt-2">
                    <div class="rounded-lg bg-grey-lighten-4 pa-4 mb-4">
                        <div class="flex justify-between text-body-2 mb-2">
                            <span class="text-medium-emphasis">Débiteur :</span>
                            <span class="font-semibold text-error">{{ globalRepayDebt.debtor_account_name }}</span>
                        </div>
                        <div class="flex justify-between text-body-2 mb-2">
                            <span class="text-medium-emphasis">Créditeur :</span>
                            <span class="font-semibold text-success">{{ globalRepayDebt.creditor_account_name }}</span>
                        </div>
                        <div class="flex justify-between text-body-2 mb-2">
                            <span class="text-medium-emphasis">Motif :</span>
                            <span class="font-medium">{{ globalRepayDebt.reason }}</span>
                        </div>
                        <div class="flex justify-between text-body-2">
                            <span class="text-medium-emphasis">Restant dû :</span>
                            <span class="font-bold text-error">{{ formatAmount(globalRepayDebt.remaining_amount) }}</span>
                        </div>
                    </div>

                    <v-text-field
                        v-model.number="globalRepayAmount"
                        label="Montant à rembourser (F CFA)"
                        type="number"
                        :min="1"
                        :max="globalRepayDebt.remaining_amount"
                        variant="outlined"
                        :hint="`Maximum : ${formatAmount(globalRepayDebt.remaining_amount)}`"
                        persistent-hint
                        :error-messages="globalRepayAmount > globalRepayDebt.remaining_amount ? ['Dépasse le montant restant'] : []"
                    />

                    <v-alert
                        v-if="globalRepayError"
                        type="error"
                        variant="tonal"
                        class="mt-3"
                        :text="globalRepayError"
                    />
                </v-card-text>
                <v-card-actions class="pa-6 pt-0">
                    <v-spacer />
                    <v-btn variant="text" :disabled="globalRepaySubmitting" @click="globalRepayDialog = false">
                        Annuler
                    </v-btn>
                    <v-btn
                        color="deep-purple"
                        variant="flat"
                        :loading="globalRepaySubmitting"
                        :disabled="!globalRepayAmount || globalRepayAmount <= 0 || globalRepayAmount > globalRepayDebt.remaining_amount"
                        @click="submitGlobalRepay"
                    >
                        Confirmer le remboursement
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

                <!-- Summary totals -->
                <div class="flex gap-4 px-6 pb-2">
                    <div class="flex-1 rounded-lg bg-green-50 border border-green-200 px-4 py-3">
                        <div class="text-xs text-green-600 font-semibold uppercase tracking-wide mb-1">Total Dépôts</div>
                        <div class="text-lg font-bold text-green-700">{{ formatAmount(txTotalDeposits) }}</div>
                    </div>
                    <div class="flex-1 rounded-lg bg-red-50 border border-red-200 px-4 py-3">
                        <div class="text-xs text-red-600 font-semibold uppercase tracking-wide mb-1">Total Retraits</div>
                        <div class="text-lg font-bold text-red-700">{{ formatAmount(txTotalWithdrawals) }}</div>
                    </div>
                    <div class="flex-1 rounded-lg bg-blue-50 border border-blue-200 px-4 py-3">
                        <div class="text-xs text-blue-600 font-semibold uppercase tracking-wide mb-1">Solde Actuel</div>
                        <div class="text-lg font-bold text-blue-700">{{ formatAmount(selectedAccount.balance) }}</div>
                    </div>
                </div>

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
    balanceSummary: Object,
    vehicles: Array,
    commercials: Array,
    accountTypes: Array,
    vehicleGroupTotals: Object,
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
    { title: 'Actions', key: 'actions', sortable: false, align: 'center', minWidth: '160px' },
];

// Vehicle groups start collapsed; the null-vehicle group (standalone accounts) is always open.
const openedVehicleGroups = ref([null]);

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
const txTotalDeposits = ref(0);
const txTotalWithdrawals = ref(0);

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
        txTotalDeposits.value = response.data.total_deposits;
        txTotalWithdrawals.value = response.data.total_withdrawals;
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
    txTotalDeposits.value = 0;
    txTotalWithdrawals.value = 0;
    txFilters.value = { date_from: '', date_to: '', type: null };
    transactionsDialog.value = true;
    fetchTransactions();
};

const applyTransactionFilters = () => fetchTransactions();

// ── Debt & Borrow dialog ───────────────────────────────────────────────────

const debtDialog = ref(false);
const debtDialogAccount = ref(null);
const debtActiveTab = ref('borrow');
const debtDebtsLoading = ref(false);

const debtsAsDebtor = ref([]);
const debtsAsCreditor = ref([]);
const totalOutstandingOwed = ref(0);
const totalOutstandingCreances = ref(0);

// ── Borrow form state ──────────────────────────────────────────────────────

const borrowReason = ref('');
const borrowLines = ref([{ creditor_account_id: null, amount: null }]);
const borrowSubmitting = ref(false);

const availableCreditorAccountsForLine = (currentLineIndex) => {
    if (!debtDialogAccount.value) {
        return [];
    }
    const accountIdsAlreadySelectedInOtherLines = borrowLines.value
        .filter((_, lineIndex) => lineIndex !== currentLineIndex)
        .map((borrowLine) => borrowLine.creditor_account_id)
        .filter(Boolean);

    return props.accounts
        .filter(
            (account) =>
                account.id !== debtDialogAccount.value.id &&
                account.balance > 0 &&
                !accountIdsAlreadySelectedInOtherLines.includes(account.id)
        )
        .map((account) => ({
            ...account,
            displayName: `${account.name} — ${formatAmount(account.balance)}`,
        }));
};

const creditorAvailableBalance = (creditorAccountId) => {
    if (!creditorAccountId) {
        return 0;
    }
    return props.accounts.find((account) => account.id === creditorAccountId)?.balance ?? 0;
};

const creditorBalanceHint = (creditorAccountId) => {
    const balance = creditorAvailableBalance(creditorAccountId);
    return creditorAccountId ? `Solde disponible : ${formatAmount(balance)}` : '';
};

const clampBorrowAmount = (borrowLine) => {
    const maxBalance = creditorAvailableBalance(borrowLine.creditor_account_id);
    if (maxBalance > 0 && borrowLine.amount > maxBalance) {
        borrowLine.amount = maxBalance;
    }
};

const autoFillBorrowAmount = (borrowLine) => {
    borrowLine.amount = creditorAvailableBalance(borrowLine.creditor_account_id) || null;
};

const borrowLinesTotalAmount = computed(() =>
    borrowLines.value.reduce((total, borrowLine) => total + (borrowLine.amount || 0), 0)
);

const borrowFormIsValid = computed(() => {
    if (!borrowReason.value.trim()) {
        return false;
    }
    return borrowLines.value.every((borrowLine) => {
        if (!borrowLine.creditor_account_id || !borrowLine.amount || borrowLine.amount <= 0) {
            return false;
        }
        const maxBalance = creditorAvailableBalance(borrowLine.creditor_account_id);
        return borrowLine.amount <= maxBalance;
    });
});

const addBorrowLine = () => {
    borrowLines.value.push({ creditor_account_id: null, amount: null });
};

const removeBorrowLine = (borrowLineIndex) => {
    borrowLines.value.splice(borrowLineIndex, 1);
};

const resetBorrowForm = () => {
    borrowReason.value = '';
    borrowLines.value = [{ creditor_account_id: null, amount: null }];
};

const submitBorrow = async () => {
    borrowSubmitting.value = true;

    try {
        for (const borrowLine of borrowLines.value) {
            await axios.post(route('account-debts.borrow'), {
                debtor_account_id: debtDialogAccount.value.id,
                creditor_account_id: borrowLine.creditor_account_id,
                amount: borrowLine.amount,
                reason: borrowReason.value,
            });
        }

        successMessage.value = 'Emprunt(s) enregistré(s) avec succès.';
        successSnackbar.value = true;

        debtDialog.value = false;
        resetBorrowForm();
        router.reload({ preserveScroll: true });
    } catch (error) {
        const serverErrorMessage = error.response?.data?.message
            ?? error.response?.data?.error
            ?? 'Erreur lors de l\'enregistrement de l\'emprunt.';
        errorMessage.value = serverErrorMessage;
        errorSnackbar.value = true;
    } finally {
        borrowSubmitting.value = false;
    }
};

// ── Repay form state ───────────────────────────────────────────────────────

const selectedDebtIds = ref([]);
const repayAmounts = ref({});
const bulkRepaySubmitting = ref(false);

/**
 * Amount already committed to other selected debts (excluding the given debt).
 */
const repayAmountAllocatedToOtherDebts = (debtItem) => {
    return selectedDebtIds.value
        .filter((selectedId) => selectedId !== debtItem.id)
        .reduce((sum, selectedId) => sum + (repayAmounts.value[selectedId] || 0), 0);
};

/**
 * Maximum repayable for a single debt: the smaller of the remaining debt
 * and the account balance minus what is already committed to other selected debts.
 */
const maxRepayableAmountForDebt = (debtItem) => {
    const availableBalance =
        (debtDialogAccount.value?.balance ?? 0) - repayAmountAllocatedToOtherDebts(debtItem);
    return Math.max(0, Math.min(debtItem.remaining_amount, availableBalance));
};

const clampRepayAmount = (debtItem) => {
    const maxAmount = maxRepayableAmountForDebt(debtItem);
    if ((repayAmounts.value[debtItem.id] || 0) > maxAmount) {
        repayAmounts.value[debtItem.id] = maxAmount;
    }
};

const toggleDebtSelection = (debtItem) => {
    const index = selectedDebtIds.value.indexOf(debtItem.id);
    if (index === -1) {
        selectedDebtIds.value.push(debtItem.id);
        repayAmounts.value[debtItem.id] = maxRepayableAmountForDebt(debtItem);
    } else {
        selectedDebtIds.value.splice(index, 1);
        delete repayAmounts.value[debtItem.id];
    }
};

const toggleSelectAllDebts = () => {
    if (selectedDebtIds.value.length === debtsAsDebtor.value.length) {
        selectedDebtIds.value = [];
        repayAmounts.value = {};
    } else {
        selectedDebtIds.value = [];
        repayAmounts.value = {};
        for (const debtItem of debtsAsDebtor.value) {
            selectedDebtIds.value.push(debtItem.id);
            repayAmounts.value[debtItem.id] = maxRepayableAmountForDebt(debtItem);
        }
    }
};

const repaySelectionTotalAmount = computed(() =>
    selectedDebtIds.value.reduce((sum, debtId) => sum + (repayAmounts.value[debtId] || 0), 0)
);

const bulkRepayFormIsValid = computed(() =>
    selectedDebtIds.value.length > 0 &&
    selectedDebtIds.value.every(
        (debtId) => repayAmounts.value[debtId] > 0
    )
);

const submitBulkRepay = async () => {
    bulkRepaySubmitting.value = true;

    try {
        for (const debtId of selectedDebtIds.value) {
            await axios.post(route('account-debts.repay', debtId), {
                amount: repayAmounts.value[debtId],
            });
        }

        successMessage.value = `${selectedDebtIds.value.length} remboursement(s) enregistré(s) avec succès.`;
        successSnackbar.value = true;

        selectedDebtIds.value = [];
        repayAmounts.value = {};

        await fetchOutstandingDebts(debtDialogAccount.value);
        router.reload({ preserveScroll: true });
    } catch (error) {
        const serverErrorMessage = error.response?.data?.message
            ?? error.response?.data?.error
            ?? 'Erreur lors du remboursement.';
        errorMessage.value = serverErrorMessage;
        errorSnackbar.value = true;
    } finally {
        bulkRepaySubmitting.value = false;
    }
};

// ── Fetch outstanding debts ────────────────────────────────────────────────

const fetchOutstandingDebts = async (account) => {
    debtDebtsLoading.value = true;

    try {
        const response = await axios.get(route('account-debts.outstanding', account.id));
        debtsAsDebtor.value = response.data.debts_as_debtor;
        debtsAsCreditor.value = response.data.debts_as_creditor;
        totalOutstandingOwed.value = response.data.total_outstanding_owed;
        totalOutstandingCreances.value = response.data.total_outstanding_creances;
    } catch {
        errorMessage.value = 'Erreur lors du chargement des dettes.';
        errorSnackbar.value = true;
    } finally {
        debtDebtsLoading.value = false;
    }
};

const openDebtDialog = (account) => {
    debtDialogAccount.value = account;
    debtActiveTab.value = 'borrow';
    debtsAsDebtor.value = [];
    debtsAsCreditor.value = [];
    totalOutstandingOwed.value = 0;
    totalOutstandingCreances.value = 0;
    selectedDebtIds.value = [];
    repayAmounts.value = {};
    resetBorrowForm();
    debtDialog.value = true;
    fetchOutstandingDebts(account);
};

const debtStatusColor = (status) => {
    const colorMap = {
        PENDING: 'warning',
        PARTIALLY_REPAID: 'orange',
        FULLY_REPAID: 'success',
    };
    return colorMap[status] ?? 'grey';
};

// ── Global all-debts dialog ────────────────────────────────────────────────

const allDettesDialog = ref(false);
const allDettesLoading = ref(false);
const allDettes = ref([]);

const allDettesTableHeaders = [
    { title: 'Le compte', key: 'debtor_account_name', sortable: true },
    { title: 'Doit à', key: 'creditor_account_name', sortable: true },
    { title: 'Montant initial', key: 'original_amount', sortable: true, align: 'end' },
    { title: 'Restant dû', key: 'remaining_amount', sortable: true, align: 'end' },
    { title: 'Statut', key: 'status_label', sortable: false },
    { title: 'Actions', key: 'actions', sortable: false, align: 'center' },
];

const openAllDettesDialog = async () => {
    allDettesDialog.value = true;
    allDettesLoading.value = true;
    allDettes.value = [];
    try {
        const response = await axios.get(route('account-debts.outstanding-all'));
        allDettes.value = response.data.debts;
    } catch {
        errorMessage.value = 'Erreur lors du chargement des dettes.';
        errorSnackbar.value = true;
    } finally {
        allDettesLoading.value = false;
    }
};

// ── Global repay dialog ────────────────────────────────────────────────────

const globalRepayDialog = ref(false);
const globalRepayDebt = ref(null);
const globalRepayAmount = ref(null);
const globalRepaySubmitting = ref(false);
const globalRepayError = ref(null);

const openGlobalRepayDialog = (debt) => {
    globalRepayDebt.value = debt;
    globalRepayAmount.value = debt.remaining_amount;
    globalRepayError.value = null;
    globalRepayDialog.value = true;
};

const submitGlobalRepay = async () => {
    globalRepaySubmitting.value = true;
    globalRepayError.value = null;
    try {
        await axios.post(route('account-debts.repay', globalRepayDebt.value.id), {
            amount: globalRepayAmount.value,
        });
        globalRepayDialog.value = false;
        successMessage.value = 'Remboursement enregistré avec succès.';
        successSnackbar.value = true;
        await openAllDettesDialog();
        router.reload({ preserveScroll: true });
    } catch (error) {
        globalRepayError.value = error.response?.data?.message ?? 'Une erreur inattendue est survenue.';
    } finally {
        globalRepaySubmitting.value = false;
    }
};
</script>
