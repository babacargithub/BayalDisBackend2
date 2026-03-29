<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import VisitBatchesDialog from './VisitBatchesDialog.vue';

const props = defineProps({
    sectors: {
        type: Array,
        required: true
    },
    lignes: {
        type: Array,
        required: true
    },
    customers: {
        type: Array,
        required: true
    },
    flash: Object,
});

// ─── Sector form ──────────────────────────────────────────────────────────────

const sectorDialogVisible = ref(false);
const selectedSector = ref(null);

const sectorForm = useForm({
    name: '',
    boundaries: '',
    ligne_id: null,
    description: ''
});

const openSectorDialog = (sector = null) => {
    if (sector) {
        selectedSector.value = sector;
        sectorForm.name = sector.name;
        sectorForm.boundaries = sector.boundaries;
        sectorForm.ligne_id = sector.ligne_id;
        sectorForm.description = sector.description;
    } else {
        selectedSector.value = null;
        sectorForm.reset();
    }
    sectorDialogVisible.value = true;
};

const submitSector = () => {
    if (selectedSector.value) {
        sectorForm.put(route('sectors.update', selectedSector.value.id), {
            onSuccess: () => {
                sectorDialogVisible.value = false;
                selectedSector.value = null;
            }
        });
    } else {
        sectorForm.post(route('sectors.store'), {
            onSuccess: () => {
                sectorDialogVisible.value = false;
            }
        });
    }
};

const deleteSector = (sector) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce secteur ?')) {
        router.delete(route('sectors.destroy', sector.id));
    }
};

// ─── Sector customers dialog ──────────────────────────────────────────────────

const sectorCustomersDialogVisible = ref(false);
const selectedSectorForCustomers = ref(null);

const assignCustomersForm = useForm({
    customer_ids: []
});

const openSectorCustomers = (sector) => {
    selectedSectorForCustomers.value = sector;
    sectorCustomersDialogVisible.value = true;
};

const assignCustomers = () => {
    assignCustomersForm.post(route('sectors.add-customers', selectedSectorForCustomers.value.id), {
        onSuccess: () => {
            sectorCustomersDialogVisible.value = false;
            assignCustomersForm.reset();
        }
    });
};

const removeCustomerFromSector = (sector, customer) => {
    if (confirm('Êtes-vous sûr de vouloir retirer ce client du secteur ?')) {
        router.delete(route('sectors.remove-customer', {
            sector: sector.id,
            customer: customer.id
        }));
    }
};

// ─── Sector map ───────────────────────────────────────────────────────────────

const openSectorMap = (sector) => {
    router.get(route('sectors.map', sector.id));
};

// ─── Visit batches ────────────────────────────────────────────────────────────

const showVisitBatchesDialog = ref(false);
const selectedSectorForVisits = ref(null);

const showVisitBatches = (sector) => {
    selectedSectorForVisits.value = sector;
    showVisitBatchesDialog.value = true;
};

// ─── Formatting ───────────────────────────────────────────────────────────────

function formatPrice(amount) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
}
</script>

