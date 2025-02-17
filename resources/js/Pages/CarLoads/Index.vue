<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useForm } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const props = defineProps({
    carLoads: {
        type: Object,
        required: true
    },
    commercials: {
        type: Array,
        required: true
    }
});

const showNewDialog = ref(false);
const showEditDialog = ref(false);
const editingCarLoad = ref(null);

const form = useForm({
    name: '',
    commercial_id: null,
    comment: '',
});

const headers = [
    { text: 'Nom', value: 'name' },
    { text: 'Commercial', value: 'commercial.name' },
    { text: 'Date de chargement', value: 'load_date' },
    { text: 'Date de déchargement', value: 'unload_date' },
    { text: 'Statut', value: 'status' },
    { text: 'Actions', value: 'actions', sortable: false },
];

const deleteCarLoad = async (id) => {
    const result = await Swal.fire({
        title: 'Êtes-vous sûr?',
        text: "Cette action est irréversible!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Oui, supprimer!',
        cancelButtonText: 'Annuler'
    });

    if (result.isConfirmed) {
        form.delete(route('car-loads.destroy', id), {
            onSuccess: () => {
                Swal.fire(
                    'Supprimé!',
                    'Le chargement a été supprimé.',
                    'success'
                );
            }
        });
    }
};

const openEditDialog = (carLoad) => {
    editingCarLoad.value = carLoad;
    form.name = carLoad.name;
    form.commercial_id = carLoad.commercial_id;
    form.comment = carLoad.comment;
    showEditDialog.value = true;
};

const submit = () => {
    if (editingCarLoad.value) {
        form.put(route('car-loads.update', editingCarLoad.value.id), {
            onSuccess: () => {
                showEditDialog.value = false;
                editingCarLoad.value = null;
                form.reset();
            }
        });
    } else {
        form.post(route('car-loads.store'), {
            onSuccess: () => {
                showNewDialog.value = false;
                form.reset();
            }
        });
    }
};
</script>

<template>
    <Head title="Chargements Véhicule" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Chargements Véhicule
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 bg-white border-b border-gray-200">
                        <div class="mb-4">
                            <v-btn
                                color="primary"
                                @click="showNewDialog = true"
                            >
                                <v-icon>mdi-plus</v-icon>
                                Nouveau Chargement
                            </v-btn>
                        </div>

                        <v-data-table
                            :headers="headers"
                            :items="carLoads.data"
                            :items-per-page="10"
                            class="elevation-1"
                        >
                            <template v-slot:item.status="{ item }">
                                <v-chip
                                    :color="item.status === 'ACTIVE' ? 'success' : (item.status === 'LOADING' ? 'warning' : 'error')"
                                    small
                                >
                                    {{ item.status }}
                                </v-chip>
                            </template>

                            <template v-slot:item.actions="{ item }">
                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            class="mr-2"
                                            v-bind="attrs"
                                            v-on="on"
                                            :to="route('car-loads.show', item.id)"
                                        >
                                            <v-icon>mdi-eye</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Voir les détails</span>
                                </v-tooltip>

                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            class="mr-2"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="openEditDialog(item)"
                                        >
                                            <v-icon>mdi-pencil</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Modifier</span>
                                </v-tooltip>

                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            color="error"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="deleteCarLoad(item.id)"
                                        >
                                            <v-icon>mdi-delete</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Supprimer</span>
                                </v-tooltip>
                            </template>
                        </v-data-table>

                        <!-- New/Edit Dialog -->
                        <v-dialog
                            v-model="showNewDialog"
                            max-width="600px"
                        >
                            <v-card>
                                <v-card-title>
                                    <span class="text-h5">{{ editingCarLoad ? 'Modifier' : 'Nouveau' }} Chargement</span>
                                </v-card-title>

                                <v-card-text>
                                    <v-form @submit.prevent="submit">
                                        <v-text-field
                                            v-model="form.name"
                                            label="Nom"
                                            required
                                            :error-messages="form.errors.name"
                                        ></v-text-field>

                                        <v-select
                                            v-model="form.commercial_id"
                                            :items="commercials"
                                            item-title="name"
                                            item-value="id"
                                            label="Commercial"
                                            required
                                            :error-messages="form.errors.commercial_id"
                                        ></v-select>

                                        <v-textarea
                                            v-model="form.comment"
                                            label="Commentaire"
                                            :error-messages="form.errors.comment"
                                        ></v-textarea>
                                    </v-form>
                                </v-card-text>

                                <v-card-actions>
                                    <v-spacer></v-spacer>
                                    <v-btn
                                        color="error"
                                        text
                                        @click="showNewDialog = showEditDialog = false"
                                    >
                                        Annuler
                                    </v-btn>
                                    <v-btn
                                        color="primary"
                                        @click="submit"
                                        :loading="form.processing"
                                    >
                                        {{ editingCarLoad ? 'Modifier' : 'Créer' }}
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 