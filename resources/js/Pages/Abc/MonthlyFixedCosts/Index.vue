<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Coûts d'exploitation</h2>
                <v-btn v-if="activeTab === 'fixed'" color="primary" @click="openCreateDialog">
                    <v-icon>mdi-plus</v-icon>
                    Ajouter un coût
                </v-btn>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

                <v-tabs v-model="activeTab" color="primary" class="mb-6">
                    <v-tab value="summary">Récapitulatif</v-tab>
                    <v-tab value="fixed">Coûts fixes mensuels</v-tab>
                    <v-tab value="salaries">Salaires commerciaux</v-tab>
                    <v-tab value="vehicles">Véhicules</v-tab>
                </v-tabs>

                <v-tabs-window v-model="activeTab">

                    <!-- ── TAB: RÉCAPITULATIF ── -->
                    <v-tabs-window-item value="summary">
                        <!-- Period selector -->
                        <v-card class="mb-6">
                            <v-card-text>
                                <v-row align="center">
                                    <v-col cols="12" sm="3">
                                        <v-select
                                            v-model="summaryYear"
                                            :items="selectableYears"
                                            label="Année"
                                            hide-details
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="3">
                                        <v-select
                                            v-model="summaryMonth"
                                            :items="monthItems"
                                            item-title="label"
                                            item-value="value"
                                            label="Mois"
                                            hide-details
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <div class="text-caption text-grey">
                                            Les coûts fixes sont filtrés par période.
                                            Les salaires et coûts véhicules sont les valeurs mensuelles courantes.
                                        </div>
                                    </v-col>
                                </v-row>
                            </v-card-text>
                        </v-card>

                        <!-- Summary cards -->
                        <v-row class="mb-6">
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="blue-lighten-5">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-grey mb-1">Coûts fixes (période)</div>
                                        <div class="text-h6 font-weight-bold text-blue-darken-2">
                                            {{ formatCurrency(summary.fixed_costs_total) }}
                                        </div>
                                        <div class="text-caption text-grey mt-1">{{ summaryFixedCostsForPeriod.length }} entrée(s)</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="green-lighten-5">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-grey mb-1">Salaires commerciaux</div>
                                        <div class="text-h6 font-weight-bold text-green-darken-2">
                                            {{ formatCurrency(summary.commercial_salaries_total) }}
                                        </div>
                                        <div class="text-caption text-grey mt-1">{{ commerciaux.length }} commercial(aux)</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="orange-lighten-5">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-grey mb-1">Coûts véhicules</div>
                                        <div class="text-h6 font-weight-bold text-orange-darken-2">
                                            {{ formatCurrency(summary.vehicle_costs_total) }}
                                        </div>
                                        <div class="text-caption text-grey mt-1">{{ vehicles.length }} véhicule(s)</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="purple-lighten-5">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-grey mb-1">Total général</div>
                                        <div class="text-h6 font-weight-bold text-purple-darken-2">
                                            {{ formatCurrency(summary.grand_total) }}
                                        </div>
                                        <div class="text-caption text-grey mt-1">Tous postes confondus</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Daily cost breakdown cards -->
                        <v-row class="mb-6">
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="blue-darken-1">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-white mb-1" style="opacity:.8">Coûts fixes / jour</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatCurrency(daily.daily_fixed_costs) }}</div>
                                        <div class="text-caption text-white mt-1" style="opacity:.7">÷ {{ daily.average_working_days_per_month }} j/mois</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="green-darken-1">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-white mb-1" style="opacity:.8">Salaires / jour</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatCurrency(daily.daily_commercial_salaries) }}</div>
                                        <div class="text-caption text-white mt-1" style="opacity:.7">÷ {{ daily.average_working_days_per_month }} j/mois</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="orange-darken-1">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-white mb-1" style="opacity:.8">Véhicules / jour</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatCurrency(daily.daily_vehicle_costs) }}</div>
                                        <div class="text-caption text-white mt-1" style="opacity:.7">{{ vehicles.length }} véhicule(s)</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-card color="purple-darken-1">
                                    <v-card-text class="text-center">
                                        <div class="text-caption text-white mb-1" style="opacity:.8">Coût journalier total</div>
                                        <div class="text-h6 font-weight-bold text-white">{{ formatCurrency(daily.daily_total_overall_cost) }}</div>
                                        <div class="text-caption text-white mt-1" style="opacity:.7">Tous postes confondus</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Daily sales required to cover costs -->
                        <v-row class="mb-6">
                            <v-col cols="12">
                                <v-card :color="breakEven.daily_sales_required_to_cover_costs ? 'teal-lighten-5' : 'grey-lighten-4'" border>
                                    <v-card-text>
                                        <v-row align="center">
                                            <v-col cols="12" sm="5" class="text-center">
                                                <div class="text-caption text-grey mb-1">CA journalier minimum pour couvrir les charges</div>
                                                <div v-if="breakEven.daily_sales_required_to_cover_costs" class="text-h5 font-weight-bold text-teal-darken-2">
                                                    {{ formatCurrency(breakEven.daily_sales_required_to_cover_costs) }}
                                                </div>
                                                <div v-else class="text-subtitle-1 text-grey font-weight-medium">
                                                    Données insuffisantes
                                                </div>
                                            </v-col>
                                            <v-divider vertical class="d-none d-sm-flex" />
                                            <v-col cols="12" sm="7">
                                                <v-row dense align="center">
                                                    <v-col cols="6" sm="4" class="text-center">
                                                        <div class="text-caption text-grey">Charges / jour</div>
                                                        <div class="font-weight-medium">{{ formatCurrency(daily.daily_total_overall_cost) }}</div>
                                                    </v-col>
                                                    <v-col cols="6" sm="4" class="text-center">
                                                        <div class="text-caption text-grey">Marge brute moyenne</div>
                                                        <div class="font-weight-medium" :class="breakEven.average_gross_margin_rate ? 'text-teal-darken-1' : 'text-grey'">
                                                            {{ breakEven.average_gross_margin_rate ? formatRate(breakEven.average_gross_margin_rate) : '—' }}
                                                        </div>
                                                    </v-col>
                                                    <v-col cols="12" sm="4" class="text-center">
                                                        <div class="text-caption text-grey">Formule</div>
                                                        <div class="text-caption font-weight-medium text-grey-darken-1">charges ÷ marge</div>
                                                    </v-col>
                                                </v-row>
                                                <div v-if="!breakEven.average_gross_margin_rate" class="text-caption text-grey mt-2">
                                                    <v-icon size="x-small">mdi-information-outline</v-icon>
                                                    Aucune facture de vente enregistrée pour calculer la marge.
                                                </div>
                                            </v-col>
                                        </v-row>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Fixed costs breakdown for period -->
                        <v-row>
                            <v-col cols="12" md="5">
                                <v-card>
                                    <v-card-title class="text-subtitle-1">Coûts fixes — {{ summaryPeriodLabel }}</v-card-title>
                                    <v-table density="compact">
                                        <thead>
                                            <tr>
                                                <th>Pool</th>
                                                <th>Sous-catégorie</th>
                                                <th class="text-right">Montant</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="cost in summaryFixedCostsForPeriod" :key="cost.id">
                                                <td>
                                                    <v-chip
                                                        :color="cost.cost_pool === 'storage' ? 'blue' : 'purple'"
                                                        size="x-small"
                                                        variant="tonal"
                                                    >
                                                        {{ cost.cost_pool_label }}
                                                    </v-chip>
                                                </td>
                                                <td>{{ cost.sub_category_label }}</td>
                                                <td class="text-right font-weight-medium">{{ formatCurrency(cost.amount) }}</td>
                                            </tr>
                                            <tr v-if="summaryFixedCostsForPeriod.length === 0">
                                                <td colspan="3" class="text-center text-grey py-3">
                                                    Aucun coût fixe pour cette période
                                                </td>
                                            </tr>
                                            <tr v-else class="font-weight-bold bg-blue-lighten-5">
                                                <td colspan="2">Total coûts fixes</td>
                                                <td class="text-right">{{ formatCurrency(summary.fixed_costs_total) }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-card>
                            </v-col>

                            <!-- Salaries breakdown -->
                            <v-col cols="12" md="3">
                                <v-card>
                                    <v-card-title class="text-subtitle-1">Salaires commerciaux</v-card-title>
                                    <v-table density="compact">
                                        <thead>
                                            <tr>
                                                <th>Commercial</th>
                                                <th class="text-right">Salaire</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="commercial in commerciaux" :key="commercial.id">
                                                <td>{{ commercial.name }}</td>
                                                <td class="text-right">{{ formatCurrency(commercial.salary) }}</td>
                                            </tr>
                                            <tr v-if="commerciaux.length === 0">
                                                <td colspan="2" class="text-center text-grey py-3">Aucun commercial</td>
                                            </tr>
                                            <tr v-else class="font-weight-bold bg-green-lighten-5">
                                                <td>Total salaires</td>
                                                <td class="text-right">{{ formatCurrency(summary.commercial_salaries_total) }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-card>
                            </v-col>

                            <!-- Vehicle costs breakdown -->
                            <v-col cols="12" md="4">
                                <v-card>
                                    <v-card-title class="text-subtitle-1">Coûts véhicules</v-card-title>
                                    <v-table density="compact">
                                        <thead>
                                            <tr>
                                                <th>Véhicule</th>
                                                <th class="text-right">Coût/mois</th>
                                                <th class="text-right">Coût/jour</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="vehicle in vehicles" :key="vehicle.id">
                                                <td>
                                                    <div>{{ vehicle.name }}</div>
                                                    <div class="text-caption text-grey">{{ vehicle.plate_number || '—' }}</div>
                                                </td>
                                                <td class="text-right">{{ formatCurrency(vehicle.total_monthly_fixed_cost) }}</td>
                                                <td class="text-right text-caption">{{ formatCurrency(vehicle.daily_fixed_cost) }}</td>
                                            </tr>
                                            <tr v-if="vehicles.length === 0">
                                                <td colspan="3" class="text-center text-grey py-3">Aucun véhicule</td>
                                            </tr>
                                            <tr v-else class="font-weight-bold bg-orange-lighten-5">
                                                <td>Total véhicules</td>
                                                <td class="text-right">{{ formatCurrency(summary.vehicle_costs_total) }}</td>
                                                <td class="text-right">{{ formatCurrency(daily.daily_vehicle_costs) }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-tabs-window-item>

                    <!-- ── TAB: COÛTS FIXES MENSUELS ── -->
                    <v-tabs-window-item value="fixed">
                        <!-- Period Filter -->
                        <v-card class="mb-6">
                            <v-card-text>
                                <v-row align="center">
                                    <v-col cols="12" sm="3">
                                        <v-select
                                            v-model="filterYear"
                                            :items="availableYears"
                                            label="Année"
                                            clearable
                                            hide-details
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="3">
                                        <v-select
                                            v-model="filterMonth"
                                            :items="monthItems"
                                            item-title="label"
                                            item-value="value"
                                            label="Mois"
                                            clearable
                                            hide-details
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="3">
                                        <v-select
                                            v-model="filterPool"
                                            :items="pools"
                                            item-title="label"
                                            item-value="value"
                                            label="Pool"
                                            clearable
                                            hide-details
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="3">
                                        <v-btn variant="tonal" @click="resetFilters" block>
                                            Réinitialiser
                                        </v-btn>
                                    </v-col>
                                </v-row>
                            </v-card-text>
                        </v-card>

                        <template v-if="filteredPeriodGroups.length > 0">
                            <v-card
                                v-for="group in filteredPeriodGroups"
                                :key="group.periodKey"
                                class="mb-6"
                            >
                                <v-card-title class="d-flex align-center justify-space-between">
                                    <span>{{ group.periodLabel }}</span>
                                    <div class="d-flex align-center gap-2">
                                        <v-chip v-if="group.isFullyFinalized" color="success" size="small" prepend-icon="mdi-check-circle">Finalisé</v-chip>
                                        <v-chip v-else-if="group.isPartiallyFinalized" color="warning" size="small" prepend-icon="mdi-alert-circle">Partiellement finalisé</v-chip>
                                        <v-btn
                                            v-if="!group.isFullyFinalized"
                                            color="warning"
                                            size="small"
                                            variant="tonal"
                                            @click="openFinalizeDialog(group)"
                                        >
                                            <v-icon size="small" class="mr-1">mdi-lock</v-icon>
                                            Finaliser le mois
                                        </v-btn>
                                    </div>
                                </v-card-title>
                                <v-card-text class="pb-0">
                                    <v-row>
                                        <v-col v-for="poolSummary in group.poolSummaries" :key="poolSummary.pool" cols="12" sm="6" md="4">
                                            <v-chip color="primary" variant="tonal" class="w-100 justify-space-between px-4" style="height:auto;padding:8px 16px;">
                                                <span>{{ poolSummary.label }}</span>
                                                <strong>{{ formatCurrency(poolSummary.total) }}</strong>
                                            </v-chip>
                                        </v-col>
                                        <v-col cols="12" sm="6" md="4">
                                            <v-chip color="secondary" variant="tonal" class="w-100 justify-space-between px-4" style="height:auto;padding:8px 16px;">
                                                <span>Total période</span>
                                                <strong>{{ formatCurrency(group.periodTotal) }}</strong>
                                            </v-chip>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                                <v-table>
                                    <thead>
                                        <tr>
                                            <th>Pool</th>
                                            <th>Sous-catégorie</th>
                                            <th>Libellé</th>
                                            <th class="text-right">Montant</th>
                                            <th class="text-right">Par véhicule</th>
                                            <th class="text-center">Véhicules actifs</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="cost in group.costs" :key="cost.id">
                                            <td>
                                                <v-chip :color="cost.cost_pool === 'storage' ? 'blue' : 'purple'" size="small" variant="tonal">
                                                    {{ cost.cost_pool_label }}
                                                </v-chip>
                                            </td>
                                            <td>{{ cost.sub_category_label }}</td>
                                            <td class="text-grey">{{ cost.label || '—' }}</td>
                                            <td class="text-right font-weight-bold">{{ formatCurrency(cost.amount) }}</td>
                                            <td class="text-right">{{ cost.per_vehicle_amount != null ? formatCurrency(cost.per_vehicle_amount) : '—' }}</td>
                                            <td class="text-center">{{ cost.active_vehicle_count ?? '—' }}</td>
                                            <td>
                                                <v-chip v-if="cost.finalized_at" color="success" size="x-small" prepend-icon="mdi-lock">Finalisé</v-chip>
                                                <v-chip v-else color="grey" size="x-small" variant="tonal">En attente</v-chip>
                                            </td>
                                            <td>
                                                <template v-if="!cost.finalized_at">
                                                    <v-btn icon="mdi-pencil" variant="text" color="primary" size="small" class="mr-1" @click="openEditDialog(cost)" />
                                                    <v-btn icon="mdi-delete" variant="text" color="error" size="small" @click="openDeleteDialog(cost)" />
                                                </template>
                                                <span v-else class="text-grey text-caption">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>
                        </template>
                        <v-card v-else>
                            <v-card-text class="text-center text-grey py-8">
                                Aucun coût fixe enregistré pour les filtres sélectionnés
                            </v-card-text>
                        </v-card>
                    </v-tabs-window-item>

                    <!-- ── TAB: SALAIRES COMMERCIAUX ── -->
                    <v-tabs-window-item value="salaries">
                        <v-card>
                            <v-card-title class="d-flex align-center justify-space-between">
                                <span>Salaires mensuels des commerciaux</span>
                                <v-chip color="green" variant="tonal">
                                    Total : {{ formatCurrency(summary.commercial_salaries_total) }}
                                </v-chip>
                            </v-card-title>
                            <v-table>
                                <thead>
                                    <tr>
                                        <th>Commercial</th>
                                        <th class="text-right">Salaire mensuel</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="commercial in commerciaux" :key="commercial.id">
                                        <td class="font-weight-medium">{{ commercial.name }}</td>
                                        <td class="text-right">{{ formatCurrency(commercial.salary) }}</td>
                                    </tr>
                                    <tr v-if="commerciaux.length === 0">
                                        <td colspan="2" class="text-center text-grey py-6">Aucun commercial enregistré</td>
                                    </tr>
                                    <tr v-else class="font-weight-bold bg-green-lighten-5">
                                        <td>Total</td>
                                        <td class="text-right">{{ formatCurrency(summary.commercial_salaries_total) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <v-card-actions>
                                <v-btn variant="text" color="primary" :href="route('commerciaux.index')">
                                    <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                                    Modifier les salaires dans Commerciaux
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-tabs-window-item>

                    <!-- ── TAB: VÉHICULES ── -->
                    <v-tabs-window-item value="vehicles">
                        <v-card>
                            <v-card-title class="d-flex align-center justify-space-between">
                                <span>Coûts mensuels des véhicules</span>
                                <v-chip color="orange" variant="tonal">
                                    Total : {{ formatCurrency(summary.vehicle_costs_total) }}
                                </v-chip>
                            </v-card-title>
                            <v-table>
                                <thead>
                                    <tr>
                                        <th>Véhicule</th>
                                        <th class="text-right">Assurance</th>
                                        <th class="text-right">Entretien</th>
                                        <th class="text-right">Réserve répa.</th>
                                        <th class="text-right">Amortissement</th>
                                        <th class="text-right">Salaire chauffeur</th>
                                        <th class="text-center">Jours/mois</th>
                                        <th class="text-right">Total/mois</th>
                                        <th class="text-right">Coût/jour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="vehicle in vehicles" :key="vehicle.id">
                                        <td>
                                            <div class="font-weight-medium">{{ vehicle.name }}</div>
                                            <div class="text-caption text-grey">{{ vehicle.plate_number || '—' }}</div>
                                        </td>
                                        <td class="text-right">{{ formatCurrency(vehicle.insurance_monthly) }}</td>
                                        <td class="text-right">{{ formatCurrency(vehicle.maintenance_monthly) }}</td>
                                        <td class="text-right">{{ formatCurrency(vehicle.repair_reserve_monthly) }}</td>
                                        <td class="text-right">{{ formatCurrency(vehicle.depreciation_monthly) }}</td>
                                        <td class="text-right">{{ formatCurrency(vehicle.driver_salary_monthly) }}</td>
                                        <td class="text-center">{{ vehicle.working_days_per_month }}</td>
                                        <td class="text-right font-weight-bold">{{ formatCurrency(vehicle.total_monthly_fixed_cost) }}</td>
                                        <td class="text-right text-caption">{{ formatCurrency(vehicle.daily_fixed_cost) }}</td>
                                    </tr>
                                    <tr v-if="vehicles.length === 0">
                                        <td colspan="9" class="text-center text-grey py-6">Aucun véhicule enregistré</td>
                                    </tr>
                                    <tr v-else class="font-weight-bold bg-orange-lighten-5">
                                        <td colspan="7">Total</td>
                                        <td class="text-right">{{ formatCurrency(summary.vehicle_costs_total) }}</td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <v-card-actions>
                                <v-btn variant="text" color="primary" :href="route('vehicles.index')">
                                    <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                                    Modifier les véhicules dans Stock → Véhicules
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-tabs-window-item>

                </v-tabs-window>
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <v-dialog v-model="formDialog" max-width="560px" persistent>
            <v-card>
                <v-card-title>
                    {{ editingCost ? 'Modifier le coût fixe' : 'Ajouter un coût fixe' }}
                </v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="saveCost">
                        <v-row>
                            <v-col cols="6">
                                <v-select
                                    v-model="form.period_year"
                                    :items="selectableYears"
                                    label="Année"
                                    required
                                    :error-messages="form.errors.period_year"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-select
                                    v-model="form.period_month"
                                    :items="monthItems"
                                    item-title="label"
                                    item-value="value"
                                    label="Mois"
                                    required
                                    :error-messages="form.errors.period_month"
                                />
                            </v-col>
                        </v-row>
                        <v-select
                            v-model="form.sub_category"
                            :items="subCategoryItems"
                            item-title="label"
                            item-value="value"
                            label="Sous-catégorie"
                            required
                            :error-messages="form.errors.sub_category"
                        />
                        <v-text-field
                            :model-value="selectedPoolLabel"
                            label="Pool (automatique)"
                            readonly
                            variant="filled"
                            class="mt-1"
                        />
                        <v-text-field
                            v-model="form.label"
                            label="Libellé (optionnel)"
                            :error-messages="form.errors.label"
                        />
                        <v-text-field
                            v-model.number="form.amount"
                            label="Montant"
                            type="number"
                            min="1"
                            suffix="XOF"
                            required
                            :error-messages="form.errors.amount"
                        />
                        <v-textarea
                            v-model="form.notes"
                            label="Notes"
                            rows="2"
                            :error-messages="form.errors.notes"
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="closeFormDialog">Annuler</v-btn>
                    <v-btn color="primary" :loading="form.processing" @click="saveCost">
                        {{ editingCost ? 'Modifier' : 'Ajouter' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title>Supprimer le coût fixe</v-card-title>
                <v-card-text>
                    Supprimer <strong>{{ costToDelete?.sub_category_label }}</strong>
                    de <strong>{{ formatCurrency(costToDelete?.amount) }}</strong> ?
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn color="error" :loading="deleteForm.processing" @click="confirmDelete">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Finalize Month Dialog -->
        <v-dialog v-model="finalizeDialog" max-width="480px">
            <v-card>
                <v-card-title>Finaliser le mois</v-card-title>
                <v-card-text>
                    <p>Finaliser les coûts fixes de <strong>{{ periodGroupToFinalize?.periodLabel }}</strong> ?</p>
                    <v-alert type="warning" variant="tonal" class="mt-3">
                        Cette action est <strong>irréversible</strong>. Les entrées finalisées ne pourront plus être modifiées.
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="finalizeDialog = false">Annuler</v-btn>
                    <v-btn color="warning" :loading="finalizeForm.processing" @click="confirmFinalize">Finaliser</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    costs: { type: Array, default: () => [] },
    pools: { type: Array, default: () => [] },
    subCategories: { type: Array, default: () => [] },
    commerciaux: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    costSummary: { type: Object, required: true },
});

const activeTab = ref('summary');

// ─── Month / year helpers ──────────────────────────────────────────────────────

const currentYear = new Date().getFullYear();
const currentMonth = new Date().getMonth() + 1;

const monthItems = [
    { value: 1, label: 'Janvier' },
    { value: 2, label: 'Février' },
    { value: 3, label: 'Mars' },
    { value: 4, label: 'Avril' },
    { value: 5, label: 'Mai' },
    { value: 6, label: 'Juin' },
    { value: 7, label: 'Juillet' },
    { value: 8, label: 'Août' },
    { value: 9, label: 'Septembre' },
    { value: 10, label: 'Octobre' },
    { value: 11, label: 'Novembre' },
    { value: 12, label: 'Décembre' },
];

const availableYears = computed(() => {
    const yearsInData = [...new Set(props.costs.map((cost) => cost.period_year))];
    return [...new Set([...yearsInData, currentYear])].sort((a, b) => b - a);
});

const selectableYears = computed(() => {
    return [...new Set([...availableYears.value, currentYear, currentYear + 1])].sort((a, b) => b - a);
});

function formatCurrency(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount ?? 0) + ' XOF';
}

function formatPeriodLabel(year, month) {
    return new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(
        new Date(year, month - 1, 1),
    );
}

// ─── Summary tab ──────────────────────────────────────────────────────────────

const summaryYear = ref(props.costSummary.period_year);
const summaryMonth = ref(props.costSummary.period_month);

const summaryPeriodLabel = computed(() => formatPeriodLabel(summaryYear.value, summaryMonth.value));

// All computed values come pre-calculated from the backend (AbcCostSummaryService).
// No arithmetic in the frontend — change the period to request a fresh server computation.
const summary = computed(() => props.costSummary);
const daily = computed(() => props.costSummary.daily_breakdown);
const breakEven = computed(() => props.costSummary.break_even);

const summaryFixedCostsForPeriod = computed(() =>
    props.costs.filter(
        (cost) => cost.period_year === summaryYear.value && cost.period_month === summaryMonth.value,
    ),
);

watch([summaryYear, summaryMonth], ([year, month]) => {
    router.get(
        route('monthly-fixed-costs.index'),
        { year, month },
        { preserveState: true, replace: true, preserveScroll: true },
    );
});

function formatRate(rate) {
    return (rate * 100).toFixed(1).replace(/\.0$/, '') + ' %';
}

// ─── Fixed costs tab filters ───────────────────────────────────────────────────

const filterYear = ref(currentYear);
const filterMonth = ref(currentMonth);
const filterPool = ref(null);

function resetFilters() {
    filterYear.value = currentYear;
    filterMonth.value = currentMonth;
    filterPool.value = null;
}

const filteredPeriodGroups = computed(() => {
    const filteredCosts = props.costs.filter((cost) => {
        if (filterYear.value && cost.period_year !== filterYear.value) return false;
        if (filterMonth.value && cost.period_month !== filterMonth.value) return false;
        if (filterPool.value && cost.cost_pool !== filterPool.value) return false;
        return true;
    });

    const groupMap = new Map();
    for (const cost of filteredCosts) {
        const periodKey = `${cost.period_year}-${String(cost.period_month).padStart(2, '0')}`;
        if (!groupMap.has(periodKey)) {
            groupMap.set(periodKey, {
                periodKey,
                year: cost.period_year,
                month: cost.period_month,
                periodLabel: formatPeriodLabel(cost.period_year, cost.period_month),
                costs: [],
            });
        }
        groupMap.get(periodKey).costs.push(cost);
    }

    return [...groupMap.values()]
        .sort((a, b) => b.periodKey.localeCompare(a.periodKey))
        .map((group) => {
            const poolTotals = new Map();
            for (const cost of group.costs) {
                poolTotals.set(cost.cost_pool, (poolTotals.get(cost.cost_pool) ?? 0) + cost.amount);
            }

            const poolSummaries = [...poolTotals.entries()].map(([pool, total]) => ({
                pool,
                label: props.pools.find((p) => p.value === pool)?.label ?? pool,
                total,
            }));

            const periodTotal = group.costs.reduce((sum, cost) => sum + cost.amount, 0);
            const finalizedCount = group.costs.filter((cost) => cost.finalized_at).length;
            const isFullyFinalized = finalizedCount === group.costs.length && group.costs.length > 0;
            const isPartiallyFinalized = finalizedCount > 0 && !isFullyFinalized;

            return { ...group, poolSummaries, periodTotal, isFullyFinalized, isPartiallyFinalized };
        });
});

// ─── Form ──────────────────────────────────────────────────────────────────────

const formDialog = ref(false);
const editingCost = ref(null);

const form = useForm({
    cost_pool: '',
    sub_category: '',
    amount: 0,
    label: '',
    period_year: currentYear,
    period_month: currentMonth,
    notes: '',
});

const subCategoryItems = computed(() => props.subCategories);

const selectedPoolLabel = computed(() => {
    const selectedSubCategory = props.subCategories.find((sc) => sc.value === form.sub_category);
    if (!selectedSubCategory) return '';
    return props.pools.find((pool) => pool.value === selectedSubCategory.pool)?.label ?? '';
});

watch(
    () => form.sub_category,
    (newSubCategory) => {
        const selectedSubCategory = props.subCategories.find((sc) => sc.value === newSubCategory);
        if (selectedSubCategory) {
            form.cost_pool = selectedSubCategory.pool;
        }
    },
);

function openCreateDialog() {
    editingCost.value = null;
    form.reset();
    form.period_year = filterYear.value ?? currentYear;
    form.period_month = filterMonth.value ?? currentMonth;
    formDialog.value = true;
}

function openEditDialog(cost) {
    editingCost.value = cost;
    form.cost_pool = cost.cost_pool;
    form.sub_category = cost.sub_category;
    form.amount = cost.amount;
    form.label = cost.label ?? '';
    form.period_year = cost.period_year;
    form.period_month = cost.period_month;
    form.notes = cost.notes ?? '';
    formDialog.value = true;
}

function closeFormDialog() {
    formDialog.value = false;
    editingCost.value = null;
    form.reset();
}

function saveCost() {
    if (editingCost.value) {
        form.put(route('monthly-fixed-costs.update', editingCost.value.id), {
            onSuccess: () => closeFormDialog(),
        });
    } else {
        form.post(route('monthly-fixed-costs.store'), {
            onSuccess: () => closeFormDialog(),
        });
    }
}

// ─── Delete ────────────────────────────────────────────────────────────────────

const deleteDialog = ref(false);
const costToDelete = ref(null);
const deleteForm = useForm({});

function openDeleteDialog(cost) {
    costToDelete.value = cost;
    deleteDialog.value = true;
}

function confirmDelete() {
    deleteForm.delete(route('monthly-fixed-costs.destroy', costToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            costToDelete.value = null;
        },
    });
}

// ─── Finalize ──────────────────────────────────────────────────────────────────

const finalizeDialog = ref(false);
const periodGroupToFinalize = ref(null);
const finalizeForm = useForm({ year: null, month: null });

function openFinalizeDialog(periodGroup) {
    periodGroupToFinalize.value = periodGroup;
    finalizeForm.year = periodGroup.year;
    finalizeForm.month = periodGroup.month;
    finalizeDialog.value = true;
}

function confirmFinalize() {
    finalizeForm.post(route('monthly-fixed-costs.finalize-month'), {
        onSuccess: () => {
            finalizeDialog.value = false;
            periodGroupToFinalize.value = null;
        },
    });
}
</script>
