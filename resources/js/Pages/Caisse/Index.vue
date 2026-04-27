<template>
    <Head title="Caisses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap justify-between items-center gap-2">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Gestion des Caisses</h2>
                <div class="flex flex-wrap gap-2">
                    <v-btn color="error" variant="tonal" @click="openSortieDeCaisseDialog">
                        <v-icon start>mdi-cash-minus</v-icon>
                        <span class="hidden sm:inline">Sortie de caisse</span>
                    </v-btn>
                    <v-btn color="secondary" @click="openTransferDialog">
                        <v-icon start>mdi-bank-transfer</v-icon>
                        <span class="hidden sm:inline">Transfert</span>
                    </v-btn>
                    <v-btn color="primary" @click="openDialog()">
                        <v-icon start>mdi-plus</v-icon>
                        <span class="hidden sm:inline">Nouvelle Caisse</span>
                    </v-btn>
                </div>
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

        <div class="py-4 sm:py-8 lg:py-12">
            <div class="mx-auto px-4 sm:px-6 lg:px-8 max-w-7xl">

                <!-- Balance Summary -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-5 mb-4 sm:mb-6">
                    <!-- Total Caisses -->
                    <div class="rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon icon="mdi-cash-multiple" size="20" color="white" />
                            </div>
                            <span class="text-sm font-semibold text-blue-100 uppercase tracking-wide">Solde
                              Caisses</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatAmount(totalCaissesBalance) }}
                        </div>
                        <div class="mt-2 text-xs text-blue-200">Argent physique disponible</div>
                    </div>

                    <!-- Total Comptes -->
                    <div class="rounded-2xl bg-gradient-to-br from-violet-500 to-violet-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon icon="mdi-bank-outline" size="20" color="white" />
                            </div>
                            <span class="text-sm font-semibold text-violet-100 uppercase tracking-wide">Solde
                              Comptes</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatAmount(totalAccountsBalance) }}
                        </div>
                        <div class="mt-2 text-xs text-violet-200">Origine de la trésorerie</div>
                    </div>

                    <!-- Difference -->
                    <div
                        class="rounded-2xl p-5 text-white shadow-lg"
                        :class="balanceDifference >= 0
                            ? 'bg-gradient-to-br from-emerald-500 to-emerald-700'
                            : 'bg-gradient-to-br from-red-500 to-red-700'"
                    >
                        <div class="flex items-center gap-3 mb-4">
                            <div class="rounded-xl bg-white/20 p-2 flex items-center justify-center">
                                <v-icon
                                    :icon="balanceDifference === 0 ? 'mdi-check-circle' : balanceDifference > 0 ? 'mdi-trending-up' : 'mdi-trending-down'"
                                    size="20"
                                    color="white"
                                />
                            </div>
                            <span
                                class="text-sm font-semibold uppercase tracking-wide"
                                :class="balanceDifference >= 0 ? 'text-emerald-100' : 'text-red-100'"
                            >
                                Différence
                            </span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            <template v-if="balanceDifference === 0">Équilibré</template>
                            <template v-else-if="balanceDifference > 0">+{{ formatAmount(balanceDifference) }}</template>
                            <template v-else>{{ formatAmount(balanceDifference) }}</template>
                        </div>
                        <div
                            class="mt-2 text-xs font-medium"
                            :class="balanceDifference >= 0 ? 'text-emerald-200' : 'text-red-200'"
                        >
                            <template v-if="balanceDifference === 0">Caisses et comptes sont équilibrés</template>
                            <template v-else-if="balanceDifference > 0">Surplus en caisse</template>
                            <template v-else>Déficit en caisse — argent manquant</template>
                        </div>
                    </div>
                </div>

                <!-- Reconciliation Calculator Button -->
                <div class="flex justify-end mb-3">
                    <v-btn
                        color="teal"
                        variant="tonal"
                        @click="reconciliationDialog = true"
                    >
                        <v-icon start>mdi-calculator-variant</v-icon>
                        Vérification physique
                    </v-btn>
                </div>

                <v-card class="overflow-x-auto">
                    <v-data-table
                        :headers="[
                            { title: 'Nom', key: 'name' },
                            { title: 'Solde', key: 'balance' },
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
                                <v-tooltip
                                    :text="item.balance !== 0 ? 'Solde doit être à zéro pour supprimer' : 'Supprimer'"
                                    location="top"
                                >
                                    <template v-slot:activator="{ props: tooltipProps }">
                                        <span v-bind="tooltipProps">
                                            <v-btn
                                                icon="mdi-delete"
                                                variant="text"
                                                color="error"
                                                :disabled="item.balance !== 0"
                                                @click="openDeleteDialog(item)"
                                            />
                                        </span>
                                    </template>
                                </v-tooltip>
                                <v-btn
                                    icon="mdi-cash-multiple"
                                    variant="text"
                                    color="info"
                                    @click="openTransactionsDialog(item)"
                                    title="Voir les transactions"
                                />
                                <v-btn
                                    v-if="item.caisse_type !== 'COMMERCIAL'"
                                    icon="mdi-plus"
                                    variant="text"
                                    color="success"
                                    @click="openNewTransactionDialog(item)"
                                    title="Nouvelle transaction"
                                />
                                <v-btn
                                    v-if="item.caisse_type === 'COMMERCIAL'"
                                    icon="mdi-lock-clock"
                                    variant="text"
                                    :color="isCaisseLockedToday(item) ? 'grey' : 'warning'"
                                    :disabled="isCaisseLockedToday(item)"
                                    :title="isCaisseLockedToday(item) ? 'Journée déjà clôturée' : 'Clôturer Journée'"
                                    @click="openCloseDayDialog(item)"
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
                            v-if="selectedCaisse.caisse_type !== 'COMMERCIAL'"
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

        <!-- Close Day Confirmation Dialog -->
        <v-dialog v-model="closeDayDialog" max-width="460px">
            <v-card v-if="closeDayCaisse">
                <v-card-title class="text-h6 pt-5 px-6">Clôturer la journée</v-card-title>
                <v-card-text class="px-6">
                    <p>
                        Êtes-vous sûr de vouloir clôturer la journée pour la caisse
                        <strong>{{ closeDayCaisse.name }}</strong> ?
                    </p>
                    <p class="mt-3 text-medium-emphasis text-sm">
                        Cette action :
                    </p>
                    <ul class="mt-1 text-sm text-medium-emphasis list-disc pl-5">
                        <li>Transfère la commission journalière gagnée vers le compte commission du commercial.</li>
                        <li>Bloque tout nouveau paiement sur cette caisse jusqu'à demain.</li>
                    </ul>
                    <v-alert
                        v-if="closeDayError"
                        type="error"
                        variant="tonal"
                        class="mt-4"
                        :text="closeDayError"
                    />
                </v-card-text>
                <v-card-actions class="px-6 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="closeDayDialog = false"
                        :disabled="closeDayLoading"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="warning"
                        variant="flat"
                        :loading="closeDayLoading"
                        @click="confirmCloseDay"
                    >
                        Confirmer la clôture
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Types Dialog -->
        <v-dialog v-model="typeDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Types de dépenses
                </v-card-title>
            </v-card>
        </v-dialog>

        <!-- Transfer Dialog -->
        <v-dialog v-model="transferDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Transfert entre caisses
                </v-card-title>
                <v-card-text>
                    <form @submit.prevent="submitTransfer">
                        <div class="mb-4">
                            <label for="from_caisse_id" class="block text-sm font-medium text-gray-700">Caisse source</label>
                            <select
                                id="from_caisse_id"
                                v-model="transferForm.from_caisse_id"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                required
                            >
                                <option value="">Sélectionner une caisse</option>
                                <option v-for="caisse in caisses" :key="caisse.id" :value="caisse.id">
                                    {{ caisse.name }} ({{ formatAmount(caisse.balance) }} FCFA)
                                </option>
                            </select>
                            <p v-if="transferForm.errors.from_caisse_id" class="mt-1 text-sm text-red-600">
                                {{ transferForm.errors.from_caisse_id }}
                            </p>
                        </div>

                        <div class="mb-4">
                            <label for="to_caisse_id" class="block text-sm font-medium text-gray-700">Caisse destination</label>
                            <select
                                id="to_caisse_id"
                                v-model="transferForm.to_caisse_id"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                required
                            >
                                <option value="">Sélectionner une caisse</option>
                                <option 
                                    v-for="caisse in caisses" 
                                    :key="caisse.id" 
                                    :value="caisse.id"
                                    :disabled="caisse.id === transferForm.from_caisse_id"
                                >
                                    {{ caisse.name }} ({{ formatAmount(caisse.balance) }} FCFA)
                                </option>
                            </select>
                            <p v-if="transferForm.errors.to_caisse_id" class="mt-1 text-sm text-red-600">
                                {{ transferForm.errors.to_caisse_id }}
                            </p>
                        </div>

                        <div class="mb-4">
                            <label for="amount" class="block text-sm font-medium text-gray-700">Montant (FCFA)</label>
                            <input
                                id="amount"
                                v-model="transferForm.amount"
                                type="number"
                                min="1"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                required
                            />
                            <p v-if="transferForm.errors.amount" class="mt-1 text-sm text-red-600">
                                {{ transferForm.errors.amount }}
                            </p>
                        </div>

                        <div class="mb-4">
                            <label for="description" class="block text-sm font-medium text-gray-700">Description (optionnel)</label>
                            <textarea
                                id="description"
                                v-model="transferForm.description"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                rows="2"
                            />
                            <p v-if="transferForm.errors.description" class="mt-1 text-sm text-red-600">
                                {{ transferForm.errors.description }}
                            </p>
                        </div>
                    </form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="transferDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        :loading="transferForm.processing"
                        @click="submitTransfer"
                    >
                        Transférer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <!-- Sortie de caisse dialog -->
        <v-dialog v-model="sortieDeCaisseDialog" max-width="560px">
            <v-card>
                <v-card-title class="text-h6 pt-5 px-6">Sortie de caisse</v-card-title>
                <v-card-text class="px-6">
                    <div class="space-y-4">
                        <!-- Source caisse -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Depuis la caisse</label>
                            <select
                                v-model="sortieForm.caisse_id"
                                class="block w-full rounded-md border border-gray-300 shadow-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">Sélectionner une caisse</option>
                                <option
                                    v-for="caisse in nonCommercialCaisses"
                                    :key="caisse.id"
                                    :value="caisse.id"
                                >
                                    {{ caisse.name }} — {{ formatAmount(caisse.balance) }}
                                </option>
                            </select>
                            <p v-if="sortieForm.errors.caisse_id" class="mt-1 text-sm text-red-600">{{ sortieForm.errors.caisse_id }}</p>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Montant (F CFA)</label>
                            <input
                                v-model.number="sortieForm.amount"
                                type="number"
                                min="1"
                                class="block w-full rounded-md border border-gray-300 shadow-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Ex: 50 000"
                            />
                            <p v-if="sortieForm.errors.amount" class="mt-1 text-sm text-red-600">{{ sortieForm.errors.amount }}</p>
                        </div>

                        <!-- Label -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Libellé</label>
                            <input
                                v-model="sortieForm.label"
                                type="text"
                                class="block w-full rounded-md border border-gray-300 shadow-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Ex: Achat fournitures, Remboursement..."
                            />
                            <p v-if="sortieForm.errors.label" class="mt-1 text-sm text-red-600">{{ sortieForm.errors.label }}</p>
                        </div>

                        <!-- Debited accounts -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Comptes à débiter
                                <span class="text-gray-400 font-normal">(dans l'ordre de sélection)</span>
                            </label>
                            <div v-if="debitableAccounts.length === 0" class="text-sm text-gray-500 italic">
                                Aucun compte avec un solde positif disponible.
                            </div>
                            <div v-else class="divide-y divide-gray-100 border border-gray-200 rounded-md overflow-hidden">
                                <label
                                    v-for="account in debitableAccounts"
                                    :key="account.id"
                                    class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors"
                                    :class="{ 'bg-blue-50': sortieForm.account_ids.includes(account.id) }"
                                >
                                    <input
                                        type="checkbox"
                                        :value="account.id"
                                        v-model="sortieForm.account_ids"
                                        class="rounded border-gray-300 text-blue-600"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <span class="text-sm font-medium text-gray-800">{{ account.name }}</span>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-700 shrink-0">
                                        {{ formatAmount(account.balance) }}
                                    </span>
                                </label>
                            </div>
                            <p v-if="sortieForm.errors.account_ids" class="mt-1 text-sm text-red-600">{{ sortieForm.errors.account_ids }}</p>

                            <!-- Balance coverage feedback -->
                            <div v-if="sortieForm.account_ids.length > 0 && sortieForm.amount > 0" class="mt-3">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-600">Total sélectionné :</span>
                                    <span class="font-semibold">{{ formatAmount(totalSelectedAccountsBalance) }}</span>
                                </div>
                                <div class="flex items-center justify-between text-sm mt-1">
                                    <span class="text-gray-600">Montant sortie :</span>
                                    <span class="font-semibold">{{ formatAmount(sortieForm.amount) }}</span>
                                </div>
                                <v-alert
                                    v-if="totalSelectedAccountsBalance < sortieForm.amount"
                                    type="warning"
                                    variant="tonal"
                                    density="compact"
                                    class="mt-2"
                                    icon="mdi-alert-circle"
                                >
                                    Solde insuffisant — sélectionnez des comptes supplémentaires
                                    (manque {{ formatAmount(sortieForm.amount - totalSelectedAccountsBalance) }}).
                                </v-alert>
                                <v-alert
                                    v-else
                                    type="success"
                                    variant="tonal"
                                    density="compact"
                                    class="mt-2"
                                    icon="mdi-check-circle"
                                >
                                    Les comptes sélectionnés couvrent le montant de la sortie.
                                </v-alert>
                            </div>
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions class="px-6 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="sortieDeCaisseDialog = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="sortieForm.processing"
                        :disabled="!sortieDeCaisseFormIsValid"
                        @click="submitSortieDeCaisse"
                    >
                        Confirmer la sortie
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <!-- Reconciliation Calculator Dialog -->
        <v-dialog v-model="reconciliationDialog" max-width="520px">
            <v-card>
                <v-card-title class="text-h6 pt-5 px-6 flex items-center gap-2">
                    <v-icon color="teal">mdi-calculator-variant</v-icon>
                    Vérification physique des caisses
                </v-card-title>
                <v-card-text class="px-6">
                    <p class="text-sm text-medium-emphasis mb-4">
                        Saisissez les soldes physiques de chaque compte. Le total sera comparé automatiquement au solde des caisses.
                    </p>

                    <div class="space-y-3">
                        <v-text-field
                            v-model.number="physicalAmounts.waveAccount"
                            label="Wave Account"
                            type="number"
                            min="0"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-cellphone-wireless"
                            hide-details
                        />
                        <v-text-field
                            v-model.number="physicalAmounts.waveBusinessAccount"
                            label="Wave Business Account"
                            type="number"
                            min="0"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-store"
                            hide-details
                        />
                        <v-text-field
                            v-model.number="physicalAmounts.orangeMoneyAccount"
                            label="Orange Money Account"
                            type="number"
                            min="0"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-cellphone"
                            hide-details
                        />
                        <v-text-field
                            v-model.number="physicalAmounts.bankAccount"
                            label="Compte bancaire"
                            type="number"
                            min="0"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-bank"
                            hide-details
                        />
                        <v-text-field
                            v-model.number="physicalAmounts.cashInHand"
                            label="Cash"
                            type="number"
                            min="0"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-cash"
                            hide-details
                        />
                        <v-divider class="my-1" />
                        <v-text-field
                            v-model.number="physicalAmounts.totalDebtsOwedToOthers"
                            label="Dettes (argent présent mais dû à d'autres)"
                            type="number"
                            min="0"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-account-arrow-right"
                            color="error"
                            hide-details
                        />
                    </div>

                    <v-divider class="my-4" />

                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between items-center">
                            <span class="text-medium-emphasis">Total brut :</span>
                            <span class="font-semibold">{{ formatAmount(physicalGrossTotal) }}</span>
                        </div>
                        <div v-if="physicalAmounts.totalDebtsOwedToOthers > 0" class="flex justify-between items-center text-error">
                            <span>Dettes à déduire :</span>
                            <span class="font-semibold">− {{ formatAmount(physicalAmounts.totalDebtsOwedToOthers) }}</span>
                        </div>
                        <div class="flex justify-between items-center border-t pt-2">
                            <span class="text-medium-emphasis">Total physique net :</span>
                            <span class="font-semibold text-base">{{ formatAmount(physicalReconciliationTotal) }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-medium-emphasis">Solde caisses (système) :</span>
                            <span class="font-semibold text-base">{{ formatAmount(totalCaissesBalance) }}</span>
                        </div>
                    </div>

                    <v-alert
                        v-if="physicalGrossTotal > 0"
                        :type="reconciliationGap === 0 ? 'success' : 'error'"
                        variant="tonal"
                        class="mt-4"
                        :icon="reconciliationGap === 0 ? 'mdi-check-circle' : 'mdi-alert-circle'"
                    >
                        <template v-if="reconciliationGap === 0">
                            Tout est équilibré — le total physique correspond au solde du système.
                        </template>
                        <template v-else-if="reconciliationGap > 0">
                            Surplus physique de <strong>{{ formatAmount(reconciliationGap) }}</strong> — l'argent physique dépasse le système.
                        </template>
                        <template v-else>
                            Manque physique de <strong>{{ formatAmount(Math.abs(reconciliationGap)) }}</strong> — l'argent physique est inférieur au système.
                        </template>
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-6 pb-5">
                    <v-btn
                        variant="text"
                        color="secondary"
                        @click="resetReconciliationAmounts"
                    >
                        Réinitialiser
                    </v-btn>
                    <v-spacer />
                    <v-btn
                        color="teal"
                        variant="flat"
                        @click="reconciliationDialog = false"
                    >
                        Fermer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    caisses: Array,
    totalCaissesBalance: Number,
    totalAccountsBalance: Number,
    debitableAccounts: Array,
});

