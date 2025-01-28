<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CustomerHistoryDialog from '@/Pages/Clients/CustomerHistoryDialog.vue';
import { useRouter } from 'vue-router';

const props = defineProps({
    clients: {
        type: Object,
        required: true
    },
    commerciaux: {
        type: Array,
        required: true
    },
    errors: Object,
    flash: Object,
    filters: {
        type: Object,
        default: () => ({})
    }
});

const form = useForm({
    name: '',
    phone_number: '',
    owner_number: '',
    gps_coordinates: '',
    commercial_id: '',
    address: '',
});

const dialog = ref(false);
const deleteDialog = ref(false);
const editDialog = ref(false);
const clientToDelete = ref(null);
const editingClient = ref(null);
const deleteForm = ref(null);
const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('');

const filterForm = useForm({
    prospect_status: props.filters?.prospect_status || '',
    commercial_id: props.filters?.commercial_id || ''
});

const applyFilter = (status) => {
    filterForm.get(route('clients.index'), {
        preserveState: true,
        preserveScroll: true,
        only: ['clients']
    });
};

watch(() => filterForm.prospect_status, (newValue) => {
    applyFilter();
});

const openGoogleMaps = (coordinates) => {
    const url = `https://www.google.com/maps?q=${coordinates}`;
    window.open(url, '_blank');
};

const editForm = useForm({
    name: '',
    phone_number: '',
    owner_number: '',
    gps_coordinates: '',
    commercial_id: '',
    description: '',
    address: '',
});

const openEditDialog = (client) => {
    editingClient.value = client;
    editForm.name = client.name;
    editForm.phone_number = client.phone_number;
    editForm.owner_number = client.owner_number;
    editForm.gps_coordinates = client.gps_coordinates;
    editForm.commercial_id = client.commercial_id;
    editForm.description = client.description || '';
    editForm.address = client.address || '';
    editDialog.value = true;
};

const submitEdit = () => {
    console.log('Submitting edit with data:', editForm.data());
    editForm.patch(route('clients.update', editingClient.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editDialog.value = false;
            editingClient.value = null;
        },
        onError: (errors) => {
            console.error('Update failed:', errors);
        }
    });
};

const confirmDelete = (client) => {
    clientToDelete.value = client;
    deleteDialog.value = true;
};

const deleteClient = () => {
    deleteForm.value = useForm({});
    deleteForm.value.delete(route('clients.destroy', clientToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialog.value = false;
            clientToDelete.value = null;
            deleteForm.value = null;
            window.location.reload();
        },
        onError: (errors) => {
            console.error('Delete failed:', errors);
            snackbarText.value = errors.message || 'Une erreur est survenue lors de la suppression du client';
            snackbarColor.value = 'error';
            snackbar.value = true;
        }
    });
};

const submit = () => {
    form.post(route('clients.store'), {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    });
};

watch(() => props.flash, (newFlash) => {
    if (!newFlash) return;
    
    if (newFlash.success) {
        snackbarText.value = newFlash.success;
        snackbarColor.value = 'success';
        snackbar.value = true;
    }
    if (newFlash.error) {
        snackbarText.value = newFlash.error;
        snackbarColor.value = 'error';
        snackbar.value = true;
    }
}, { deep: true, immediate: true });

const showHistory = ref(false);
const selectedClient = ref(null);

const openHistory = async (client) => {
    selectedClient.value = client;
    showHistory.value = true;
};

const searchQuery = ref('');

const router = useRouter();

const filteredClients = computed(() => {
    if (!searchQuery.value) return props.clients.data;
    
    const query = searchQuery.value.toLowerCase();
    return props.clients.data.filter(client => 
        client.name.toLowerCase().includes(query) || 
        (client.phone_number && client.phone_number.toLowerCase().includes(query))
    );
});

