<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Planifier un beat récurrent
                </h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6">
                        <!-- Beat Information -->
                        <div class="mb-8">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                Informations du beat
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="name" value="Nom du beat" />
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
                                    <InputLabel for="day_of_week" value="Jour de la semaine" />
                                    <select
                                        id="day_of_week"
                                        v-model="form.day_of_week"
                                        class="mt-1 block w-full rounded-md border-gray-300"
                                        required
                                    >
                                        <option value="">Sélectionner un jour</option>
                                        <option
                                            v-for="day in days_of_week"
                                            :key="day.value"
                                            :value="day.value"
                                        >
                                            {{ day.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.day_of_week" class="mt-2" />
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

                        <!-- Stops List -->
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-medium text-gray-900">
                                    Clients à visiter
                                    <span v-if="form.day_of_week" class="text-gray-500 font-normal text-sm ml-2">
                                        (chaque {{ selectedDayLabel?.toLowerCase() }})
                                    </span>
                                </h3>
                                <button
                                    type="button"
                                    @click="addStop"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                                >
                                    Ajouter un client
                                </button>
                            </div>

                            <div v-if="!form.stops.length" class="text-center py-12 bg-gray-50 rounded-lg">
                                <v-icon
                                    icon="mdi-account-plus"
                                    size="48"
                                    class="text-gray-400 mb-4"
                                />
                                <p class="text-gray-500">
                                    Cliquez sur "Ajouter un client" pour définir les clients récurrents.
                                </p>
                            </div>

                            <div v-else class="space-y-4">
                                <div
                                    v-for="(stop, index) in form.stops"
                                    :key="index"
                                    class="border rounded-lg p-4"
                                >
                                    <div class="flex justify-between items-start mb-4">
                                        <h4 class="font-medium text-gray-900">
                                            Client {{ index + 1 }}
                                        </h4>
                                        <button
                                            type="button"
                                            @click="removeStop(index)"
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
                                                v-model="stop.customer_id"
                                                v-model:search="search"
                                                :items="filteredCustomers"
                                                item-title="name"
                                                item-value="id"
                                                :error-messages="form.errors['stops.' + index + '.customer_id']"
                                                label="Sélectionner un client"
                                                required
                                            >
                                                <template v-slot:item="{ item, props: itemProps }">
                                                    <v-list-item v-bind="itemProps">
                                                        <template v-slot:prepend>
                                                            <v-checkbox-btn
                                                                :model-value="stop.customer_id === item.value"
                                                                @update:model-value="stop.customer_id = item.value"
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
                                        <div class="md:col-span-2">
                                            <InputLabel :for="'notes_' + index" value="Notes" />
                                            <textarea
                                                :id="'notes_' + index"
                                                v-model="stop.notes"
                                                class="mt-1 block w-full rounded-md border-gray-300"
                                                rows="2"
                                            />
                                            <InputError
                                                :message="form.errors['stops.' + index + '.notes']"
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
                                :href="route('beats.index')"
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
import { computed, ref, watch } from 'vue';

const props = defineProps({
    customers: {
        type: Array,
        required: true
    },
    commercials: {
        type: Array,
        required: true
    },
    days_of_week: {
        type: Array,
        required: true
    }
});

const form = useForm({
    name: '',
    day_of_week: '',
    commercial_id: '',
    stops: []
});

const search = ref('');
const filteredCustomers = ref([...props.customers]);

const selectedDayLabel = computed(() => {
    return props.days_of_week.find(d => d.value === form.day_of_week)?.label;
});

watch(search, (val) => {
    if (!val) {
        filteredCustomers.value = props.customers;
        return;
    }

    filteredCustomers.value = props.customers.filter(customer =>
        customer.name.toLowerCase().includes(val.toLowerCase()) ||
        customer.phone_number?.toLowerCase().includes(val.toLowerCase()) ||
        customer.address?.toLowerCase().includes(val.toLowerCase())
    );
});

const addStop = () => {
    form.stops.push({
        customer_id: '',
        notes: ''
    });
};

const removeStop = (index) => {
    form.stops.splice(index, 1);
};

const submit = () => {
    form.post(route('beats.store'), {
        onSuccess: () => {
            form.reset();
        }
    });
};
</script>
