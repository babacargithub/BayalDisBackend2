<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    clients: Array,
    commerciaux: Array
});

const form = useForm({
    name: '',
    phone_number: '',
    owner_number: '',
    gps_coordinates: '',
    commercial_id: '',
});

const dialog = ref(false);

const submit = () => {
    form.post(route('clients.store'), {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Clients</h2>
                <v-btn color="primary" @click="dialog = true">
                    Ajouter un client
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
                                <th>Numéro Propriétaire</th>
                                <th>Commercial</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="client in clients" :key="client.id">
                                <td>{{ client.name }}</td>
                                <td>{{ client.phone_number }}</td>
                                <td>{{ client.owner_number }}</td>
                                <td>{{ client.commercial.name }}</td>
                                <td>
                                    <v-btn icon="mdi-pencil" variant="text" color="primary" />
                                    <v-btn icon="mdi-delete" variant="text" color="error" />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>Nouveau Client</v-card-title>
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
                        <v-text-field
                            v-model="form.owner_number"
                            label="Numéro Propriétaire"
                            :error-messages="form.errors.owner_number"
                        />
                        <v-text-field
                            v-model="form.gps_coordinates"
                            label="Coordonnées GPS"
                            :error-messages="form.errors.gps_coordinates"
                        />
                        <v-select
                            v-model="form.commercial_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            :error-messages="form.errors.commercial_id"
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
    </AuthenticatedLayout>
</template> 