const handlePageChange = (page) => {
    router.get(
        route('clients.index', {
            page,
            commercial_id: filterForm.commercial_id,
            prospect_status: filterForm.prospect_status,
        }),
        {},
        { preserveState: true, preserveScroll: true }
    );
};
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Clients</h2>
                <div class="flex gap-2 items-center">
                  
                    <v-btn-group>
                        <v-btn 
                            :color="filterForm.prospect_status === '' ? 'primary' : undefined"
                            @click="filterForm.prospect_status = ''"
                        >
                            Tous
                        </v-btn>
                        <v-btn 
                            :color="filterForm.prospect_status === 'prospects' ? 'primary' : undefined"
                            @click="filterForm.prospect_status = 'prospects'"
                        >
                            Prospects
                        </v-btn>
                        <v-btn 
                            :color="filterForm.prospect_status === 'customers' ? 'primary' : undefined"
                            @click="filterForm.prospect_status = 'customers'"
                        >
                            Clients
                        </v-btn>
                    </v-btn-group>
                    <v-btn color="primary" @click="dialog = true">
                        Ajouter un client
                    </v-btn>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-text-field
                        v-model="searchQuery"
                        prepend-inner-icon="mdi-magnify"
                        label="Rechercher par nom ou téléphone"
                        single-line
                        hide-details
                        density="compact"
                        class="mr-4"
                    />
                    <v-table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Téléphone</th>
                                <th>Numéro Propriétaire</th>
                                <th>Commercial</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="client in filteredClients" :key="client.id">
                                <td>
                                    <div class="d-flex align-center">
                                        <div>
                                            <div class="font-weight-bold">{{ client.name }}</div>
                                            <div class="text-caption text-grey">{{ client.address }}</div>
                                        </div>
                                        <v-tooltip v-if="client.description" location="top">
                                            <template v-slot:activator="{ props }">
                                                <v-icon
                                                    size="small"
                                                    color="grey-darken-1"
                                                    class="ml-2"
                                                    v-bind="props"
                                                >
                                                    mdi-information
                                                </v-icon>
                                            </template>
                                            {{ client.description }}
                                        </v-tooltip>
                                    </div>
                                </td>
                                <td>{{ client.phone_number }}</td>
                                <td>{{ client.owner_number }}</td>
                                <td>{{ client.commercial?.name }}</td>
                                <td>
                                    <v-icon
                                        v-if="client.is_prospect"
                                        icon="mdi-account-question"
                                        color="warning"
                                        title="Prospect"
                                    />
                                </td>
                                <td class="d-flex">
                                    <v-btn 
                                        icon="mdi-history"
                                        variant="text"
                                        color="info"
                                        class="mr-2"
                                        @click="openHistory(client)"
                                        title="Historique des ventes"
                                    />
                                    <v-btn 
                                        icon="mdi-map-marker"
                                        variant="text"
                                        color="success"
                                        class="mr-2"
                                        @click="openGoogleMaps(client.gps_coordinates)"
                                        title="Voir sur Google Maps"
                                    />
                                    <v-btn 
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        class="mr-2"
                                        @click="openEditDialog(client)"
                                        title="Modifier"
                                    />
                                    <v-btn 
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        @click="confirmDelete(client)"
                                        title="Supprimer"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                    
                    <!-- Pagination -->
                    <div class="py-3 px-4 d-flex justify-end">
                        <v-pagination
                            v-if="clients.last_page > 1"
                            v-model="clients.current_page"
                            :length="clients.last_page"
                            :total-visible="7"
                            @update:model-value="handlePageChange"
                        />
                    </div>
                </v-card>
            </div>
        </div>

        <!-- Create Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>Nouveau Client</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            :error-messages="form.errors.name"
                        />
                        <v-text-field
                            v-model="form.phone_number"
                            label="Téléphone"
                            :error-messages="form.errors.phone_number"
                        />
                        <v-text-field
                            v-model="form.owner_number"
                            label="Numéro Propriétaire"
                            :error-messages="form.errors.owner_number"
                        />
                        <v-text-field
                            v-model="form.gps_coordinates"
                            label="Coordonnées GPS"
                            :error-messages="form.errors.gps_coordinates"
                        />
                        <v-text-field
                            v-model="form.address"
                            label="Adresse"
                            :error-messages="form.errors.address"
                            class="mb-4"
                        />
                        <v-select
                            v-model="form.commercial_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            :error-messages="form.errors.commercial_id"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="form.processing">
                                Sauvegarder
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Edit Dialog -->
        <v-dialog v-model="editDialog" max-width="500px">
            <v-card>
                <v-card-title>Modifier le Client</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submitEdit">
                        <v-text-field
                            v-model="editForm.name"
                            label="Nom"
                            :error-messages="editForm.errors.name"
                        />
                        <v-text-field
                            v-model="editForm.phone_number"
                            label="Téléphone"
                            :error-messages="editForm.errors.phone_number"
                        />
                        <v-text-field
                            v-model="editForm.owner_number"
                            label="Numéro Propriétaire"
                            :error-messages="editForm.errors.owner_number"
                        />
                        <v-text-field
                            v-model="editForm.gps_coordinates"
                            label="Coordonnées GPS"
                            :error-messages="editForm.errors.gps_coordinates"
                        />
                        <v-select
                            v-model="editForm.commercial_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            :error-messages="editForm.errors.commercial_id"
                        />
                        <v-textarea
                            v-model="editForm.description"
                            label="Description"
                            :error-messages="editForm.errors.description"
                            rows="3"
                            auto-grow
                            placeholder="Ajoutez une description pour ce client..."
                        />
                        <v-text-field
                            v-model="editForm.address"
                            label="Adresse"
                            :error-messages="editForm.errors.address"
                            class="mb-4"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="editDialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="editForm.processing">
                                Mettre à jour
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Delete Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title>Supprimer le Client</v-card-title>
                <v-card-text>
                    <div class="mb-4">
                        Êtes-vous sûr de vouloir supprimer ce client ? Cette action est irréversible.
                    </div>
                    <div v-if="clientToDelete" class="text-subtitle-1">
                        <div><strong>Nom:</strong> {{ clientToDelete.name }}</div>
                        <div><strong>Téléphone:</strong> {{ clientToDelete.phone_number }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteDialog = false" :disabled="deleteForm?.processing">Annuler</v-btn>
                    <v-btn 
                        color="error" 
                        @click="deleteClient" 
                        :loading="deleteForm?.processing"
                        :disabled="deleteForm?.processing"
                    >
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar -->
        <v-snackbar
            v-model="snackbar"
            :color="snackbarColor"
            :timeout="3000"
        >
            {{ snackbarText }}
            <template v-slot:actions>
                <v-btn
                    variant="text"
                    @click="snackbar = false"
                >
                    Fermer
                </v-btn>
            </template>
        </v-snackbar>

        <CustomerHistoryDialog
            v-model="showHistory"
            :customer="selectedClient"
            :orders="selectedClient?.orders || []"
            :ventes="selectedClient?.ventes || []"
        />
    </AuthenticatedLayout>
</template> 