const balanceDifference = computed(() => props.totalCaissesBalance - props.totalAccountsBalance);

// ─── Physical reconciliation calculator ───────────────────────────────────
const reconciliationDialog = ref(false);

const physicalAmounts = ref({
    waveAccount: null,
    waveBusinessAccount: null,
    orangeMoneyAccount: null,
    bankAccount: null,
    cashInHand: null,
    totalDebtsOwedToOthers: null,
});

const physicalGrossTotal = computed(() =>
    (physicalAmounts.value.waveAccount || 0) +
    (physicalAmounts.value.waveBusinessAccount || 0) +
    (physicalAmounts.value.orangeMoneyAccount || 0) +
    (physicalAmounts.value.bankAccount || 0) +
    (physicalAmounts.value.cashInHand || 0)
);

const physicalReconciliationTotal = computed(() =>
    physicalGrossTotal.value - (physicalAmounts.value.totalDebtsOwedToOthers || 0)
);

const reconciliationGap = computed(() =>
    physicalReconciliationTotal.value - props.totalCaissesBalance
);

const resetReconciliationAmounts = () => {
    physicalAmounts.value = {
        waveAccount: null,
        waveBusinessAccount: null,
        orangeMoneyAccount: null,
        bankAccount: null,
        cashInHand: null,
        totalDebtsOwedToOthers: null,
    };
};

