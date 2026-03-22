<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Commissions</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-tabs v-model="activeTab" color="primary" class="mb-6">
                    <v-tab value="general">Général</v-tab>
                    <v-tab value="nouveaux-clients">Nouveaux clients</v-tab>
                    <v-tab value="commerciaux">Commerciaux</v-tab>
                </v-tabs>

                <!-- ── TAB: GÉNÉRAL ── -->
                <v-tabs-window v-model="activeTab">
                    <v-tabs-window-item value="general">
                        <v-card>
                            <v-card-title class="d-flex align-center justify-space-between">
                                <span>Taux par catégorie de produit</span>
                                <v-btn color="primary" size="small" @click="openRateDialog(null, null)">
                                    <v-icon size="small" class="mr-1">mdi-plus</v-icon>
                                    Ajouter un taux
                                </v-btn>
                            </v-card-title>
                            <v-card-subtitle class="pb-2">
                                Priorité : taux spécifique commercial × produit &gt; taux commercial × catégorie &gt; taux par défaut de la catégorie.
                                Un taux à 0.01 = 1 %. Les taux par défaut des catégories se gèrent dans la page <strong>Catégories</strong>.
                            </v-card-subtitle>

                            <!-- Matrix table: rows = commercials, cols = categories -->
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>Commercial</th>
                                        <th
                                            v-for="category in productCategories"
                                            :key="category.id"
                                            class="text-center"
                                        >
                                            {{ category.name }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Default category rates row -->
                                    <tr class="bg-grey-lighten-4">
                                        <td class="text-caption text-grey font-italic">Taux par défaut catégorie</td>
                                        <td
                                            v-for="category in productCategories"
                                            :key="category.id"
                                            class="text-center"
                                        >
                                            <v-chip
                                                v-if="category.commission_rate != null"
                                                color="grey"
                                                size="x-small"
                                                variant="tonal"
                                            >
                                                {{ formatRate(category.commission_rate) }}
                                            </v-chip>
                                            <span v-else class="text-grey text-caption">—</span>
                                        </td>
                                    </tr>

                                    <!-- Per-commercial override rows -->
                                    <tr
                                        v-for="commercial in commerciaux"
                                        :key="commercial.id"
                                    >
                                        <td class="font-weight-medium">{{ commercial.name }}</td>
                                        <td
                                            v-for="category in productCategories"
                                            :key="category.id"
                                            class="text-center"
                                        >
                                            <template v-if="getCategoryRate(commercial.id, category.id)">
                                                <v-chip
                                                    color="primary"
                                                    size="x-small"
                                                    variant="tonal"
                                                    class="cursor-pointer mr-1"
                                                    @click="openRateDialog(commercial, category)"
                                                >
                                                    {{ formatRate(getCategoryRate(commercial.id, category.id).rate) }}
                                                </v-chip>
                                                <v-btn
                                                    icon="mdi-delete"
                                                    size="x-small"
                                                    variant="text"
                                                    color="error"
                                                    @click="deleteRate(getCategoryRate(commercial.id, category.id))"
                                                />
                                            </template>
                                            <span
                                                v-else
                                                class="text-grey text-caption cursor-pointer"
                                                :title="category.commission_rate != null ? 'Hérite : ' + formatRate(category.commission_rate) : 'Aucun taux'"
                                                @click="openRateDialog(commercial, category)"
                                            >
                                                {{ category.commission_rate != null ? '↳ ' + formatRate(category.commission_rate) : '—' }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="commerciaux.length === 0">
                                        <td colspan="999" class="text-center text-grey py-4">
                                            Aucun commercial enregistré
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card>
                    </v-tabs-window-item>

                    <!-- ── TAB: NOUVEAUX CLIENTS ── -->
                    <v-tabs-window-item value="nouveaux-clients">
                        <v-card>
                            <v-card-title>Bonus nouveaux clients par commercial</v-card-title>
                            <v-card-subtitle class="pb-2">
                                Montant fixe (XOF) attribué par nouveau client créé dans la journée. Séparé par type : client confirmé (is_prospect = false) et prospect (is_prospect = true).
                            </v-card-subtitle>

                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>Commercial</th>
                                        <th class="text-right">Bonus client confirmé (XOF)</th>
                                        <th class="text-right">Bonus prospect (XOF)</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="commercial in commerciaux"
                                        :key="commercial.id"
                                    >
                                        <td class="font-weight-medium">{{ commercial.name }}</td>
                                        <td class="text-right">
                                            {{ formatCurrency(getNewCustomerSetting(commercial.id).confirmed_customer_bonus) }}
                                        </td>
                                        <td class="text-right">
                                            {{ formatCurrency(getNewCustomerSetting(commercial.id).prospect_customer_bonus) }}
                                        </td>
                                        <td class="text-center">
                                            <v-btn
                                                icon="mdi-pencil"
                                                size="x-small"
                                                variant="text"
                                                color="primary"
                                                @click="openNewCustomerSettingDialog(commercial)"
                                            />
                                        </td>
                                    </tr>
                                    <tr v-if="commerciaux.length === 0">
                                        <td colspan="4" class="text-center text-grey py-4">
                                            Aucun commercial enregistré
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card>
                    </v-tabs-window-item>

                    <!-- ── TAB: COMMERCIAUX ── -->
                    <v-tabs-window-item value="commerciaux">
                        <div class="d-flex justify-space-between align-center mb-4">
                            <v-select
                                v-model="filterCommercialId"
                                :items="commerciaux"
                                item-title="name"
                                item-value="id"
                                label="Filtrer par commercial"
                                clearable
                                hide-details
                                style="max-width: 300px"
                            />
                            <v-btn color="primary" @click="openWorkPeriodDialog">
                                <v-icon class="mr-1">mdi-plus</v-icon>
                                Nouvelle période
                            </v-btn>
                        </div>

                        <v-expansion-panels variant="accordion">
                            <v-expansion-panel
                                v-for="workPeriod in filteredWorkPeriods"
                                :key="workPeriod.id"
                            >
                                <v-expansion-panel-title>
                                    <div class="d-flex align-center justify-space-between w-100 pr-4">
                                        <div>
                                            <span class="font-weight-bold">{{ workPeriod.commercial_name }}</span>
                                            <span class="text-grey ml-3">
                                                {{ formatDate(workPeriod.period_start_date) }}
                                                →
                                                {{ formatDate(workPeriod.period_end_date) }}
                                            </span>
                                        </div>
                                        <div class="d-flex align-center gap-2">
                                            <v-chip
                                                v-if="workPeriod.is_finalized"
                                                color="success"
                                                size="small"
                                                prepend-icon="mdi-lock"
                                            >
                                                Finalisée
                                            </v-chip>
                                            <v-chip
                                                v-else-if="workPeriod.daily_commissions.length > 0"
                                                color="blue"
                                                size="small"
                                                prepend-icon="mdi-calculator"
                                            >
                                                En cours
                                            </v-chip>
                                            <v-chip v-else color="grey" size="small" variant="tonal">
                                                En attente
                                            </v-chip>
                                            <span
                                                v-if="workPeriod.daily_commissions.length > 0"
                                                class="font-weight-bold text-primary"
                                            >
                                                {{ formatCurrency(periodNetTotal(workPeriod)) }}
                                            </span>
                                        </div>
                                    </div>
                                </v-expansion-panel-title>

                                <v-expansion-panel-text>
                                    <v-row>
                                        <!-- Daily commission breakdown -->
                                        <v-col cols="12" md="6">
                                            <div class="text-subtitle-2 mb-2">Commissions journalières</div>
                                            <template v-if="workPeriod.daily_commissions.length > 0">
                                                <v-table density="compact">
                                                    <thead>
                                                        <tr>
                                                            <th>Jour</th>
                                                            <th class="text-right">Base</th>
                                                            <th class="text-right">Basket</th>
                                                            <th class="text-right">Objectif</th>
                                                            <th class="text-right">Nvx clients</th>
                                                            <th class="text-right text-error">Pénalités</th>
                                                            <th class="text-right">Seuil</th>
                                                            <th class="text-right font-weight-bold">Net</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr
                                                            v-for="dailyCommission in workPeriod.daily_commissions"
                                                            :key="dailyCommission.id"
                                                        >
                                                            <td>
                                                                {{ formatDate(dailyCommission.work_day) }}
                                                                <v-chip
                                                                    v-if="dailyCommission.basket_achieved"
                                                                    color="success"
                                                                    size="x-small"
                                                                    class="ml-1"
                                                                    title="Bonus panier atteint"
                                                                >
                                                                    🧺
                                                                </v-chip>
                                                            </td>
                                                            <td class="text-right">{{ formatCurrency(dailyCommission.base_commission) }}</td>
                                                            <td class="text-right">{{ formatCurrency(dailyCommission.basket_bonus) }}</td>
                                                            <td class="text-right">
                                                                {{ formatCurrency(dailyCommission.objective_bonus) }}
                                                                <span
                                                                    v-if="dailyCommission.achieved_tier_level"
                                                                    class="text-caption text-grey"
                                                                >
                                                                    (P{{ dailyCommission.achieved_tier_level }})
                                                                </span>
                                                            </td>
                                                            <td class="text-right">
                                                                <span
                                                                    v-if="dailyCommission.new_confirmed_customers_bonus > 0 || dailyCommission.new_prospect_customers_bonus > 0"
                                                                    :title="`Confirmés : ${formatCurrency(dailyCommission.new_confirmed_customers_bonus)} · Prospects : ${formatCurrency(dailyCommission.new_prospect_customers_bonus)}`"
                                                                >
                                                                    {{ formatCurrency(dailyCommission.new_confirmed_customers_bonus + dailyCommission.new_prospect_customers_bonus) }}
                                                                </span>
                                                                <span v-else class="text-grey text-caption">—</span>
                                                            </td>
                                                            <td class="text-right text-error">
                                                                {{ dailyCommission.total_penalties > 0 ? '− ' + formatCurrency(dailyCommission.total_penalties) : '—' }}
                                                            </td>
                                                            <td class="text-right">
                                                                <template v-if="dailyCommission.mandatory_daily_threshold > 0">
                                                                    <v-chip
                                                                        :color="dailyCommission.mandatory_threshold_reached ? 'success' : 'error'"
                                                                        size="x-small"
                                                                        :title="dailyCommission.mandatory_threshold_reached ? 'Seuil atteint' : 'Seuil non atteint'"
                                                                    >
                                                                        {{ formatCurrency(dailyCommission.mandatory_daily_threshold) }}
                                                                    </v-chip>
                                                                </template>
                                                                <span v-else class="text-grey text-caption">—</span>
                                                            </td>
                                                            <td class="text-right font-weight-bold text-primary">
                                                                {{ formatCurrency(dailyCommission.net_commission) }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="bg-grey-lighten-4 font-weight-bold">
                                                            <td>Total période</td>
                                                            <td class="text-right">{{ formatCurrency(periodBaseTotal(workPeriod)) }}</td>
                                                            <td class="text-right">{{ formatCurrency(periodBasketTotal(workPeriod)) }}</td>
                                                            <td class="text-right">{{ formatCurrency(periodObjectiveTotal(workPeriod)) }}</td>
                                                            <td class="text-right">{{ formatCurrency(periodNewCustomerBonusTotal(workPeriod)) }}</td>
                                                            <td class="text-right text-error">
                                                                {{ periodPenaltiesTotal(workPeriod) > 0 ? '− ' + formatCurrency(periodPenaltiesTotal(workPeriod)) : '—' }}
                                                            </td>
                                                            <td></td>
                                                            <td class="text-right text-primary">{{ formatCurrency(periodNetTotal(workPeriod)) }}</td>
                                                        </tr>
                                                    </tfoot>
                                                </v-table>
                                            </template>
                                            <div v-else class="text-grey text-caption">
                                                Aucune commission calculée. Les commissions se calculent automatiquement après chaque paiement.
                                            </div>

                                            <!-- Action buttons -->
                                            <div class="d-flex gap-2 mt-3 flex-wrap">
                                                <v-btn
                                                    v-if="!workPeriod.is_finalized"
                                                    color="primary"
                                                    size="small"
                                                    variant="tonal"
                                                    :loading="computingId === workPeriod.id"
                                                    @click="computeCommission(workPeriod)"
                                                >
                                                    <v-icon size="small" class="mr-1">mdi-calculator</v-icon>
                                                    Recalculer tous les jours
                                                </v-btn>
                                                <v-btn
                                                    v-if="workPeriod.daily_commissions.length > 0 && !workPeriod.is_finalized"
                                                    color="warning"
                                                    size="small"
                                                    variant="tonal"
                                                    @click="openFinalizeDialog(workPeriod)"
                                                >
                                                    <v-icon size="small" class="mr-1">mdi-lock</v-icon>
                                                    Finaliser
                                                </v-btn>
                                                <v-btn
                                                    v-if="!workPeriod.is_finalized"
                                                    color="error"
                                                    size="small"
                                                    variant="text"
                                                    @click="openDeleteWorkPeriodDialog(workPeriod)"
                                                >
                                                    <v-icon size="small">mdi-delete</v-icon>
                                                </v-btn>
                                            </div>
                                        </v-col>

                                        <!-- Objective tiers -->
                                        <v-col cols="12" md="3">
                                            <div class="d-flex align-center justify-space-between mb-2">
                                                <span class="text-subtitle-2">Paliers objectif CA</span>
                                                <v-btn
                                                    v-if="!workPeriod.is_finalized"
                                                    icon="mdi-plus"
                                                    size="x-small"
                                                    color="primary"
                                                    variant="tonal"
                                                    @click="openTierDialog(workPeriod)"
                                                />
                                            </div>
                                            <v-table v-if="workPeriod.objective_tiers.length > 0" density="compact">
                                                <thead>
                                                    <tr>
                                                        <th>Palier</th>
                                                        <th class="text-right">Seuil CA</th>
                                                        <th class="text-right">Bonus</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="tier in workPeriod.objective_tiers" :key="tier.id">
                                                        <td>{{ tier.tier_level }}</td>
                                                        <td class="text-right">{{ formatCurrency(tier.ca_threshold) }}</td>
                                                        <td class="text-right">{{ formatCurrency(tier.bonus_amount) }}</td>
                                                        <td>
                                                            <v-btn
                                                                v-if="!workPeriod.is_finalized"
                                                                icon="mdi-delete"
                                                                size="x-small"
                                                                variant="text"
                                                                color="error"
                                                                @click="deleteTier(tier)"
                                                            />
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </v-table>
                                            <div v-else class="text-grey text-caption">Aucun palier défini.</div>
                                        </v-col>

                                        <!-- Penalties -->
                                        <v-col cols="12" md="3">
                                            <div class="d-flex align-center justify-space-between mb-2">
                                                <span class="text-subtitle-2">Pénalités</span>
                                                <v-btn
                                                    v-if="!workPeriod.is_finalized"
                                                    icon="mdi-plus"
                                                    size="x-small"
                                                    color="error"
                                                    variant="tonal"
                                                    @click="openPenaltyDialog(workPeriod)"
                                                />
                                            </div>
                                            <div v-if="workPeriod.penalties.length > 0">
                                                <div
                                                    v-for="penalty in workPeriod.penalties"
                                                    :key="penalty.id"
                                                    class="d-flex align-center justify-space-between mb-1"
                                                >
                                                    <div>
                                                        <div class="text-error font-weight-medium">− {{ formatCurrency(penalty.amount) }}</div>
                                                        <div class="text-caption text-grey">
                                                            {{ penalty.work_day ? formatDate(penalty.work_day) + ' — ' : '' }}{{ penalty.reason }}
                                                        </div>
                                                    </div>
                                                    <v-btn
                                                        v-if="!workPeriod.is_finalized"
                                                        icon="mdi-delete"
                                                        size="x-small"
                                                        variant="text"
                                                        color="error"
                                                        @click="deletePenalty(penalty)"
                                                    />
                                                </div>
                                            </div>
                                            <div v-else class="text-grey text-caption">Aucune pénalité.</div>
                                        </v-col>
                                    </v-row>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>

                        <v-card v-if="filteredWorkPeriods.length === 0" class="mt-2">
                            <v-card-text class="text-center text-grey py-8">
                                Aucune période de travail enregistrée
                            </v-card-text>
                        </v-card>
                    </v-tabs-window-item>
                </v-tabs-window>
            </div>
        </div>

        <!-- Dialog: New customer commission setting -->
        <v-dialog v-model="newCustomerSettingDialog" max-width="440px" persistent>
            <v-card>
                <v-card-title>Bonus nouveaux clients — {{ newCustomerSettingForm.commercial_name }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model.number="newCustomerSettingForm.confirmed_customer_bonus"
                        label="Bonus client confirmé (XOF)"
                        type="number"
                        min="0"
                        suffix="XOF"
                        required
                        :error-messages="newCustomerSettingForm.errors.confirmed_customer_bonus"
                        hint="Montant par nouveau client confirmé créé dans la journée"
                        persistent-hint
                    />
                    <v-text-field
                        v-model.number="newCustomerSettingForm.prospect_customer_bonus"
                        label="Bonus prospect (XOF)"
                        type="number"
                        min="0"
                        suffix="XOF"
                        required
                        class="mt-2"
                        :error-messages="newCustomerSettingForm.errors.prospect_customer_bonus"
                        hint="Montant par nouveau prospect créé dans la journée"
                        persistent-hint
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="newCustomerSettingDialog = false">Annuler</v-btn>
                    <v-btn color="primary" :loading="newCustomerSettingForm.processing" @click="saveNewCustomerSetting">
                        Enregistrer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Set category rate -->
        <v-dialog v-model="rateDialog" max-width="440px" persistent>
            <v-card>
                <v-card-title>Taux de commission</v-card-title>
                <v-card-text>
                    <v-select
                        v-model="rateForm.commercial_id"
                        :items="commerciaux"
                        item-title="name"
                        item-value="id"
                        label="Commercial"
                        required
                        :error-messages="rateForm.errors.commercial_id"
                    />
                    <v-select
                        v-model="rateForm.product_category_id"
                        :items="productCategories"
                        item-title="name"
                        item-value="id"
                        label="Catégorie de produit"
                        required
                        :error-messages="rateForm.errors.product_category_id"
                    />
                    <v-text-field
                        v-model.number="rateForm.rate"
                        label="Taux (ex: 0.01 = 1 %)"
                        type="number"
                        step="0.0001"
                        min="0"
                        max="1"
                        required
                        :error-messages="rateForm.errors.rate"
                        hint="0.01 = 1 % · 0.05 = 5 %"
                        persistent-hint
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="rateDialog = false">Annuler</v-btn>
                    <v-btn color="primary" :loading="rateForm.processing" @click="saveRate">
                        Enregistrer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: New work period -->
        <v-dialog v-model="workPeriodDialog" max-width="480px" persistent>
            <v-card>
                <v-card-title>Nouvelle période de travail</v-card-title>
                <v-card-text>
                    <v-select
                        v-model="workPeriodForm.commercial_id"
                        :items="commerciaux"
                        item-title="name"
                        item-value="id"
                        label="Commercial"
                        required
                        :error-messages="workPeriodForm.errors.commercial_id"
                    />
                    <v-row>
                        <v-col cols="6">
                            <v-text-field
                                v-model="workPeriodForm.period_start_date"
                                label="Date de début"
                                type="date"
                                required
                                :error-messages="workPeriodForm.errors.period_start_date"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="workPeriodForm.period_end_date"
                                label="Date de fin"
                                type="date"
                                required
                                :error-messages="workPeriodForm.errors.period_end_date"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="workPeriodDialog = false">Annuler</v-btn>
                    <v-btn color="primary" :loading="workPeriodForm.processing" @click="saveWorkPeriod">
                        Créer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Add objective tier -->
        <v-dialog v-model="tierDialog" max-width="420px" persistent>
            <v-card>
                <v-card-title>Ajouter un palier objectif</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model.number="tierForm.tier_level"
                        label="Niveau du palier"
                        type="number"
                        min="1"
                        required
                        :error-messages="tierForm.errors.tier_level"
                    />
                    <v-text-field
                        v-model.number="tierForm.ca_threshold"
                        label="Seuil CA (XOF)"
                        type="number"
                        min="0"
                        suffix="XOF"
                        required
                        :error-messages="tierForm.errors.ca_threshold"
                    />
                    <v-text-field
                        v-model.number="tierForm.bonus_amount"
                        label="Montant du bonus"
                        type="number"
                        min="0"
                        suffix="XOF"
                        required
                        :error-messages="tierForm.errors.bonus_amount"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="tierDialog = false">Annuler</v-btn>
                    <v-btn color="primary" :loading="tierForm.processing" @click="saveTier">
                        Ajouter
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Add penalty -->
        <v-dialog v-model="penaltyDialog" max-width="420px" persistent>
            <v-card>
                <v-card-title>Ajouter une pénalité</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="penaltyForm.work_day"
                        label="Jour concerné"
                        type="date"
                        required
                        :error-messages="penaltyForm.errors.work_day"
                        :min="penaltyTargetWorkPeriod?.period_start_date"
                        :max="penaltyTargetWorkPeriod?.period_end_date"
                    />
                    <v-text-field
                        v-model.number="penaltyForm.amount"
                        label="Montant"
                        type="number"
                        min="1"
                        suffix="XOF"
                        required
                        :error-messages="penaltyForm.errors.amount"
                    />
                    <v-textarea
                        v-model="penaltyForm.reason"
                        label="Motif"
                        rows="2"
                        required
                        :error-messages="penaltyForm.errors.reason"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="penaltyDialog = false">Annuler</v-btn>
                    <v-btn color="primary" :loading="penaltyForm.processing" @click="savePenalty">
                        Ajouter
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Finalize work period -->
        <v-dialog v-model="finalizeDialog" max-width="460px">
            <v-card>
                <v-card-title>Finaliser la période</v-card-title>
                <v-card-text>
                    <p>
                        Finaliser la période de
                        <strong>{{ workPeriodToFinalize?.commercial_name }}</strong>
                        :
                        <strong>{{ formatDate(workPeriodToFinalize?.period_start_date) }} → {{ formatDate(workPeriodToFinalize?.period_end_date) }}</strong>
                        ?
                    </p>
                    <p class="mt-2">
                        Commission nette totale :
                        <strong class="text-primary">{{ workPeriodToFinalize ? formatCurrency(periodNetTotal(workPeriodToFinalize)) : '—' }}</strong>
                    </p>
                    <v-alert type="warning" variant="tonal" class="mt-3">
                        Cette action est <strong>irréversible</strong>. Les commissions ne pourront plus être recalculées automatiquement.
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="finalizeDialog = false">Annuler</v-btn>
                    <v-btn color="warning" :loading="finalizeForm.processing" @click="confirmFinalize">
                        Finaliser
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Delete work period -->
        <v-dialog v-model="deleteWorkPeriodDialog" max-width="460px">
            <v-card>
                <v-card-title>Supprimer la période</v-card-title>
                <v-card-text>
                    Supprimer la période
                    <strong>{{ formatDate(workPeriodToDelete?.period_start_date) }} → {{ formatDate(workPeriodToDelete?.period_end_date) }}</strong>
                    de <strong>{{ workPeriodToDelete?.commercial_name }}</strong> ?
                    Cette action supprimera aussi les paliers, pénalités et les commissions journalières associées.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteWorkPeriodDialog = false">Annuler</v-btn>
                    <v-btn color="error" :loading="deleteWorkPeriodForm.processing" @click="confirmDeleteWorkPeriod">
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    productCategories: { type: Array, default: () => [] },
    commerciaux: { type: Array, default: () => [] },
    categoryRates: { type: Array, default: () => [] },
    workPeriods: { type: Array, default: () => [] },
    newCustomerCommissionSettings: { type: Array, default: () => [] },
});

const activeTab = ref('general');
const filterCommercialId = ref(null);
const computingId = ref(null);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatCurrency(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount ?? 0) + ' XOF';
}

function formatRate(rate) {
    return (rate * 100).toFixed(2).replace(/\.?0+$/, '') + ' %';
}

function formatDate(dateString) {
    if (!dateString) return '';
    return new Intl.DateTimeFormat('fr-FR').format(new Date(dateString));
}

function getCategoryRate(commercialId, categoryId) {
    return props.categoryRates.find(
        (rate) => rate.commercial_id === commercialId && rate.product_category_id === categoryId,
    ) ?? null;
}

// ─── Period totals (computed from daily_commissions array) ────────────────────

function periodBaseTotal(workPeriod) {
    return workPeriod.daily_commissions.reduce((sum, dc) => sum + dc.base_commission, 0);
}

function periodBasketTotal(workPeriod) {
    return workPeriod.daily_commissions.reduce((sum, dc) => sum + dc.basket_bonus, 0);
}

function periodObjectiveTotal(workPeriod) {
    return workPeriod.daily_commissions.reduce((sum, dc) => sum + dc.objective_bonus, 0);
}

function periodPenaltiesTotal(workPeriod) {
    return workPeriod.daily_commissions.reduce((sum, dc) => sum + dc.total_penalties, 0);
}

function periodNewCustomerBonusTotal(workPeriod) {
    return workPeriod.daily_commissions.reduce(
        (sum, dc) => sum + (dc.new_confirmed_customers_bonus ?? 0) + (dc.new_prospect_customers_bonus ?? 0),
        0,
    );
}

function periodNetTotal(workPeriod) {
    return workPeriod.daily_commissions.reduce((sum, dc) => sum + dc.net_commission, 0);
}

// ─── New customer commission settings helpers ─────────────────────────────────

function getNewCustomerSetting(commercialId) {
    return props.newCustomerCommissionSettings.find((s) => s.commercial_id === commercialId)
        ?? { confirmed_customer_bonus: 0, prospect_customer_bonus: 0 };
}

// ─── Filter ───────────────────────────────────────────────────────────────────

const filteredWorkPeriods = computed(() => {
    if (!filterCommercialId.value) return props.workPeriods;
    return props.workPeriods.filter((wp) => wp.commercial_id === filterCommercialId.value);
});

// ─── New customer commission setting dialog ───────────────────────────────────

const newCustomerSettingDialog = ref(false);
const newCustomerSettingForm = useForm({
    commercial_id: null,
    commercial_name: '',
    confirmed_customer_bonus: 0,
    prospect_customer_bonus: 0,
});

function openNewCustomerSettingDialog(commercial) {
    const existingSetting = getNewCustomerSetting(commercial.id);
    newCustomerSettingForm.commercial_id = commercial.id;
    newCustomerSettingForm.commercial_name = commercial.name;
    newCustomerSettingForm.confirmed_customer_bonus = existingSetting.confirmed_customer_bonus;
    newCustomerSettingForm.prospect_customer_bonus = existingSetting.prospect_customer_bonus;
    newCustomerSettingDialog.value = true;
}

function saveNewCustomerSetting() {
    newCustomerSettingForm.post(route('commissions.new-customer-settings.upsert'), {
        onSuccess: () => { newCustomerSettingDialog.value = false; },
    });
}

// ─── Category rate dialog ─────────────────────────────────────────────────────

const rateDialog = ref(false);
const rateForm = useForm({ commercial_id: null, product_category_id: null, rate: 0 });

function openRateDialog(commercial, category) {
    rateForm.commercial_id = commercial?.id ?? null;
    rateForm.product_category_id = category?.id ?? null;
    const existingRate = commercial && category ? getCategoryRate(commercial.id, category.id) : null;
    rateForm.rate = existingRate?.rate ?? 0;
    rateDialog.value = true;
}

function saveRate() {
    rateForm.post(route('commissions.category-rates.upsert'), {
        onSuccess: () => { rateDialog.value = false; },
    });
}

function deleteRate(rateRecord) {
    router.delete(route('commissions.category-rates.destroy', rateRecord.id), {
        preserveScroll: true,
    });
}

// ─── Work period dialog ───────────────────────────────────────────────────────

const workPeriodDialog = ref(false);
const workPeriodForm = useForm({
    commercial_id: null,
    period_start_date: '',
    period_end_date: '',
});

function openWorkPeriodDialog() {
    workPeriodForm.reset();
    workPeriodForm.commercial_id = filterCommercialId.value ?? null;
    workPeriodDialog.value = true;
}

function saveWorkPeriod() {
    workPeriodForm.post(route('commissions.work-periods.store'), {
        onSuccess: () => { workPeriodDialog.value = false; },
    });
}

// ─── Tier dialog ──────────────────────────────────────────────────────────────

const tierDialog = ref(false);
const tierTargetWorkPeriod = ref(null);
const tierForm = useForm({ tier_level: 1, ca_threshold: 0, bonus_amount: 0 });

function openTierDialog(workPeriod) {
    tierTargetWorkPeriod.value = workPeriod;
    tierForm.reset();
    tierForm.tier_level = (workPeriod.objective_tiers.length ?? 0) + 1;
    tierDialog.value = true;
}

function saveTier() {
    tierForm.post(route('commissions.tiers.store', tierTargetWorkPeriod.value.id), {
        onSuccess: () => { tierDialog.value = false; },
    });
}

function deleteTier(tier) {
    router.delete(route('commissions.tiers.destroy', tier.id), { preserveScroll: true });
}

// ─── Penalty dialog ───────────────────────────────────────────────────────────

const penaltyDialog = ref(false);
const penaltyTargetWorkPeriod = ref(null);
const penaltyForm = useForm({ work_day: '', amount: 0, reason: '' });

function openPenaltyDialog(workPeriod) {
    penaltyTargetWorkPeriod.value = workPeriod;
    penaltyForm.reset();
    penaltyForm.work_day = workPeriod.period_start_date;
    penaltyDialog.value = true;
}

function savePenalty() {
    penaltyForm.post(route('commissions.penalties.store', penaltyTargetWorkPeriod.value.id), {
        onSuccess: () => { penaltyDialog.value = false; },
    });
}

function deletePenalty(penalty) {
    router.delete(route('commissions.penalties.destroy', penalty.id), { preserveScroll: true });
}

// ─── Compute commission ───────────────────────────────────────────────────────

function computeCommission(workPeriod) {
    computingId.value = workPeriod.id;
    router.post(
        route('commissions.work-periods.compute', workPeriod.id),
        {},
        { preserveScroll: true, onFinish: () => { computingId.value = null; } },
    );
}

// ─── Finalize dialog ──────────────────────────────────────────────────────────

const finalizeDialog = ref(false);
const workPeriodToFinalize = ref(null);
const finalizeForm = useForm({});

function openFinalizeDialog(workPeriod) {
    workPeriodToFinalize.value = workPeriod;
    finalizeDialog.value = true;
}

function confirmFinalize() {
    finalizeForm.post(route('commissions.work-periods.finalize', workPeriodToFinalize.value.id), {
        onSuccess: () => { finalizeDialog.value = false; },
    });
}

// ─── Delete work period ───────────────────────────────────────────────────────

const deleteWorkPeriodDialog = ref(false);
const workPeriodToDelete = ref(null);
const deleteWorkPeriodForm = useForm({});

function openDeleteWorkPeriodDialog(workPeriod) {
    workPeriodToDelete.value = workPeriod;
    deleteWorkPeriodDialog.value = true;
}

function confirmDeleteWorkPeriod() {
    deleteWorkPeriodForm.delete(route('commissions.work-periods.destroy', workPeriodToDelete.value.id), {
        onSuccess: () => { deleteWorkPeriodDialog.value = false; },
    });
}
</script>
