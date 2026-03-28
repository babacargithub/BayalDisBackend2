<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    pricingPolicies: {
        type: Array,
        required: true,
    },
});

const dialog = ref(false);
const editedPolicy = ref(null);

const form = useForm({
    name: '',
    surcharge_percent: 0,
    grace_days: 0,
    apply_to_deferred_only: true,
    apply_credit_price: false,
});

const openCreateDialog = () => {
    editedPolicy.value = null;
    form.reset();
    form.surcharge_percent = 0;
    form.grace_days = 0;
    form.apply_to_deferred_only = true;
    form.apply_credit_price = false;
    dialog.value = true;
};

const openEditDialog = (policy) => {
    editedPolicy.value = policy;
    form.name = policy.name;
    form.surcharge_percent = policy.surcharge_percent;
    form.grace_days = policy.grace_days;
    form.apply_to_deferred_only = policy.apply_to_deferred_only;
    form.apply_credit_price = policy.apply_credit_price;
    dialog.value = true;
};

const submit = () => {
    if (editedPolicy.value) {
        form.put(route('pricing-policies.update', editedPolicy.value.id), {
            onSuccess: () => {
                dialog.value = false;
                editedPolicy.value = null;
            },
        });
    } else {
        form.post(route('pricing-policies.store'), {
            onSuccess: () => {
                dialog.value = false;
            },
        });
    }
};

const activatePolicy = (policy) => {
    router.post(route('pricing-policies.activate', policy.id));
};
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Politique de prix
                </h2>
                <button
                    @click="openCreateDialog"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
                >
                    <v-icon icon="mdi-plus" size="small" class="mr-2" />
                    Nouvelle politique
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Nom
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Majoration
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Jours de grâce
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Différé uniquement
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Prix crédit
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Statut
                                        </th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr
                                        v-for="policy in pricingPolicies"
                                        :key="policy.id"
                                        :class="{ 'bg-green-50': policy.active }"
                                    >
                                        <td class="px-6 py-4 font-medium text-gray-900">
                                            {{ policy.name }}
                                        </td>
                                        <td class="px-6 py-4">
                                            {{ policy.surcharge_percent }}%
                                        </td>
                                        <td class="px-6 py-4">
                                            {{ policy.grace_days }} jour{{ policy.grace_days !== 1 ? 's' : '' }}
                                        </td>
                                        <td class="px-6 py-4">
                                            <v-icon
                                                :icon="policy.apply_to_deferred_only ? 'mdi-check-circle' : 'mdi-close-circle'"
                                                :color="policy.apply_to_deferred_only ? 'success' : 'error'"
                                            />
                                        </td>
                                        <td class="px-6 py-4">
                                            <v-icon
                                                :icon="policy.apply_credit_price ? 'mdi-check-circle' : 'mdi-close-circle'"
                                                :color="policy.apply_credit_price ? 'success' : 'error'"
                                            />
                                        </td>
                                        <td class="px-6 py-4">
                                            <v-chip
                                                v-if="policy.active"
                                                color="success"
                                                size="small"
                                                prepend-icon="mdi-check"
                                            >
                                                Active
                                            </v-chip>
                                            <v-chip v-else color="default" size="small">
                                                Inactive
                                            </v-chip>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right space-x-2">
                                            <v-btn
                                                v-if="!policy.active"
                                                size="small"
                                                color="success"
                                                variant="tonal"
                                                prepend-icon="mdi-check-circle"
                                                @click="activatePolicy(policy)"
                                            >
                                                Activer
                                            </v-btn>
                                            <v-btn
                                                size="small"
                                                color="primary"
                                                variant="text"
                                                icon="mdi-pencil"
                                                @click="openEditDialog(policy)"
                                            />
                                        </td>
                                    </tr>
                                    <tr v-if="pricingPolicies.length === 0">
                                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                            Aucune politique de prix définie
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create / Edit Dialog -->
        <v-dialog v-model="dialog" max-width="520px">
            <v-card>
                <v-card-title class="text-h6 pb-2">
                    {{ editedPolicy ? 'Modifier la politique' : 'Nouvelle politique de prix' }}
                </v-card-title>

                <v-card-text>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="block w-full rounded-md border-gray-300 shadow-sm"
                                placeholder="Ex: Politique crédit 30 jours"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Majoration (%) — appliquée sur le prix normal
                            </label>
                            <input
                                v-model.number="form.surcharge_percent"
                                type="number"
                                min="0"
                                max="100"
                                class="block w-full rounded-md border-gray-300 shadow-sm"
                            />
                            <p v-if="form.errors.surcharge_percent" class="mt-1 text-sm text-red-600">{{ form.errors.surcharge_percent }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Jours de grâce avant application de la majoration
                            </label>
                            <input
                                v-model.number="form.grace_days"
                                type="number"
                                min="0"
                                class="block w-full rounded-md border-gray-300 shadow-sm"
                            />
                            <p v-if="form.errors.grace_days" class="mt-1 text-sm text-red-600">{{ form.errors.grace_days }}</p>
                        </div>

                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-sm font-medium text-gray-700">Appliquer aux ventes différées uniquement</p>
                                <p class="text-xs text-gray-500">Si activé, la majoration ne s'applique qu'aux factures dont la date d'échéance dépasse les jours de grâce</p>
                            </div>
                            <v-switch
                                v-model="form.apply_to_deferred_only"
                                color="primary"
                                hide-details
                            />
                        </div>

                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-sm font-medium text-gray-700">Utiliser le prix crédit</p>
                                <p class="text-xs text-gray-500">Si activé, les factures impayées utilisent le prix crédit du produit au lieu du prix normal</p>
                            </div>
                            <v-switch
                                v-model="form.apply_credit_price"
                                color="primary"
                                hide-details
                            />
                        </div>
                    </div>
                </v-card-text>

                <v-card-actions>
                    <v-spacer />
                    <v-btn color="error" variant="text" @click="dialog = false">Annuler</v-btn>
                    <v-btn color="primary" :loading="form.processing" @click="submit">
                        {{ editedPolicy ? 'Modifier' : 'Créer' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>
