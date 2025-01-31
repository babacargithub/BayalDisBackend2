<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Fournisseurs</h2>
                <v-btn color="primary" @click="dialog = true">
                    <v-icon>mdi-plus</v-icon>
                    Ajouter un fournisseur
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
                                <th>Contact</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th>Adresse</th>
                                <th>Numéro fiscal</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="supplier in suppliers" :key="supplier.id">
                                <td>{{ supplier.name }}</td>
                                <td>{{ supplier.contact }}</td>
                                <td>{{ supplier.email }}</td>
                                <td>{{ supplier.phone }}</td>
                                <td>{{ supplier.address }}</td>
                                <td>{{ supplier.tax_number }}</td>
                                <td>
                                    <v-btn 
                                        icon="mdi-pencil"
                                        variant="text"
                                        color="primary"
                                        class="mr-2"
                                        @click="editSupplier(supplier)"
                                    />
                                    <v-btn 
                                        icon="mdi-delete"
                                        variant="text"
                                        color="error"
                                        @click="deleteSupplier(supplier)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <v-dialog v-model="dialog" max-width="600px">
            <v-card>
                <v-card-title>
                    {{ editingSupplier ? 'Modifier le fournisseur' : 'Ajouter un fournisseur' }}
                </v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="saveSupplier">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            required
                            :error-messages="form.errors.name"
                        />
                        <v-text-field
                            v-model="form.contact"
                            label="Contact"
                            :error-messages="form.errors.contact"
                        />
                        <v-text-field
                            v-model="form.email"
                            label="Email"
                            type="email"
                            :error-messages="form.errors.email"
                        />
                        <v-text-field
                            v-model="form.phone"
                            label="Téléphone"
                            :error-messages="form.errors.phone"
                        />
                        <v-textarea
                            v-model="form.address"
                            label="Adresse"
                            :error-messages="form.errors.address"
                        />
                        <v-text-field
                            v-model="form.tax_number"
                            label="Numéro fiscal"
                            :error-messages="form.errors.tax_number"
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        @click="saveSupplier"
                        :loading="form.processing"
                    >
                        {{ editingSupplier ? 'Modifier' : 'Ajouter' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title>Supprimer le fournisseur</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer ce fournisseur ?
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        @click="confirmDelete"
                        :loading="deleteForm.processing"
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

const props = defineProps({
    suppliers: {
        type: Array,
        default: () => []
    }
});

const dialog = ref(false);
const deleteDialog = ref(false);
const editingSupplier = ref(null);
const supplierToDelete = ref(null);

const form = useForm({
    name: '',
    contact: '',
    email: '',
    phone: '',
    address: '',
    tax_number: ''
});

const deleteForm = useForm({});

function editSupplier(supplier) {
    editingSupplier.value = supplier;
    form.name = supplier.name;
    form.contact = supplier.contact;
    form.email = supplier.email;
    form.phone = supplier.phone;
    form.address = supplier.address;
    form.tax_number = supplier.tax_number;
    dialog.value = true;
}

function deleteSupplier(supplier) {
    supplierToDelete.value = supplier;
    deleteDialog.value = true;
}

function confirmDelete() {
    deleteForm.delete(route('suppliers.destroy', supplierToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            supplierToDelete.value = null;
        }
    });
}

function saveSupplier() {
    if (editingSupplier.value) {
        form.put(route('suppliers.update', editingSupplier.value.id), {
            onSuccess: () => {
                dialog.value = false;
                editingSupplier.value = null;
                form.reset();
            }
        });
    } else {
        form.post(route('suppliers.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            }
        });
    }
}
</script> 