<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    commerciaux: Array,
    statistics: Object
});

const formatNumber = (number) => {
    return new Intl.NumberFormat('fr-FR').format(number || 0);
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', { 
        style: 'currency', 
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
};

const form = useForm({
    name: '',
    phone_number: '',
    gender: '',
    secret_code: '',
});

const dialog = ref(false);
const editDialog = ref(false);
const deleteDialog = ref(false);
const commercialToDelete = ref(null);
const editingCommercial = ref(null);
const isDeleting = ref(false);

const editForm = useForm({
    name: '',
    phone_number: '',
    gender: '',
    secret_code: '',
});

const openEditDialog = (commercial) => {
    editingCommercial.value = commercial;
    editForm.name = commercial.name;
    editForm.phone_number = commercial.phone_number;
    editForm.gender = commercial.gender;
    editForm.secret_code = commercial.secret_code;
    editDialog.value = true;
};

const submitEdit = () => {
    editForm.patch(route('commerciaux.update', editingCommercial.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editDialog.value = false;
            editingCommercial.value = null;
        },
        onError: (errors) => {
            console.error('Update failed:', errors);
        }
    });
};

const confirmDelete = (commercial) => {
    commercialToDelete.value = commercial;
    deleteDialog.value = true;
};

const deleteCommercial = () => {
    isDeleting.value = true;
    router.delete(route('commerciaux.destroy', commercialToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            commercialToDelete.value = null;
            isDeleting.value = false;
        },
        onError: (errors) => {
            console.error('Delete failed:', errors);
            isDeleting.value = false;
        },
        onFinish: () => {
            isDeleting.value = false;
        }
    });
};

const submit = () => {
    form.post(route('commerciaux.store'), {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <Head title="Commerciaux" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Commerciaux</h2>
                <v-btn color="primary" @click="dialog = true">
                    Ajouter un commercial
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
                                <th>Genre</th>
                                <th>Clients</th>
                                <th>Ventes</th>
                                <th>Montant Total</th>
                                <th>Ventes Impayées</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="commercial in commerciaux" :key="commercial.id">
                                <td>{{ commercial.name }}</td>
                                <td>{{ commercial.phone_number }}</td>
                                <td>{{ commercial.gender === 'male' ? 'Homme' : 'Femme' }}</td>
                                <td>{{ commercial.clients?.length || 0 }}</td>
                                <td>{{ commercial.ventes_count || 0 }}</td>
                                <td>{{ formatCurrency(commercial.ventes_sum_price_multiply_by_quantity) }}</td>
                                <td>{{ commercial.ventes_impayees_count || 0 }}</td>
                                <td class="d-flex">
                                    <v-btn 
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        class="mr-2"
                                        @click="openEditDialog(commercial)"
                                        title="Modifier"
                                    />
                                    <v-btn 
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        @click="confirmDelete(commercial)"
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
                <v-card-title>Nouveau Commercial</v-card-title>
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
                        <v-select
                            v-model="form.gender"
                            :items="[
                                { title: 'Homme', value: 'male' },
                                { title: 'Femme', value: 'female' }
                            ]"
                            item-title="title"
                            item-value="value"
                            label="Genre"
                            :error-messages="form.errors.gender"
                        />
                        <v-text-field
                            v-model="form.secret_code"
                            label="Code secret"
                            :error-messages="form.errors.secret_code"
                            type="password"
                            hint="Minimum 4 caractères"
                            persistent-hint
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
                <v-card-title>Modifier le Commercial</v-card-title>
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
                        <v-select
                            v-model="editForm.gender"
                            :items="[
                                { title: 'Homme', value: 'male' },
                                { title: 'Femme', value: 'female' }
                            ]"
                            item-title="title"
                            item-value="value"
                            label="Genre"
                            :error-messages="editForm.errors.gender"
                        />
                        <v-text-field
                            v-model="editForm.secret_code"
                            label="Code secret"
                            :error-messages="editForm.errors.secret_code"
                            type="password"
                            hint="Minimum 4 caractères"
                            persistent-hint
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
                    Êtes-vous sûr de vouloir supprimer ce commercial ? Cette action est irréversible.
                    <div v-if="commercialToDelete" class="mt-4">
                        <strong>Commercial à supprimer :</strong>
                        <div>Nom : {{ commercialToDelete.name }}</div>
                        <div>Téléphone : {{ commercialToDelete.phone_number }}</div>
                        <div v-if="commercialToDelete.clients?.length > 0" class="mt-2 text-error">
                            Attention : Ce commercial a {{ commercialToDelete.clients.length }} client(s) associé(s).
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deleteDialog = false" :disabled="isDeleting">Annuler</v-btn>
                    <v-btn 
                        color="error" 
                        variant="text" 
                        @click="deleteCommercial" 
                        :loading="isDeleting"
                        :disabled="isDeleting || (commercialToDelete?.clients?.length > 0)"
                    >
                        Confirmer la suppression
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 