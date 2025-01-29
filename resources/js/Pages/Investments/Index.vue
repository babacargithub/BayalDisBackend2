<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Investissements
                </h2>
                <button
                    @click="openCreateDialog"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                >
                    <v-icon
                        icon="mdi-plus"
                        size="small"
                        class="mr-2"
                    />
                    Nouvel investissement
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Total Investment Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                            Total des investissements
                        </h3>
                        <p class="text-3xl font-bold text-indigo-600">
                            {{ formatAmount(totalInvestment) }} FCFA
                        </p>
                    </div>
                </div>

                <!-- Investments List -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Titre
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Commentaire
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Montant
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
                                    <tr v-for="investment in investments" :key="investment.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ investment.title }}
                                        </td>
                                        <td class="px-6 py-4">
                                            {{ investment.comment }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ formatAmount(investment.amount) }} FCFA
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            {{ formatDate(investment.created_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <button
                                                @click="openEditDialog(investment)"
                                                class="text-indigo-600 hover:text-indigo-900 mr-4"
                                            >
                                                <v-icon icon="mdi-pencil" />
                                            </button>
                                            <button
                                                @click="confirmDelete(investment)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                <v-icon icon="mdi-delete" />
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="investments.length === 0">
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            Aucun investissement enregistré
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5 pb-4">
                    {{ editedInvestment ? 'Modifier l\'investissement' : 'Nouvel investissement' }}
                </v-card-title>
                <v-card-text>
                    <form @submit.prevent="submit">
                        <div class="mb-4">
                            <label for="title" class="block text-sm font-medium text-gray-700">Titre</label>
                            <input
                                id="title"
                                v-model="form.title"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                required
                            />
                            <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">
                                {{ form.errors.title }}
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
                        {{ editedInvestment ? 'Modifier' : 'Ajouter' }}
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
                    Êtes-vous sûr de vouloir supprimer cet investissement ? Cette action est irréversible.
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
                        @click="deleteInvestment"
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
    investments: {
        type: Array,
        required: true
    },
    totalInvestment: {
        type: Number,
        required: true
    }
});

const dialog = ref(false);
const deleteDialog = ref(false);
const editedInvestment = ref(null);
const investmentToDelete = ref(null);

const form = useForm({
    title: '',
    comment: '',
    amount: ''
});

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

const openCreateDialog = () => {
    editedInvestment.value = null;
    form.reset();
    dialog.value = true;
};

const openEditDialog = (investment) => {
    editedInvestment.value = investment;
    form.title = investment.title;
    form.comment = investment.comment;
    form.amount = investment.amount;
    dialog.value = true;
};

const submit = () => {
    if (editedInvestment.value) {
        form.put(route('investments.update', editedInvestment.value.id), {
            onSuccess: () => {
                dialog.value = false;
                editedInvestment.value = null;
            }
        });
    } else {
        form.post(route('investments.store'), {
            onSuccess: () => {
                dialog.value = false;
            }
        });
    }
};

const confirmDelete = (investment) => {
    investmentToDelete.value = investment;
    deleteDialog.value = true;
};

const deleteInvestment = () => {
    router.delete(route('investments.destroy', investmentToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            investmentToDelete.value = null;
        }
    });
};
</script> 