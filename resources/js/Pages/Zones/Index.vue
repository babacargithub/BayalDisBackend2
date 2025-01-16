<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    zones: Array,
    errors: Object,
    flash: Object,
    unassignedCustomers: Array,
});

// Zone form
const zoneForm = useForm({
    name: '',
    ville: '',
    quartiers: '',
    gps_coordinates: '',
});

// Ligne form
const ligneForm = useForm({
    name: '',
    zone_id: null,
    livreur_id: null,
});

// Assign customer form
const assignCustomerForm = useForm({
    customer_id: '',
});

// UI state
const addZoneDialog = ref(false);
const editZoneDialog = ref(false);
const addLigneDialog = ref(false);
const showLignesDialog = ref(false);
const assignCustomerDialog = ref(false);
const selectedZone = ref(null);
const selectedLigne = ref(null);
const lignes = ref([]);
const unassignedCustomers = ref([]);
const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('');

// Watch for flash messages
watch(() => props.flash, (newFlash) => {
    if (newFlash?.success) {
        snackbarText.value = newFlash.success;
        snackbarColor.value = 'success';
        snackbar.value = true;
    }
    if (newFlash?.error) {
        snackbarText.value = newFlash.error;
        snackbarColor.value = 'error';
        snackbar.value = true;
    }
}, { deep: true });

// Zone methods
const createZone = () => {
    zoneForm.post(route('zones.store'), {
        onSuccess: () => {
            addZoneDialog.value = false;
            zoneForm.reset();
        },
    });
};

const editZone = (zone) => {
    selectedZone.value = zone;
    zoneForm.name = zone.name;
    zoneForm.ville = zone.ville;
    zoneForm.quartiers = zone.quartiers;
    zoneForm.gps_coordinates = zone.gps_coordinates;
    editZoneDialog.value = true;
};

const updateZone = () => {
    zoneForm.put(route('zones.update', selectedZone.value.id), {
        onSuccess: () => {
            editZoneDialog.value = false;
            zoneForm.reset();
            selectedZone.value = null;
        },
    });
};

const deleteZone = (zone) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette zone ?')) {
        router.delete(route('zones.destroy', zone.id));
    }
};

// Ligne methods
const showLignes = async (zone) => {
    selectedZone.value = zone;
    try {
        const [lignesResponse, customersResponse] = await Promise.all([
            axios.get(route('zones.lignes', zone.id)),
            axios.get(route('lignes.unassigned-customers'))
        ]);
        lignes.value = lignesResponse.data;
        unassignedCustomers.value = customersResponse.data;
        showLignesDialog.value = true;
    } catch (error) {
        console.error('Error fetching data:', error);
        snackbarText.value = 'Erreur lors du chargement des données';
        snackbarColor.value = 'error';
        snackbar.value = true;
    }
};

const createLigne = () => {
    ligneForm.zone_id = selectedZone.value.id;
    ligneForm.post(route('lignes.store'), {
        onSuccess: () => {
            addLigneDialog.value = false;
            ligneForm.reset();
            showLignes(selectedZone.value);
        },
    });
};

const viewCustomers = (ligne) => {
    router.visit(route('lignes.show', ligne.id));
};

const assignCustomer = () => {
    assignCustomerForm.post(route('lignes.assign-customer', selectedLigne.value.id), {
        onSuccess: () => {
            assignCustomerDialog.value = false;
            assignCustomerForm.reset();
            showLignes(selectedZone.value);
        },
    });
};

const openAssignCustomerDialog = (ligne) => {
    selectedLigne.value = ligne;
    assignCustomerDialog.value = true;
};
</script>

