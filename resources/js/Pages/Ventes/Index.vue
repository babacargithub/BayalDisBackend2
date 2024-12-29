<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    ventes: Array,
    produits: Array,
    clients: Array,
    commerciaux: Array,
    filters: Object
});

const form = useForm({
    product_id: '',
    customer_id: '',
    commercial_id: '',
    quantity: '',
    price: '',
    should_be_paid_at: '',
});

const dialog = ref(false);
const filterDialog = ref(false);

const filterForm = useForm({
    date_debut: filters?.date_debut || '',
    date_fin: filters?.date_fin || '',
    paid: filters?.paid || '',
    commercial_id: filters?.commercial_id || '',
});

const submit = () => {
    form.post(route('ventes.store'), {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    });
};

const applyFilters = () => {
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            filterDialog.value = false;
        },
    });
};

const formatPrice = (price) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(price);
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR');
};

const togglePaid = (vente) => {
    useForm({
        paid: !vente.paid,
    }).patch(route('ventes.update', vente.id));
};
</script>

<template>
    <Head title="Ventes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ventes</h2>
                <div class="flex gap-2">
                    <v-btn color="secondary" @click="filterDialog = true">
                        Filtrer
                    </v-btn>
                    <v-btn color="primary" @click="dialog = true">
                        Nouvelle vente
                    </v-btn>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Produit</th>
                                <th>Client</th>
                                <th>Commercial</th>
                                <th>Quantité</th>
                                <th>Prix Total</th>
                                <th>Statut</th>
                                <th>Date Échéance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="vente in ventes" :key="vente.id">
                                <td>{{ formatDate(vente.created_at) }}</td>
                                <td>{{ vente.produit.name }}</td>
                                <td>{{ vente.client.name }}</td>
                                <td>{{ vente.commercial.name }}</td>
                                <td>{{ vente.quantity }}</td>
                                <td>{{ formatPrice(vente.price * vente.quantity) }}</td>
                                <td>
                                    <v-chip
                                        :color="vente.paid ? 'success' : 'error'"
                                        @click="togglePaid(vente)"
                                    >
                                        {{ vente.paid ? 'Payé' : 'Non payé' }}
                                    </v-chip>
                                </td>
                                <td>{{ formatDate(vente.should_be_paid_at) }}</td>
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

        <!-- Nouvelle Vente Dialog -->
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>Nouvelle Vente</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-select
                            v-model="form.product_id"
                            :items="produits"
                            item-title="name"
                            item-value="id"
                            label="Produit"
                            :error-messages="form.errors.product_id"
                        />
                        <v-select
                            v-model="form.customer_id"
                            :items="clients"
                            item-title="name"
                            item-value="id"
                            label="Client"
                            :error-messages="form.errors.customer_id"
                        />
                        <v-select
                            v-model="form.commercial_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            :error-messages="form.errors.commercial_id"
                        />
                        <v-text-field
                            v-model="form.quantity"
                            label="Quantité"
                            type="number"
                            :error-messages="form.errors.quantity"
                        />
                        <v-text-field
                            v-model="form.price"
                            label="Prix unitaire"
                            type="number"
                            :error-messages="form.errors.price"
                        />
                        <v-text-field
                            v-model="form.should_be_paid_at"
                            label="Date d'échéance"
                            type="date"
                            :error-messages="form.errors.should_be_paid_at"
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

        <!-- Filtres Dialog -->
        <v-dialog v-model="filterDialog" max-width="500px">
            <v-card>
                <v-card-title>Filtrer les ventes</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="applyFilters">
                        <v-text-field
                            v-model="filterForm.date_debut"
                            label="Date début"
                            type="date"
                        />
                        <v-text-field
                            v-model="filterForm.date_fin"
                            label="Date fin"
                            type="date"
                        />
                        <v-select
                            v-model="filterForm.paid"
                            :items="[
                                { title: 'Tous', value: '' },
                                { title: 'Payé', value: true },
                                { title: 'Non payé', value: false }
                            ]"
                            label="Statut de paiement"
                        />
                        <v-select
                            v-model="filterForm.commercial_id"
                            :items="[{ title: 'Tous', value: '' }, ...commerciaux]"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="filterDialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="filterForm.processing">
                                Appliquer
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 