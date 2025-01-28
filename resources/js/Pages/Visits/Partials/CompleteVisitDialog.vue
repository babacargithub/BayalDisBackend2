<template>
    <v-dialog :model-value="show" @update:model-value="$emit('close')" max-width="700px">
        <v-card>
            <v-card-title class="text-h5 pb-4">
                Terminer la visite
            </v-card-title>
            <v-card-text>
                <form @submit.prevent="submit">
                    <!-- Location Status -->
                    <div class="mb-4">
                        <div class="flex items-center gap-2 text-sm" :class="locationStatusClass">
                            <div v-if="isLoadingLocation" class="animate-spin rounded-full h-4 w-4 border-2 border-indigo-500 border-t-transparent"></div>
                            <span>{{ locationStatusText }}</span>
                        </div>
                    </div>

                    <!-- Result -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">Résultat de la visite</label>
                        <div class="mt-2 space-y-2">
                            <label class="flex items-center">
                                <input
                                    type="radio"
                                    v-model="form.resulted_in_sale"
                                    :value="true"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                />
                                <span class="ml-2">Vente réalisée</span>
                            </label>
                            <label class="flex items-center">
                                <input
                                    type="radio"
                                    v-model="form.resulted_in_sale"
                                    :value="false"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                />
                                <span class="ml-2">Pas de vente</span>
                            </label>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4">
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            class="mt-1 block w-full rounded-md border-gray-300"
                            rows="3"
                            placeholder="Détails de la visite..."
                        />
                    </div>

                    <!-- Create Order Button -->
                    <div v-if="form.resulted_in_sale" class="mb-4">
                        <Link
                            :href="route('orders.create', { customer_id: props.visit.customer.id })"
                            class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700"
                        >
                            Créer une commande
                        </Link>
                    </div>
                </form>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn
                    color="error"
                    variant="text"
                    @click="$emit('close')"
                >
                    Annuler
                </v-btn>
                <v-btn
                    color="primary"
                    :loading="form.processing"
                    :disabled="form.processing || isLoadingLocation"
                    @click="submit"
                >
                    Terminer la visite
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        required: true
    },
    visit: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['close']);

const form = useForm({
    resulted_in_sale: false,
    notes: '',
    gps_coordinates: null
});

const isLoadingLocation = ref(true);
const locationError = ref(null);

const locationStatusClass = computed(() => {
    if (isLoadingLocation.value) return 'text-gray-600';
    if (locationError.value) return 'text-red-600';
    return 'text-green-600';
});

const locationStatusText = computed(() => {
    if (isLoadingLocation.value) return 'Récupération de la position...';
    if (locationError.value) return 'Erreur: ' + locationError.value;
    return 'Position récupérée avec succès';
});

const getLocation = () => {
    if (!navigator.geolocation) {
        locationError.value = 'La géolocalisation n\'est pas supportée par votre navigateur';
        isLoadingLocation.value = false;
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            form.gps_coordinates = `${position.coords.latitude},${position.coords.longitude}`;
            isLoadingLocation.value = false;
        },
        (error) => {
            locationError.value = error.message;
            isLoadingLocation.value = false;
        }
    );
};

const submit = () => {
    form.post(route('visits.customer-visits.complete', props.visit.id), {
        onSuccess: () => {
            emit('close');
            form.reset();
        }
    });
};

onMounted(() => {
    getLocation();
});
</script> 