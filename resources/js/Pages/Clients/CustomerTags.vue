<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    customerTags: {
        type: Array,
        default: () => [],
    },
    flash: Object,
});

// ─── Available colors ──────────────────────────────────────────────────────────

const availableColors = [
    { label: 'Bleu', value: '#1976D2' },
    { label: 'Vert', value: '#388E3C' },
    { label: 'Rouge', value: '#D32F2F' },
    { label: 'Orange', value: '#F57C00' },
    { label: 'Violet', value: '#7B1FA2' },
    { label: 'Rose', value: '#C2185B' },
    { label: 'Cyan', value: '#0097A7' },
    { label: 'Gris', value: '#616161' },
    { label: 'Brun', value: '#5D4037' },
    { label: 'Indigo', value: '#303F9F' },
];

// ─── Create ────────────────────────────────────────────────────────────────────

const createDialogVisible = ref(false);

const createForm = useForm({
    name: '',
    color: '#1976D2',
});

const submitCreate = () => {
    createForm.post(route('customer-tags.store'), {
        onSuccess: () => {
            createDialogVisible.value = false;
            createForm.reset();
        },
    });
};

// ─── Edit ──────────────────────────────────────────────────────────────────────

const editDialogVisible = ref(false);
const editingTag = ref(null);

const editForm = useForm({
    name: '',
    color: '#1976D2',
});

const openEditDialog = (tag) => {
    editingTag.value = tag;
    editForm.name = tag.name;
    editForm.color = tag.color;
    editDialogVisible.value = true;
};

const submitEdit = () => {
    editForm.put(route('customer-tags.update', editingTag.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editDialogVisible.value = false;
            editingTag.value = null;
        },
    });
};

// ─── Delete ────────────────────────────────────────────────────────────────────

const deleteDialogVisible = ref(false);
const tagToDelete = ref(null);

const confirmDelete = (tag) => {
    tagToDelete.value = tag;
    deleteDialogVisible.value = true;
};

const deleteForm = useForm({});

const deleteTag = () => {
    deleteForm.delete(route('customer-tags.destroy', tagToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogVisible.value = false;
            tagToDelete.value = null;
        },
    });
};

// ─── Snackbar ──────────────────────────────────────────────────────────────────

const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('success');

const showSnackbar = (text, color = 'success') => {
    snackbarText.value = text;
    snackbarColor.value = color;
    snackbar.value = true;
};

watch(() => props.flash, (newFlash) => {
    if (!newFlash) return;
    if (newFlash.success) showSnackbar(newFlash.success, 'success');
    if (newFlash.error) showSnackbar(newFlash.error, 'error');
}, { deep: true, immediate: true });
</script>

<template>
    <Head title="Étiquettes Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Étiquettes Clients</h2>
                <v-btn color="primary" @click="createDialogVisible = true" prepend-icon="mdi-plus">
                    Nouvelle étiquette
                </v-btn>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Étiquette</th>
                                <th>Clients</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tag in customerTags" :key="tag.id">
                                <td>
                                    <v-chip :color="tag.color" variant="flat" size="small" class="text-white font-weight-medium">
                                        {{ tag.name }}
                                    </v-chip>
                                </td>
                                <td>{{ tag.customers_count }} client(s)</td>
                                <td>
                                    <v-btn
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        size="small"
                                        @click="openEditDialog(tag)"
                                        title="Modifier"
                                    />
                                    <v-btn
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        size="small"
                                        @click="confirmDelete(tag)"
                                        title="Supprimer"
                                    />
                                </td>
                            </tr>
                            <tr v-if="customerTags.length === 0">
                                <td colspan="3" class="text-center text-grey py-8">
                                    Aucune étiquette créée
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <!-- Create Dialog -->
        <v-dialog v-model="createDialogVisible" max-width="400px">
            <v-card>
                <v-card-title>Nouvelle étiquette</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submitCreate">
                        <v-text-field
                            v-model="createForm.name"
                            label="Nom de l'étiquette"
                            :error-messages="createForm.errors.name"
                            autofocus
                        />
                        <div class="text-subtitle-2 mb-2">Couleur</div>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <v-btn
                                v-for="colorOption in availableColors"
                                :key="colorOption.value"
                                :color="colorOption.value"
                                size="small"
                                variant="flat"
                                class="text-white"
                                :class="{ 'elevation-8': createForm.color === colorOption.value }"
                                :style="createForm.color === colorOption.value ? 'outline: 3px solid #000; outline-offset: 2px;' : ''"
                                @click="createForm.color = colorOption.value"
                            >
                                {{ colorOption.label }}
                            </v-btn>
                        </div>
                        <div class="d-flex align-center gap-2 mb-4">
                            <span class="text-subtitle-2">Aperçu :</span>
                            <v-chip :color="createForm.color" variant="flat" size="small" class="text-white">
                                {{ createForm.name || 'Exemple' }}
                            </v-chip>
                        </div>
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" variant="text" @click="createDialogVisible = false">Annuler</v-btn>
                    <v-btn color="primary" @click="submitCreate" :loading="createForm.processing">Créer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Edit Dialog -->
        <v-dialog v-model="editDialogVisible" max-width="400px">
            <v-card>
                <v-card-title>Modifier l'étiquette</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submitEdit">
                        <v-text-field
                            v-model="editForm.name"
                            label="Nom de l'étiquette"
                            :error-messages="editForm.errors.name"
                        />
                        <div class="text-subtitle-2 mb-2">Couleur</div>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <v-btn
                                v-for="colorOption in availableColors"
                                :key="colorOption.value"
                                :color="colorOption.value"
                                size="small"
                                variant="flat"
                                class="text-white"
                                :style="editForm.color === colorOption.value ? 'outline: 3px solid #000; outline-offset: 2px;' : ''"
                                @click="editForm.color = colorOption.value"
                            >
                                {{ colorOption.label }}
                            </v-btn>
                        </div>
                        <div class="d-flex align-center gap-2 mb-4">
                            <span class="text-subtitle-2">Aperçu :</span>
                            <v-chip :color="editForm.color" variant="flat" size="small" class="text-white">
                                {{ editForm.name || 'Exemple' }}
                            </v-chip>
                        </div>
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" variant="text" @click="editDialogVisible = false">Annuler</v-btn>
                    <v-btn color="primary" @click="submitEdit" :loading="editForm.processing">Enregistrer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Dialog -->
        <v-dialog v-model="deleteDialogVisible" max-width="400px">
            <v-card>
                <v-card-title>Supprimer l'étiquette</v-card-title>
                <v-card-text>
                    <p>Êtes-vous sûr de vouloir supprimer l'étiquette
                        <v-chip v-if="tagToDelete" :color="tagToDelete.color" variant="flat" size="small" class="text-white mx-1">
                            {{ tagToDelete?.name }}
                        </v-chip>
                        ? Elle sera retirée de tous les clients.
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deleteDialogVisible = false">Annuler</v-btn>
                    <v-btn color="error" @click="deleteTag" :loading="deleteForm.processing">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="3000">
            {{ snackbarText }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar = false">Fermer</v-btn>
            </template>
        </v-snackbar>
    </AuthenticatedLayout>
</template>