// ─── Sortie de caisse ──────────────────────────────────────────────────────
const sortieDeCaisseDialog = ref(false);

const sortieForm = useForm({
    caisse_id: '',
    amount: '',
    label: '',
    account_ids: [],
});

const nonCommercialCaisses = computed(() =>
    props.caisses.filter(c => c.caisse_type !== 'COMMERCIAL')
);

const totalSelectedAccountsBalance = computed(() =>
    sortieForm.account_ids.reduce((sum, accountId) => {
        const account = props.debitableAccounts.find(a => a.id === accountId);
        return sum + (account ? account.balance : 0);
    }, 0)
);

const sortieDeCaisseFormIsValid = computed(() =>
    sortieForm.caisse_id !== '' &&
    sortieForm.amount > 0 &&
    sortieForm.label.trim() !== '' &&
    sortieForm.account_ids.length > 0 &&
    totalSelectedAccountsBalance.value >= sortieForm.amount
);

const openSortieDeCaisseDialog = () => {
    sortieForm.reset();
    sortieDeCaisseDialog.value = true;
};

const submitSortieDeCaisse = () => {
    sortieForm.post(route('caisses.sortie-de-caisse'), {
        preserveScroll: true,
        onSuccess: () => {
            sortieDeCaisseDialog.value = false;
            sortieForm.reset();
        },
    });
};

