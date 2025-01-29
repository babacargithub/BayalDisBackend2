<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Dépenses
                </h2>
                <div class="flex gap-2">
                    <button
                        @click="openTypeDialog"
                        class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700"
                    >
                        <v-icon
                            icon="mdi-format-list-bulleted"
                            size="small"
                            class="mr-2"
                        />
                        Types de dépenses
                    </button>
                    <button
                        @click="openCreateDialog"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                    >
                        <v-icon
                            icon="mdi-plus"
                            size="small"
                            class="mr-2"
                        />
                        Nouvelle dépense
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Total Expenses Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                            Total des dépenses
                        </h3>
                        <p class="text-3xl font-bold text-indigo-600">
                            {{ formatAmount(totalDepenses) }} FCFA
                        </p>
                    </div>
                </div>

                <!-- Expenses List -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Type
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Montant
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Commentaire
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date
                                        </th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="depense in depenses" :key="depense.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ depense.type.name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ formatAmount(depense.amount) }} FCFA
                                        </td>
                                        <td class="px-6 py-4">
                                            {{ depense.comment }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ formatDate(depense.created_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <button
                                                @click="openEditDialog(depense)"
                                                class="text-indigo-600 hover:text-indigo-900 mr-4"
                                            >
                                                <v-icon icon="mdi-pencil" />
                                            </button>
                                            <button
                                                @click="confirmDelete(depense)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                <v-icon icon="mdi-delete" />
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="depenses.length === 0">
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            Aucune dépense enregistrée
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Expense Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    {{ editedDepense ? 'Modifier la dépense' : 'Nouvelle dépense' }}
                </v-card-title>
                <v-card-text>
                    <form @submit.prevent="submit">
                        <div class="mb-4">
                            <label for="type_depense_id" class="block text-sm font-medium text-gray-700">Type de dépense</label>
                            <select
                                id="type_depense_id"
                                v-model="form.type_depense_id"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                required
                            >
                                <option value="">Sélectionner un type</option>
                                <option v-for="type in types" :key="type.id" :value="type.id">
                                    {{ type.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.type_depense_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.type_depense_id }}
                            </p>
                        </div>

                        <div class="mb-4">
                            <label for="amount" class="block text-sm font-medium text-gray-700">Montant (FCFA)</label>
                            <input
                                id="amount"
                                v-model="form.amount"
                                type="number"
                                min="0"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                required
                            />
                            <p v-if="form.errors.amount" class="mt-1 text-sm text-red-600">
                                {{ form.errors.amount }}
                            </p>
                        </div>

                        <div class="mb-4">
                            <label for="comment" class="block text-sm font-medium text-gray-700">Commentaire</label>
                            <textarea
                                id="comment"
                                v-model="form.comment"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                rows="3"
                            />
                            <p v-if="form.errors.comment" class="mt-1 text-sm text-red-600">
                                {{ form.errors.comment }}
                            </p>
                        </div>
                    </form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="dialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        :loading="form.processing"
                        @click="submit"
                    >
                        {{ editedDepense ? 'Modifier' : 'Ajouter' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Types Dialog -->
        <v-dialog v-model="typeDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Types de dépenses
                </v-card-title>
                <v-card-text>
                    <!-- Add Type Form -->
                    <form @submit.prevent="submitType" class="mb-6">
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <input
                                    v-model="typeForm.name"
                                    type="text"
                                    class="block w-full rounded-md border-gray-300"
                                    placeholder="Nouveau type de dépense"
                                    required
                                />
                                <p v-if="typeForm.errors.name" class="mt-1 text-sm text-red-600">
                                    {{ typeForm.errors.name }}
                                </p>
                            </div>
                            <button
                                type="submit"
                                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                                :disabled="typeForm.processing"
                            >
                                Ajouter
                            </button>
                        </div>
                    </form>

                    <!-- Types List -->
                    <div class="space-y-2">
                        <div
                            v-for="type in types"
                            :key="type.id"
                            class="flex items-center justify-between p-2 bg-gray-50 rounded"
                        >
                            <span>{{ type.name }}</span>
                            <div class="flex gap-2">
                                <button
                                    @click="openEditTypeDialog(type)"
                                    class="text-indigo-600 hover:text-indigo-900"
                                >
                                    <v-icon icon="mdi-pencil" />
                                </button>
                                <button
                                    @click="confirmDeleteType(type)"
                                    class="text-red-600 hover:text-red-900"
                                >
                                    <v-icon icon="mdi-delete" />
                                </button>
                            </div>
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="primary"
                        variant="text"
                        @click="typeDialog = false"
                    >
                        Fermer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Edit Type Dialog -->
        <v-dialog v-model="editTypeDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Modifier le type de dépense
                </v-card-title>
                <v-card-text>
                    <form @submit.prevent="submitEditType">
                        <div class="mb-4">
                            <input
                                v-model="editTypeForm.name"
                                type="text"
                                class="block w-full rounded-md border-gray-300"
                                required
                            />
                            <p v-if="editTypeForm.errors.name" class="mt-1 text-sm text-red-600">
                                {{ editTypeForm.errors.name }}
                            </p>
                        </div>
                    </form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="editTypeDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        :loading="editTypeForm.processing"
                        @click="submitEditType"
                    >
                        Modifier
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Confirmer la suppression
                </v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette dépense ? Cette action est irréversible.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="deleteDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="deleteDepense"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Type Confirmation Dialog -->
        <v-dialog v-model="deleteTypeDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    Confirmer la suppression
                </v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer ce type de dépense ? Cette action est irréversible.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="deleteTypeDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="deleteType"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    depenses: {
        type: Array,
        required: true
    },
    types: {
        type: Array,
        required: true
    },
    totalDepenses: {
        type: Number,
        required: true
    }
});

// Expense Dialog
const dialog = ref(false);
const editedDepense = ref(null);

const form = useForm({
    type_depense_id: '',
    amount: '',
    comment: ''
});

// Type Dialog
const typeDialog = ref(false);
const editTypeDialog = ref(false);
const editedType = ref(null);

const typeForm = useForm({
    name: ''
});

const editTypeForm = useForm({
    name: ''
});

// Delete Dialogs
const deleteDialog = ref(false);
const deleteTypeDialog = ref(false);
const depenseToDelete = ref(null);
const typeToDelete = ref(null);

const formatAmount = (amount) => {
    return new Intl.NumberFormat('fr-FR').format(amount);
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

// Expense Methods
const openCreateDialog = () => {
    editedDepense.value = null;
    form.reset();
    dialog.value = true;
};

const openEditDialog = (depense) => {
    editedDepense.value = depense;
    form.type_depense_id = depense.type.id;
    form.amount = depense.amount;
    form.comment = depense.comment;
    dialog.value = true;
};

const submit = () => {
    if (editedDepense.value) {
        form.put(route('depenses.update', editedDepense.value.id), {
            onSuccess: () => {
                dialog.value = false;
                editedDepense.value = null;
            }
        });
    } else {
        form.post(route('depenses.store'), {
            onSuccess: () => {
                dialog.value = false;
            }
        });
    }
};

const confirmDelete = (depense) => {
    depenseToDelete.value = depense;
    deleteDialog.value = true;
};

const deleteDepense = () => {
    router.delete(route('depenses.destroy', depenseToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            depenseToDelete.value = null;
        }
    });
};

// Type Methods
const openTypeDialog = () => {
    typeForm.reset();
    typeDialog.value = true;
};

const submitType = () => {
    typeForm.post(route('depenses.types.store'), {
        onSuccess: () => {
            typeForm.reset();
        }
    });
};

const openEditTypeDialog = (type) => {
    editedType.value = type;
    editTypeForm.name = type.name;
    editTypeDialog.value = true;
};

const submitEditType = () => {
    editTypeForm.put(route('depenses.types.update', editedType.value.id), {
        onSuccess: () => {
            editTypeDialog.value = false;
            editedType.value = null;
        }
    });
};

const confirmDeleteType = (type) => {
    typeToDelete.value = type;
    deleteTypeDialog.value = true;
};

const deleteType = () => {
    router.delete(route('depenses.types.destroy', typeToDelete.value.id), {
        onSuccess: () => {
            deleteTypeDialog.value = false;
            typeToDelete.value = null;
        }
    });
};
</script> 