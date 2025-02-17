<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useForm } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const props = defineProps({
    carLoad: {
        type: Object,
        required: true
    },
    products: {
        type: Array,
        required: true
    }
});

const showAddItemDialog = ref(false);
const editingItem = ref(null);

const form = useForm({
    product_id: null,
    total_loaded: 0,
    total_sold: 0,
    total_available: 0,
    comment: '',
});

const headers = [
    { text: 'Produit', value: 'product.name' },
    { text: 'Total Chargé', value: 'total_loaded' },
    { text: 'Total Vendu', value: 'total_sold' },
    { text: 'Disponible', value: 'total_available' },
    { text: 'Commentaire', value: 'comment' },
    { text: 'Actions', value: 'actions', sortable: false },
];

const deleteItem = async (id) => {
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
        form.delete(route('car-load-items.destroy', id), {
            onSuccess: () => {
                Swal.fire(
                    'Supprimé!',
                    'L\'article a été supprimé.',
                    'success'
                );
            }
        });
    }
};

const openEditDialog = (item) => {
    editingItem.value = item;
    form.product_id = item.product_id;
    form.total_loaded = item.total_loaded;
    form.total_sold = item.total_sold;
    form.total_available = item.total_available;
    form.comment = item.comment;
    showAddItemDialog.value = true;
};

const submit = () => {
    if (editingItem.value) {
        form.put(route('car-load-items.update', editingItem.value.id), {
            onSuccess: () => {
                showAddItemDialog.value = false;
                editingItem.value = null;
                form.reset();
            }
        });
    } else {
        form.post(route('car-load-items.store', { car_load_id: props.carLoad.id }), {
            onSuccess: () => {
                showAddItemDialog.value = false;
                form.reset();
            }
        });
    }
};

const unloadCarLoad = async () => {
    const result = await Swal.fire({
        title: 'Êtes-vous sûr?',
        text: "Voulez-vous décharger ce véhicule?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Oui, décharger!',
        cancelButtonText: 'Annuler'
    });

    if (result.isConfirmed) {
        form.post(route('car-loads.unload', props.carLoad.id), {
            onSuccess: () => {
                Swal.fire(
                    'Déchargé!',
                    'Le véhicule a été déchargé.',
                    'success'
                );
            }
        });
    }
};

const createNewFromCurrent = async () => {
    const result = await Swal.fire({
        title: 'Nouveau chargement',
        text: "Voulez-vous créer un nouveau chargement à partir de celui-ci?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Oui, créer!',
        cancelButtonText: 'Annuler'
    });

    if (result.isConfirmed) {
        form.post(route('car-loads.create-from', props.carLoad.id), {
            onSuccess: () => {
                Swal.fire(
                    'Créé!',
                    'Le nouveau chargement a été créé.',
                    'success'
                );
            }
        });
    }
};
</script>

<template>
    <Head :title="`Chargement ${carLoad.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Chargement {{ carLoad.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 bg-white border-b border-gray-200">
                        <!-- Car Load Details -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold mb-4">Détails du chargement</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p><strong>Commercial:</strong> {{ carLoad.commercial.name }}</p>
                                    <p><strong>Date de chargement:</strong> {{ carLoad.load_date }}</p>
                                    <p><strong>Statut:</strong> {{ carLoad.status }}</p>
                                </div>
                                <div>
                                    <p><strong>Date de déchargement:</strong> {{ carLoad.unload_date || 'Non déchargé' }}</p>
                                    <p><strong>Commentaire:</strong> {{ carLoad.comment || '-' }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="mb-6">
                            <v-btn
                                color="primary"
                                class="mr-2"
                                @click="showAddItemDialog = true"
                                :disabled="carLoad.status === 'UNLOADED'"
                            >
                                <v-icon left>mdi-plus</v-icon>
                                Ajouter un article
                            </v-btn>

                            <v-btn
                                color="warning"
                                class="mr-2"
                                @click="unloadCarLoad"
                                :disabled="carLoad.status !== 'ACTIVE'"
                            >
                                <v-icon left>mdi-package-down</v-icon>
                                Décharger
                            </v-btn>

                            <v-btn
                                color="info"
                                @click="createNewFromCurrent"
                                :disabled="carLoad.status !== 'UNLOADED'"
                            >
                                <v-icon left>mdi-content-copy</v-icon>
                                Nouveau à partir de celui-ci
                            </v-btn>
                        </div>

                        <!-- Inventory Items Table -->
                        <v-data-table
                            :headers="headers"
                            :items="carLoad.items"
                            :items-per-page="10"
                            class="elevation-1"
                        >
                            <template v-slot:item.actions="{ item }">
                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            class="mr-2"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="openEditDialog(item)"
                                            :disabled="carLoad.status === 'UNLOADED'"
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
                                            @click="deleteItem(item.id)"
                                            :disabled="carLoad.status === 'UNLOADED'"
                                        >
                                            <v-icon>mdi-delete</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Supprimer</span>
                                </v-tooltip>
                            </template>
                        </v-data-table>

                        <!-- Add/Edit Item Dialog -->
                        <v-dialog
                            v-model="showAddItemDialog"
                            max-width="600px"
                        >
                            <v-card>
                                <v-card-title>
                                    <span class="text-h5">{{ editingItem ? 'Modifier' : 'Ajouter' }} un article</span>
                                </v-card-title>

                                <v-card-text>
                                    <v-form @submit.prevent="submit">
                                        <v-select
                                            v-model="form.product_id"
                                            :items="products"
                                            item-text="name"
                                            item-value="id"
                                            label="Produit"
                                            required
                                            :error-messages="form.errors.product_id"
                                        ></v-select>

                                        <v-text-field
                                            v-model="form.total_loaded"
                                            type="number"
                                            label="Total Chargé"
                                            required
                                            :error-messages="form.errors.total_loaded"
                                        ></v-text-field>

                                        <v-text-field
                                            v-model="form.total_sold"
                                            type="number"
                                            label="Total Vendu"
                                            required
                                            :error-messages="form.errors.total_sold"
                                        ></v-text-field>

                                        <v-text-field
                                            v-model="form.total_available"
                                            type="number"
                                            label="Disponible"
                                            required
                                            :error-messages="form.errors.total_available"
                                        ></v-text-field>

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
                                        @click="showAddItemDialog = false"
                                    >
                                        Annuler
                                    </v-btn>
                                    <v-btn
                                        color="primary"
                                        @click="submit"
                                        :loading="form.processing"
                                    >
                                        {{ editingItem ? 'Modifier' : 'Ajouter' }}
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