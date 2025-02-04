<template>
    <Head title="Caisses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Gestion des Caisses</h2>
                <v-btn color="primary" @click="openDialog()">
                    <v-icon start>mdi-plus</v-icon>
                    Nouvelle Caisse
                </v-btn>
            </div>
        </template>

        <!-- Flash Message Snackbar -->
        <v-snackbar
            v-model="snackbar"
            :timeout="3000"
            color="success"
        >
            {{ flashMessage }}
            <template v-slot:actions>
                <v-btn
                    color="white"
                    variant="text"
                    @click="snackbar = false"
                >
                    Fermer
                </v-btn>
            </template>
        </v-snackbar>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-data-table
                        :headers="[
                            { title: 'Nom', key: 'name' },
                            { title: 'Balance', key: 'balance' },
                            { title: 'Status', key: 'closed' },
                            { title: 'Actions', key: 'actions', sortable: false },
                        ]"
                        :items="caisses"
                    >
                        <template v-slot:item.balance="{ item }">
                            {{ formatAmount(item.balance) }}
                        </template>

                        <template v-slot:item.closed="{ item }">
                            <v-chip
                                :color="item.closed ? 'error' : 'success'"
                                :text="item.closed ? 'Fermée' : 'Ouverte'"
                            />
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <div class="flex gap-2">
                                <v-btn 
                                    icon="mdi-pencil" 
                                    variant="text" 
                                    color="primary"
                                    @click="openDialog(item)"
                                />
                                <v-btn 
                                    icon="mdi-delete" 
                                    variant="text" 
                                    color="error"
                                    @click="openDeleteDialog(item)"
                                />
                            </div>
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </div>

        <!-- Add/Edit Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>{{ editedItem ? 'Modifier la Caisse' : 'Nouvelle Caisse' }}</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            :error-messages="form.errors.name"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-text-field
                            v-model.number="form.balance"
                            label="Balance"
                            type="number"
                            :error-messages="form.errors.balance"
                            variant="outlined"
                            class="mb-4"
                        />
                        <v-checkbox
                            v-model="form.closed"
                            label="Fermée"
                            :error-messages="form.errors.closed"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                            <v-btn 
                                color="primary" 
                                type="submit" 
                                :loading="form.processing"
                            >
                                {{ editedItem ? 'Modifier' : 'Ajouter' }}
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Supprimer la caisse</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette caisse ?
                    <div v-if="itemToDelete" class="mt-4">
                        <strong>Détails de la caisse :</strong>
                        <div>Nom : {{ itemToDelete.name }}</div>
                        <div>Balance : {{ formatAmount(itemToDelete.balance) }}</div>
                        <div>Status : {{ itemToDelete.closed ? 'Fermée' : 'Ouverte' }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn 
                        color="primary" 
                        variant="text" 
                        @click="deleteDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn 
                        color="error" 
                        variant="text" 
                        @click="deleteCaisse"
                        :loading="form.processing"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    caisses: Array
});

const flash = computed(() => usePage().props.flash || {});
const flashMessage = computed(() => flash.value.success || '');
const snackbar = ref(false);

const dialog = ref(false);
const editedItem = ref(null);
const deleteDialog = ref(false);
const itemToDelete = ref(null);

// Watch for flash messages
watch(() => flash.value.success, (message) => {
    if (message) {
        snackbar.value = true;
    }
});

const form = useForm({
    name: '',
    balance: 0,
    closed: false
});

const formatAmount = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(amount);
};

const openDialog = (item = null) => {
    editedItem.value = item;
    if (item) {
        form.name = item.name;
        form.balance = item.balance;
        form.closed = item.closed;
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
        form.put(route('caisses.update', editedItem.value.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                editedItem.value = null;
            },
            onError: (errors) => {
                console.error('Update failed:', errors);
            }
        });
    } else {
        form.post(route('caisses.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            },
            onError: (errors) => {
                console.error('Create failed:', errors);
            }
        });
    }
};

const deleteCaisse = () => {
    if (itemToDelete.value) {
        form.delete(route('caisses.destroy', itemToDelete.value.id), {
            onSuccess: () => {
                deleteDialog.value = false;
                itemToDelete.value = null;
            },
        });
    }
};
</script>