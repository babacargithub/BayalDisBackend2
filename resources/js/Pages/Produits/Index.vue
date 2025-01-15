<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    produits: Array
});

const form = useForm({
    name: '',
    price: '',
});

const dialog = ref(false);
const editedItem = ref(null);
const deleteDialog = ref(false);
const itemToDelete = ref(null);
const deleteForm = ref(null);

const openDialog = (item = null) => {
    editedItem.value = item;
    if (item) {
        form.name = item.name;
        form.price = item.price;
    } else {
        form.reset();
    }
    dialog.value = true;
};

const openDeleteDialog = (item) => {
    itemToDelete.value = item;
    deleteDialog.value = true;
};

const submit = () => {
    if (editedItem.value) {
        form.put(route('produits.update', editedItem.value.id), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                editedItem.value = null;
            },
        });
    } else {
        form.post(route('produits.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            },
        });
    }
};

const deleteProduct = () => {
    if (itemToDelete.value) {
        deleteForm.value = useForm({});
        deleteForm.value.delete(route('produits.destroy', itemToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                deleteDialog.value = false;
                itemToDelete.value = null;
                deleteForm.value = null;
            },
            onError: (errors) => {
                console.error('Delete failed:', errors);
            }
        });
    }
};

const formatPrice = (price) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(price);
};
</script>

<template>
    <Head title="Produits" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Produits</h2>
                <v-btn color="primary" @click="openDialog()">
                    Ajouter un produit
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
                                <th>Prix</th>
                                <th>Ventes totales</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="produit in produits" :key="produit.id">
                                <td>{{ produit.name }}</td>
                                <td>{{ formatPrice(produit.price) }}</td>
                                <td>{{ produit.ventes_count }}</td>
                                <td>
                                    <v-btn 
                                        icon="mdi-pencil" 
                                        variant="text" 
                                        color="primary"
                                        @click="openDialog(produit)"
                                    />
                                    <v-btn 
                                        icon="mdi-delete" 
                                        variant="text" 
                                        color="error"
                                        @click="openDeleteDialog(produit)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>{{ editedItem ? 'Modifier le Produit' : 'Nouveau Produit' }}</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            :error-messages="form.errors.name"
                        />
                        <v-text-field
                            v-model="form.price"
                            label="Prix"
                            type="number"
                            :error-messages="form.errors.price"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                            <v-btn 
                                color="primary" 
                                type="submit" 
                                :loading="form.processing"
                            >
                                {{ editedItem ? 'Mettre à jour' : 'Sauvegarder' }}
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Supprimer le produit</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer ce produit ?
                    <br>
                    Cette action est irréversible.
                    <div v-if="itemToDelete" class="mt-4">
                        <strong>Détails du produit :</strong>
                        <div>Nom : {{ itemToDelete.name }}</div>
                        <div>Prix : {{ formatPrice(itemToDelete.price) }}</div>
                        <div v-if="itemToDelete.ventes_count > 0" class="mt-2 text-error">
                            Attention : Ce produit a {{ itemToDelete.ventes_count }} vente(s) associée(s).
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn 
                        color="primary" 
                        variant="text" 
                        @click="deleteDialog = false"
                        :disabled="deleteForm?.processing"
                    >
                        Annuler
                    </v-btn>
                    <v-btn 
                        color="error" 
                        variant="text" 
                        @click="deleteProduct"
                        :loading="deleteForm?.processing"
                        :disabled="deleteForm?.processing || (itemToDelete?.ventes_count > 0)"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 