// Close-day state
const closeDayDialog = ref(false);
const closeDayCaisse = ref(null);
const closeDayLoading = ref(false);
const closeDayError = ref(null);

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
const typeDialog = ref(false);
const transferDialog = ref(false);

const transactionForm = useForm({
    amount: null,
    label: ''
});

const transferForm = useForm({
    from_caisse_id: '',
    to_caisse_id: '',
    amount: '',
    description: ''
});

watch(() => flash.value.success, (message) => {
    if (message) {
        snackbar.value = true;
    }
});

const form = useForm({
    name: '',
    closed: false,
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
            transactions: []
        };
        transactionsDialog.value = true;
        
        const response = await axios.get(route('caisses.transactions', caisse.id));
        if (response.data && response.data.transactions) {
            selectedCaisse.value = {
                ...caisse,
                transactions: response.data.transactions
            };
        }
    } catch (error) {
        console.error('Error fetching transactions:', error);
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

  transactionForm.amount = transactionType.value === 'WITHDRAW' ? -Math.abs(transactionForm.amount) : Math.abs(transactionForm.amount);

    transactionForm.post(route('caisses.transactions.store', selectedCaisse.value.id), {
        preserveScroll: true,
        onSuccess: async () => {
            transactionDialog.value = false;
            transactionForm.reset();
            transactionType.value = null;
            
            // Refresh the caisse data
            if (selectedCaisse.value) {
                await openTransactionsDialog(selectedCaisse.value);
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

const openTransferDialog = () => {
    transferForm.reset();
    transferDialog.value = true;
};

const submitTransfer = () => {
    transferForm.post(route('caisses.transfer'), {
        preserveScroll: true,
        onSuccess: () => {
            transferDialog.value = false;
            transferForm.reset();
        }
    });
};

const todayDateString = new Date().toISOString().slice(0, 10);

const isCaisseLockedToday = (caisse) => {
    if (!caisse.locked_until) {
        return false;
    }
    return caisse.locked_until.slice(0, 10) === todayDateString;
};

const openCloseDayDialog = (caisse) => {
    closeDayCaisse.value = caisse;
    closeDayError.value = null;
    closeDayDialog.value = true;
};

const confirmCloseDay = async () => {
    closeDayLoading.value = true;
    closeDayError.value = null;

    try {
        await axios.post(route('caisses.close-day', closeDayCaisse.value.id));

        // Optimistically mark the caisse as locked in the local list.
        const matchingCaisse = props.caisses.find((c) => c.id === closeDayCaisse.value.id);
        if (matchingCaisse) {
            matchingCaisse.locked_until = todayDateString;
        }

        closeDayDialog.value = false;
    } catch (error) {
        closeDayError.value = error.response?.data?.message ?? 'Une erreur inattendue est survenue.';
    } finally {
        closeDayLoading.value = false;
    }
};
</script>