<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    clients: Array,
    commerciaux: Array
});

const form = useForm({
    name: '',
    phone_number: '',
    owner_number: '',
    gps_coordinates: '',
    commercial_id: '',
});

const dialog = ref(false);
const deleteDialog = ref(false);
const editDialog = ref(false);
const clientToDelete = ref(null);
const editingClient = ref(null);

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
});

const openEditDialog = (client) => {
    editingClient.value = client;
    editForm.name = client.name;
    editForm.phone_number = client.phone_number;
    editForm.owner_number = client.owner_number;
    editForm.gps_coordinates = client.gps_coordinates;
    editForm.commercial_id = client.commercial_id;
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
    router.delete(route('clients.destroy', clientToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            clientToDelete.value = null;
        },
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
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Clients</h2>
                <v-btn color="primary" @click="dialog = true">
                    Ajouter un client
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
                                <th>Téléphone</th>
                                <th>Numéro Propriétaire</th>
                                <th>Commercial</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="client in clients" :key="client.id">
                                <td>{{ client.name }}</td>
                                <td>{{ client.phone_number }}</td>
                                <td>{{ client.owner_number }}</td>
                                <td>{{ client.commercial?.name }}</td>
                                <td class="d-flex">
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

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Confirmer la suppression</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer ce client ? Cette action est irréversible.
                    <div v-if="clientToDelete" class="mt-4">
                        <strong>Client à supprimer :</strong>
                        <div>Nom : {{ clientToDelete.name }}</div>
                        <div>Téléphone : {{ clientToDelete.phone_number }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="text" @click="deleteClient">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 