<template>
    <Head title="Zones" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Zones</h2>
        </template>

        <v-container>
            <!-- Add Zone Button -->
            <v-row class="mb-4">
                <v-col>
                    <v-btn color="primary" @click="addZoneDialog = true">
                        Ajouter une zone
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Zones Table -->
            <v-card>
                <v-table>
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Ville</th>
                            <th>Quartiers</th>
                            <th>Coordonnées GPS</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="zone in zones" :key="zone.id">
                            <td>{{ zone.name }}</td>
                            <td>{{ zone.ville }}</td>
                            <td>{{ zone.quartiers }}</td>
                            <td>{{ zone.gps_coordinates }}</td>
                            <td>
                                <v-btn icon="mdi-pencil" size="small" class="mr-2" @click="editZone(zone)" />
                                <v-btn icon="mdi-delete" size="small" color="error" class="mr-2" @click="deleteZone(zone)" />
                                <v-btn icon="mdi-map-marker" size="small" color="success" class="mr-2" @click="showLignes(zone)" />
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card>

            <!-- Add Zone Dialog -->
            <v-dialog v-model="addZoneDialog" max-width="500px">
                <v-card>
                    <v-card-title>Ajouter une zone</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="createZone">
                            <v-text-field v-model="zoneForm.name" label="Nom" :error-messages="errors?.name" />
                            <v-text-field v-model="zoneForm.ville" label="Ville" :error-messages="errors?.ville" />
                            <v-text-field v-model="zoneForm.quartiers" label="Quartiers" :error-messages="errors?.quartiers" />
                            <v-text-field v-model="zoneForm.gps_coordinates" label="Coordonnées GPS" :error-messages="errors?.gps_coordinates" />
                            <v-card-actions>
                                <v-spacer />
                                <v-btn @click="addZoneDialog = false">Annuler</v-btn>
                                <v-btn color="primary" type="submit" :loading="zoneForm.processing">Sauvegarder</v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Edit Zone Dialog -->
            <v-dialog v-model="editZoneDialog" max-width="500px">
                <v-card>
                    <v-card-title>Modifier la zone</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="updateZone">
                            <v-text-field v-model="zoneForm.name" label="Nom" :error-messages="errors?.name" />
                            <v-text-field v-model="zoneForm.ville" label="Ville" :error-messages="errors?.ville" />
                            <v-text-field v-model="zoneForm.quartiers" label="Quartiers" :error-messages="errors?.quartiers" />
                            <v-text-field v-model="zoneForm.gps_coordinates" label="Coordonnées GPS" :error-messages="errors?.gps_coordinates" />
                            <v-card-actions>
                                <v-spacer />
                                <v-btn @click="editZoneDialog = false">Annuler</v-btn>
                                <v-btn color="primary" type="submit" :loading="zoneForm.processing">Mettre à jour</v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Show Lignes Dialog -->
            <v-dialog v-model="showLignesDialog" max-width="800px">
                <v-card>
                    <v-card-title>
                        Lignes de la zone {{ selectedZone?.name }}
                        <v-spacer />
                        <v-btn color="primary" @click="addLigneDialog = true">
                            Ajouter une ligne
                        </v-btn>
                    </v-card-title>
                    <v-card-text>
                        <v-table>
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Livreur</th>
                                    <th>Nombre de clients</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ligne in lignes" :key="ligne.id">
                                    <td>
                                        <div class="font-weight-bold">{{ ligne.name }}</div>
                                        <template v-if="ligne.customers && ligne.customers.length">
                                            <div  class="d-flex align-center mt-2">
                                                <!-- show customers count -->
                                                <div class="text-caption">{{ ligne.customers?.length }} clients</div>
                                                
                                            </div>
                                        </template>
                                    </td>
                                    <td>{{ ligne.livreur?.name || 'Non assigné' }}</td>
                                    <td>{{ ligne.customers?.length || 0 }}</td>
                                    <td>
                                        <v-btn icon="mdi-account-group" size="small" color="info" class="mr-2" @click="viewCustomers(ligne)" />
                                        <v-btn icon="mdi-account-plus" size="small" color="success" @click="openAssignCustomerDialog(ligne)" />
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Add Ligne Dialog -->
            <v-dialog v-model="addLigneDialog" max-width="500px">
                <v-card>
                    <v-card-title>Ajouter une ligne</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="createLigne">
                            <v-text-field v-model="ligneForm.name" label="Nom" :error-messages="errors?.name" />
                            <v-card-actions>
                                <v-spacer />
                                <v-btn @click="addLigneDialog = false">Annuler</v-btn>
                                <v-btn color="primary" type="submit" :loading="ligneForm.processing">Sauvegarder</v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Assign Customer Dialog -->
            <v-dialog v-model="assignCustomerDialog" max-width="500px">
                <v-card>
                    <v-card-title>Assigner un client</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="assignCustomer">
                            <v-select 
                                v-model="assignCustomerForm.customer_id" 
                                label="Client" 
                                :items="unassignedCustomers" 
                                item-title="name"
                                item-value="id"
                                :error-messages="errors?.customer_id"
                                persistent-hint
                                hint="Sélectionnez un client à assigner à cette ligne"
                                :loading="!unassignedCustomers.length"
                            />
                            <v-card-actions>
                                <v-spacer />
                                <v-btn @click="assignCustomerDialog = false">Annuler</v-btn>
                                <v-btn color="primary" type="submit" :loading="assignCustomerForm.processing">Assigner</v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Snackbar -->
            <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="3000">
                {{ snackbarText }}
                <template v-slot:actions>
                    <v-btn variant="text" @click="snackbar = false">Fermer</v-btn>
                </template>
            </v-snackbar>
        </v-container>
    </AuthenticatedLayout>
</template> 