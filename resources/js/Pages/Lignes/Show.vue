<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    ligne: Object,
    unassignedCustomers: Array,
    errors: Object,
    flash: Object,
});

// UI state
const assignCustomerDialog = ref(false);
const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('');

// Assign customer form
const assignCustomerForm = useForm({
    customer_id: '',
});

// Watch for flash messages
watch(() => props.flash, (newFlash) => {
    if (newFlash?.success) {
        snackbarText.value = newFlash.success;
        snackbarColor.value = 'success';
        snackbar.value = true;
    }
    if (newFlash?.error) {
        snackbarText.value = newFlash.error;
        snackbarColor.value = 'error';
        snackbar.value = true;
    }
}, { deep: true });

const assignCustomer = () => {
    assignCustomerForm.post(route('lignes.assign-customer', props.ligne.id), {
        onSuccess: () => {
            assignCustomerDialog.value = false;
            assignCustomerForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="'Ligne - ' + ligne.name" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Ligne: {{ ligne.name }}
            </h2>
        </template>

        <v-container>
            <!-- Ligne Details -->
            <v-row>
                <v-col cols="12" md="6">
                    <v-card>
                        <v-card-title>Détails de la ligne</v-card-title>
                        <v-card-text>
                            <v-list>
                                <v-list-item>
                                    <template v-slot:prepend>
                                        <v-icon>mdi-map-marker</v-icon>
                                    </template>
                                    <v-list-item-title>Zone</v-list-item-title>
                                    <v-list-item-subtitle>{{ ligne.zone?.name }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item>
                                    <template v-slot:prepend>
                                        <v-icon>mdi-account-delivery</v-icon>
                                    </template>
                                    <v-list-item-title>Livreur</v-list-item-title>
                                    <v-list-item-subtitle>{{ ligne.livreur?.name || 'Non assigné' }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item>
                                    <template v-slot:prepend>
                                        <v-icon>mdi-account-group</v-icon>
                                    </template>
                                    <v-list-item-title>Nombre de clients</v-list-item-title>
                                    <v-list-item-subtitle>{{ ligne.customers?.length || 0 }}</v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Customers Table -->
            <v-row class="mt-4">
                <v-col>
                    <v-card>
                        <v-card-title>
                            Clients de la ligne
                            <v-spacer />
                            <v-btn color="primary" @click="assignCustomerDialog = true">
                                Assigner un client
                            </v-btn>
                        </v-card-title>
                        <v-card-text>
                            <v-table>
                                <thead>
                                    <tr>
                                        <th>Nom</th>
                                        <th>Téléphone</th>
                                        <th>Adresse</th>
                                        <th>Nombre de ventes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="customer in ligne.customers" :key="customer.id">
                                        <td>{{ customer.name }}</td>
                                        <td>{{ customer.phone_number }}</td>
                                        <td>{{ customer.address }}</td>
                                        <td>{{ customer.ventes?.length || 0 }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Assign Customer Dialog -->
            <v-dialog v-model="assignCustomerDialog" max-width="500px">
                <v-card>
                    <v-card-title>Assigner un client</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="assignCustomer">
                            <v-select 
                                v-model="assignCustomerForm.customer_id" 
                                label="Client" 
                                :items="unassignedCustomers" 
                                item-title="name"
                                item-value="id"
                                :error-messages="errors?.customer_id"
                                persistent-hint
                                hint="Sélectionnez un client à assigner à cette ligne"
                                :loading="!unassignedCustomers.length"
                            />
                            <v-card-actions>
                                <v-spacer />
                                <v-btn @click="assignCustomerDialog = false">Annuler</v-btn>
                                <v-btn color="primary" type="submit" :loading="assignCustomerForm.processing">Assigner</v-btn>
                            </v-card-actions>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-dialog>

            <!-- Snackbar -->
            <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="3000">
                {{ snackbarText }}
                <template v-slot:actions>
                    <v-btn variant="text" @click="snackbar = false">Fermer</v-btn>
                </template>
            </v-snackbar>
        </v-container>
    </AuthenticatedLayout>
</template> 