<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    commerciaux: Array
});

const form = useForm({
    name: '',
    phone_number: '',
    gender: '',
});

const dialog = ref(false);

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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="commercial in commerciaux" :key="commercial.id">
                                <td>{{ commercial.name }}</td>
                                <td>{{ commercial.phone_number }}</td>
                                <td>{{ commercial.gender === 'male' ? 'Homme' : 'Femme' }}</td>
                                <td>{{ commercial.clients.length }}</td>
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
                            label="Genre"
                            :error-messages="form.errors.gender"
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