<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Catégories de produits</h2>
                <v-btn color="primary" @click="openCreateDialog">
                    <v-icon>mdi-plus</v-icon>
                    Ajouter une catégorie
                </v-btn>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Description</th>
                                <th class="text-right">Taux de commission</th>
                                <th class="text-center">Produits</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="category in categories" :key="category.id">
                                <td class="font-weight-medium">{{ category.name }}</td>
                                <td class="text-grey">{{ category.description || '—' }}</td>
                                <td class="text-right">
                                    <v-chip
                                        v-if="category.commission_rate != null"
                                        color="primary"
                                        size="small"
                                        variant="tonal"
                                    >
                                        {{ formatRate(category.commission_rate) }}
                                    </v-chip>
                                    <span v-else class="text-grey">—</span>
                                </td>
                                <td class="text-center">
                                    <v-chip size="small" variant="tonal">{{ category.products_count }}</v-chip>
                                </td>
                                <td>
                                    <v-btn
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        size="small"
                                        class="mr-1"
                                        @click="openEditDialog(category)"
                                    />
                                    <v-btn
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        size="small"
                                        :disabled="category.products_count > 0"
                                        :title="category.products_count > 0 ? 'Catégorie utilisée par des produits' : 'Supprimer'"
                                        @click="openDeleteDialog(category)"
                                    />
                                </td>
                            </tr>
                            <tr v-if="categories.length === 0">
                                <td colspan="5" class="text-center text-grey py-6">
                                    Aucune catégorie enregistrée
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <v-dialog v-model="formDialog" max-width="480px" persistent>
            <v-card>
                <v-card-title>
                    {{ editingCategory ? 'Modifier la catégorie' : 'Ajouter une catégorie' }}
                </v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="saveCategory">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            required
                            :error-messages="form.errors.name"
                        />
                        <v-textarea
                            v-model="form.description"
                            label="Description (optionnel)"
                            rows="2"
                            :error-messages="form.errors.description"
                        />
                        <v-text-field
                            v-model.number="form.commission_rate"
                            label="Taux de commission par défaut (optionnel)"
                            type="number"
                            step="0.0001"
                            min="0"
                            max="1"
                            :error-messages="form.errors.commission_rate"
                            hint="0.01 = 1 % · 0.05 = 5 %. Peut être remplacé par un taux spécifique au commercial."
                            persistent-hint
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="closeFormDialog">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        :loading="form.processing"
                        @click="saveCategory"
                    >
                        {{ editingCategory ? 'Modifier' : 'Ajouter' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="480px">
            <v-card>
                <v-card-title>Supprimer la catégorie</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer la catégorie
                    <strong>{{ categoryToDelete?.name }}</strong> ?
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        :loading="deleteForm.processing"
                        @click="confirmDelete"
                    >
                        Supprimer
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

defineProps({
    categories: { type: Array, default: () => [] },
});

const formDialog = ref(false);
const deleteDialog = ref(false);
const editingCategory = ref(null);
const categoryToDelete = ref(null);

const form = useForm({ name: '', description: '', commission_rate: null });

function formatRate(rate) {
    if (rate == null) return '—';
    return (rate * 100).toFixed(2).replace(/\.?0+$/, '') + ' %';
}
const deleteForm = useForm({});

function openCreateDialog() {
    editingCategory.value = null;
    form.reset();
    formDialog.value = true;
}

function openEditDialog(category) {
    editingCategory.value = category;
    form.name = category.name;
    form.description = category.description ?? '';
    form.commission_rate = category.commission_rate ?? null;
    formDialog.value = true;
}

function closeFormDialog() {
    formDialog.value = false;
    editingCategory.value = null;
    form.reset();
}

function openDeleteDialog(category) {
    categoryToDelete.value = category;
    deleteDialog.value = true;
}

function saveCategory() {
    if (editingCategory.value) {
        form.put(route('product-categories.update', editingCategory.value.id), {
            onSuccess: () => closeFormDialog(),
        });
    } else {
        form.post(route('product-categories.store'), {
            onSuccess: () => closeFormDialog(),
        });
    }
}

function confirmDelete() {
    deleteForm.delete(route('product-categories.destroy', categoryToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            categoryToDelete.value = null;
        },
    });
}
</script>
