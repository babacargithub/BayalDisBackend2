<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Véhicules</h2>
                <v-btn color="primary" @click="openCreateDialog">
                    <v-icon>mdi-plus</v-icon>
                    Ajouter un véhicule
                </v-btn>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Immatriculation</th>
                                <th class="text-right">Assurance/mois</th>
                                <th class="text-right">Entretien/mois</th>
                                <th class="text-right">Réserve réparation/mois</th>
                                <th class="text-right">Amortissement/mois</th>
                                <th class="text-right">Salaire chauffeur/mois</th>
                                <th class="text-center">Jours travaillés/mois</th>
                                <th class="text-right">Carburant estimé/jour</th>
                                <th class="text-right">Coût fixe/mois</th>
                                <th class="text-right">Coût fixe/jour</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="vehicle in vehicles" :key="vehicle.id">
                                <td>{{ vehicle.name }}</td>
                                <td>{{ vehicle.plate_number || '—' }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.insurance_monthly) }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.maintenance_monthly) }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.repair_reserve_monthly) }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.depreciation_monthly) }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.driver_salary_monthly) }}</td>
                                <td class="text-center">{{ vehicle.working_days_per_month }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.estimated_daily_fuel_consumption) }}</td>
                                <td class="text-right font-weight-bold">{{ formatCurrency(vehicle.total_monthly_fixed_cost) }}</td>
                                <td class="text-right">{{ formatCurrency(vehicle.daily_fixed_cost) }}</td>
                                <td>
                                    <v-btn
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        class="mr-2"
                                        @click="openEditDialog(vehicle)"
                                    />
                                    <v-btn
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        @click="openDeleteDialog(vehicle)"
                                    />
                                </td>
                            </tr>
                            <tr v-if="vehicles.length === 0">
                                <td colspan="12" class="text-center text-grey py-4">Aucun véhicule enregistré</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <v-dialog v-model="formDialog" max-width="600px" persistent>
            <v-card>
                <v-card-title>
                    {{ editingVehicle ? 'Modifier le véhicule' : 'Ajouter un véhicule' }}
                </v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="saveVehicle">
                        <v-row>
                            <v-col cols="12" sm="7">
                                <v-text-field
                                    v-model="form.name"
                                    label="Nom du véhicule"
                                    required
                                    :error-messages="form.errors.name"
                                />
                            </v-col>
                            <v-col cols="12" sm="5">
                                <v-text-field
                                    v-model="form.plate_number"
                                    label="Immatriculation"
                                    :error-messages="form.errors.plate_number"
                                />
                            </v-col>
                        </v-row>

                        <v-divider class="my-2" />
                        <div class="text-caption text-grey mb-2">Coûts fixes mensuels (XOF)</div>

                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.insurance_monthly"
                                    label="Assurance"
                                    type="number"
                                    min="0"
                                    suffix="XOF"
                                    :error-messages="form.errors.insurance_monthly"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.maintenance_monthly"
                                    label="Entretien"
                                    type="number"
                                    min="0"
                                    suffix="XOF"
                                    :error-messages="form.errors.maintenance_monthly"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.repair_reserve_monthly"
                                    label="Réserve réparations"
                                    type="number"
                                    min="0"
                                    suffix="XOF"
                                    :error-messages="form.errors.repair_reserve_monthly"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.depreciation_monthly"
                                    label="Amortissement"
                                    type="number"
                                    min="0"
                                    suffix="XOF"
                                    :error-messages="form.errors.depreciation_monthly"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.driver_salary_monthly"
                                    label="Salaire chauffeur"
                                    type="number"
                                    min="0"
                                    suffix="XOF"
                                    :error-messages="form.errors.driver_salary_monthly"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.working_days_per_month"
                                    label="Jours travaillés/mois"
                                    type="number"
                                    min="1"
                                    max="31"
                                    :error-messages="form.errors.working_days_per_month"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.estimated_daily_fuel_consumption"
                                    label="Carburant estimé/jour"
                                    type="number"
                                    min="0"
                                    suffix="XOF"
                                    :error-messages="form.errors.estimated_daily_fuel_consumption"
                                />
                            </v-col>
                        </v-row>

                        <v-divider class="my-2" />

                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-chip color="primary" variant="tonal" class="w-100 justify-center">
                                    Total/mois : {{ formatCurrency(computedMonthlyTotal) }}
                                </v-chip>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-chip color="secondary" variant="tonal" class="w-100 justify-center">
                                    Coût/jour : {{ formatCurrency(computedDailyCost) }}
                                </v-chip>
                            </v-col>
                        </v-row>

                        <v-textarea
                            v-model="form.notes"
                            label="Notes"
                            rows="2"
                            class="mt-4"
                            :error-messages="form.errors.notes"
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="closeFormDialog">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        @click="saveVehicle"
                        :loading="form.processing"
                    >
                        {{ editingVehicle ? 'Modifier' : 'Ajouter' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title>Supprimer le véhicule</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer le véhicule
                    <strong>{{ vehicleToDelete?.name }}</strong> ?
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        @click="confirmDelete"
                        :loading="deleteForm.processing"
                    >
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    vehicles: {
        type: Array,
        default: () => [],
    },
});

