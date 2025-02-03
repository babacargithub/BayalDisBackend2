<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Planifier des visites
                </h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6">
                        <!-- Batch Information -->
                        <div class="mb-8">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                Informations du lot
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="name" value="Nom du lot" />
                                    <TextInput
                                        id="name"
                                        v-model="form.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError :message="form.errors.name" class="mt-2" />
                                </div>
                                <div>
                                    <InputLabel for="visit_date" value="Date des visites" />
                                    <TextInput
                                        id="visit_date"
                                        v-model="form.visit_date"
                                        type="date"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError :message="form.errors.visit_date" class="mt-2" />
                                </div>
                                <div>
                                    <InputLabel for="commercial_id" value="Commercial" />
                                    <select
                                        id="commercial_id"
                                        v-model="form.commercial_id"
                                        class="mt-1 block w-full rounded-md border-gray-300"
                                        required
                                    >
                                        <option value="">Sélectionner un commercial</option>
                                        <option
                                            v-for="commercial in commercials"
                                            :key="commercial.id"
                                            :value="commercial.id"
                                        >
                                            {{ commercial.name }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.commercial_id" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Visits List -->
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-medium text-gray-900">
                                    Visites planifiées
                                </h3>
                                <button
                                    type="button"
                                    @click="addVisit"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                                >
                                    Ajouter une visite
                                </button>
                            </div>

                            <div v-if="!form.visits.length" class="text-center py-12 bg-gray-50 rounded-lg">
                                <v-icon
                                    icon="mdi-map-marker-plus"
                                    size="48"
                                    class="text-gray-400 mb-4"
                                />
                                <p class="text-gray-500">
                                    Cliquez sur "Ajouter une visite" pour commencer à planifier vos visites.
                                </p>
                            </div>

                            <div v-else class="space-y-4">
                                <div
                                    v-for="(visit, index) in form.visits"
                                    :key="index"
                                    class="border rounded-lg p-4"
                                >
                                    <div class="flex justify-between items-start mb-4">
                                        <h4 class="font-medium text-gray-900">
                                            Visite {{ index + 1 }}
                                        </h4>
                                        <button
                                            type="button"
                                            @click="removeVisit(index)"
                                            class="text-red-600 hover:text-red-800"
                                        >
                                            <v-icon icon="mdi-delete" />
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel :for="'customer_' + index" value="Client" />
                                            <v-autocomplete
                                                :id="'customer_' + index"
                                                v-model="visit.customer_id"
                                                v-model:search="search"
                                                :items="filteredCustomers"
                                                item-title="name"
                                                item-value="id"
                                                :error-messages="form.errors['visits.' + index + '.customer_id']"
                                                label="Sélectionner un client"
                                                required
                                            >
                                                <template v-slot:item="{ item, props: itemProps }">
                                                    <v-list-item v-bind="itemProps">
                                                        <template v-slot:prepend>
                                                            <v-checkbox-btn
                                                                :model-value="visit.customer_id === item.value"
                                                                @update:model-value="visit.customer_id = item.value"
                                                            />
                                                        </template>
                                                        <v-list-item-title>{{ item.title }}</v-list-item-title>
                                                        <v-list-item-subtitle>
                                                            {{ item.raw.phone_number }} - {{ item.raw.address }}
                                                        </v-list-item-subtitle>
                                                    </v-list-item>
                                                </template>
                                            </v-autocomplete>
                                        </div>
                                        <div>
                                            <InputLabel :for="'time_' + index" value="Heure prévue" />
                                            <TextInput
                                                :id="'time_' + index"
                                                v-model="visit.visit_planned_at"
                                                type="time"
                                                class="mt-1 block w-full"
                                                required
                                            />
                                            <InputError
                                                :message="form.errors['visits.' + index + '.visit_planned_at']"
                                                class="mt-2"
                                            />
                                        </div>
                                        <div class="md:col-span-2">
                                            <InputLabel :for="'notes_' + index" value="Notes" />
                                            <textarea
                                                :id="'notes_' + index"
                                                v-model="visit.notes"
                                                class="mt-1 block w-full rounded-md border-gray-300"
                                                rows="2"
                                            />
                                            <InputError
                                                :message="form.errors['visits.' + index + '.notes']"
                                                class="mt-2"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex justify-end space-x-4">
                            <Link
                                :href="route('visits.index')"
                                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50"
                            >
                                Annuler
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                            >
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { ref, watch } from 'vue';

const props = defineProps({
    customers: {
        type: Array,
        required: true
    },
    commercials: {
        type: Array,
        required: true
    }
});

const form = useForm({
    name: '',
    visit_date: '',
    commercial_id: '',
    visits: []
});

const search = ref('');
const filteredCustomers = ref([...props.customers]);

// Watch for changes in the search input
watch(search, (val) => {
    if (!val) {
        filteredCustomers.value = props.customers;
        return;
    }
    
    // Filter customers based on search term
    filteredCustomers.value = props.customers.filter(customer => 
        customer.name.toLowerCase().includes(val.toLowerCase()) ||
        customer.phone_number?.toLowerCase().includes(val.toLowerCase()) ||
        customer.address?.toLowerCase().includes(val.toLowerCase())
    );
});

const addVisit = () => {
    form.visits.push({
        customer_id: '',
        visit_planned_at: '',
        notes: ''
    });
};

const removeVisit = (index) => {
    form.visits.splice(index, 1);
};

const submit = () => {
    form.post(route('visits.store'), {
        onSuccess: () => {
            form.reset();
        }
    });
};
</script> 