<template>
    <Head title="Secteurs" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Secteurs</h2>
                <v-btn color="primary" @click="openSectorDialog()">
                    <v-icon start>mdi-plus</v-icon>
                    Nouveau Secteur
                </v-btn>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-data-table
                        :headers="[
                            { title: 'Nom', key: 'name', sortable: true },
                            { title: 'Description', key: 'description', sortable: true },
                            { title: 'Ligne', key: 'ligne.name', sortable: true },
                            { title: 'Nombre de clients', key: 'customers_count', sortable: true },
                            { title: 'Nombre de ventes', key: 'total_number_of_ventes', sortable: true },
                            { title: 'Montant total', key: 'total_amount_of_ventes', sortable: true },
                            { title: 'Dette totale', key: 'total_debt', sortable: true },
                            { title: 'Actions', key: 'actions', sortable: false },
                        ]"
                        :items="sectors"
                        class="elevation-1"
                    >
                        <template v-slot:item.customers_count="{ item }">
                            {{ item.customers?.length || 0 }}
                        </template>

                        <template v-slot:item.total_amount_of_ventes="{ item }">
                            {{ formatPrice(item.total_amount_of_ventes) }}
                        </template>

                        <template v-slot:item.total_debt="{ item }">
                            <span :class="{ 'text-error': item.total_debt > 0 }">
                                {{ formatPrice(item.total_debt) }}
                            </span>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <div class="d-flex gap-2">
                                <v-btn
                                    icon
                                    variant="text"
                                    density="comfortable"
                                    color="primary"
                                    title="Modifier"
                                    @click="openSectorDialog(item)"
                                >
                                    <v-icon>mdi-pencil</v-icon>
                                </v-btn>

                                <v-btn
                                    icon
                                    variant="text"
                                    density="comfortable"
                                    color="error"
                                    title="Supprimer"
                                    @click="deleteSector(item)"
                                >
                                    <v-icon>mdi-delete</v-icon>
                                </v-btn>

                                <v-btn
                                    icon
                                    variant="text"
                                    density="comfortable"
                                    color="success"
                                    title="Gérer les clients"
                                    @click="openSectorCustomers(item)"
                                >
                                    <v-icon>mdi-account-multiple</v-icon>
                                </v-btn>

                                <v-btn
                                    icon
                                    variant="text"
                                    density="comfortable"
                                    color="info"
                                    title="Carte"
                                    @click="openSectorMap(item)"
                                >
                                    <v-icon>mdi-map-marker-multiple</v-icon>
                                </v-btn>

                                <v-btn
                                    icon
                                    variant="text"
                                    density="comfortable"
                                    color="primary"
                                    title="Visites"
                                    @click="showVisitBatches(item)"
                                >
                                    <v-icon>mdi-calendar-check</v-icon>
                                </v-btn>
                            </div>
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </div>

        <!-- Sector Form Dialog -->
        <v-dialog v-model="sectorDialogVisible" max-width="500px">
            <v-card>
                <v-card-title>
                    {{ selectedSector ? 'Modifier le Secteur' : 'Nouveau Secteur' }}
                </v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submitSector">
                        <v-text-field
                            v-model="sectorForm.name"
                            label="Nom"
                            :error-messages="sectorForm.errors.name"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-textarea
                            v-model="sectorForm.description"
                            label="Description"
                            :error-messages="sectorForm.errors.description"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-textarea
                            v-model="sectorForm.boundaries"
                            label="Limites"
                            :error-messages="sectorForm.errors.boundaries"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-select
                            v-model="sectorForm.ligne_id"
                            :items="lignes"
                            item-title="name"
                            item-value="id"
                            label="Ligne"
                            :error-messages="sectorForm.errors.ligne_id"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn @click="sectorDialogVisible = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="sectorForm.processing">
                                {{ selectedSector ? 'Modifier' : 'Créer' }}
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Sector Customers Dialog -->
        <v-dialog v-model="sectorCustomersDialogVisible" max-width="800px">
            <v-card>
                <v-card-title>
                    Clients du Secteur {{ selectedSectorForCustomers?.name }}
                </v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="assignCustomers" class="mb-4">
                        <v-autocomplete
                            v-model="assignCustomersForm.customer_ids"
                            :items="customers"
                            item-title="name"
                            item-value="id"
                            label="Sélectionner les clients"
                            multiple
                            chips
                            :error-messages="assignCustomersForm.errors.customer_ids"
                            variant="outlined"
                        />
                        <div class="text-right mt-2">
                            <v-btn
                                color="primary"
                                type="submit"
                                :loading="assignCustomersForm.processing"
                            >
                                Ajouter les clients
                            </v-btn>
                        </div>
                    </v-form>

                    <v-data-table
                        :headers="[
                            { title: 'Nom', key: 'name' },
                            { title: 'Téléphone', key: 'phone_number' },
                            { title: 'Actions', key: 'actions', sortable: false },
                        ]"
                        :items="selectedSectorForCustomers?.customers || []"
                    >
                        <template v-slot:item.actions="{ item }">
                            <v-btn
                                icon
                                size="small"
                                color="error"
                                @click="removeCustomerFromSector(selectedSectorForCustomers, item)"
                            >
                                <v-icon>mdi-delete</v-icon>
                            </v-btn>
                        </template>
                    </v-data-table>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="sectorCustomersDialogVisible = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <visit-batches-dialog
            v-if="selectedSectorForVisits"
            v-model="showVisitBatchesDialog"
            :sector="selectedSectorForVisits"
        />
    </AuthenticatedLayout>
</template>