const formDialog = ref(false);
const deleteDialog = ref(false);
const editingVehicle = ref(null);
const vehicleToDelete = ref(null);

const form = useForm({
    name: '',
    plate_number: '',
    insurance_monthly: 0,
    maintenance_monthly: 0,
    repair_reserve_monthly: 0,
    depreciation_monthly: 0,
    driver_salary_monthly: 0,
    working_days_per_month: 26,
    estimated_daily_fuel_consumption: 0,
    notes: '',
});

const deleteForm = useForm({});

const computedMonthlyTotal = computed(() => {
    return (form.insurance_monthly || 0)
        + (form.maintenance_monthly || 0)
        + (form.repair_reserve_monthly || 0)
        + (form.depreciation_monthly || 0)
        + (form.driver_salary_monthly || 0);
});

const computedDailyCost = computed(() => {
    const workingDays = form.working_days_per_month || 0;
    if (workingDays <= 0) {
        return 0;
    }
    return Math.round(computedMonthlyTotal.value / workingDays);
});

function formatCurrency(amount) {
    return new Intl.NumberFormat('fr-FR', { style: 'decimal' }).format(amount ?? 0) + ' XOF';
}

function openCreateDialog() {
    editingVehicle.value = null;
    form.reset();
    formDialog.value = true;
}

function openEditDialog(vehicle) {
    editingVehicle.value = vehicle;
    form.name = vehicle.name;
    form.plate_number = vehicle.plate_number ?? '';
    form.insurance_monthly = vehicle.insurance_monthly;
    form.maintenance_monthly = vehicle.maintenance_monthly;
    form.repair_reserve_monthly = vehicle.repair_reserve_monthly;
    form.depreciation_monthly = vehicle.depreciation_monthly;
    form.driver_salary_monthly = vehicle.driver_salary_monthly;
    form.working_days_per_month = vehicle.working_days_per_month;
    form.estimated_daily_fuel_consumption = vehicle.estimated_daily_fuel_consumption;
    form.notes = vehicle.notes ?? '';
    formDialog.value = true;
}

function closeFormDialog() {
    formDialog.value = false;
    editingVehicle.value = null;
    form.reset();
}

function openDeleteDialog(vehicle) {
    vehicleToDelete.value = vehicle;
    deleteDialog.value = true;
}

function confirmDelete() {
    deleteForm.delete(route('vehicles.destroy', vehicleToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            vehicleToDelete.value = null;
        },
    });
}

function saveVehicle() {
    if (editingVehicle.value) {
        form.put(route('vehicles.update', editingVehicle.value.id), {
            onSuccess: () => {
                closeFormDialog();
            },
        });
    } else {
        form.post(route('vehicles.store'), {
            onSuccess: () => {
                closeFormDialog();
            },
        });
    }
}
</script>
