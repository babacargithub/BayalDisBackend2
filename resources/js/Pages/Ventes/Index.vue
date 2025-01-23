<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    ventes: Object,
    produits: Array,
    clients: Array,
    commerciaux: Array,
    filters: Object,
    statistics: Object
});

const form = useForm({
    product_id: '',
    customer_id: '',
    commercial_id: '',
    quantity: '',
    price: '',
    payment_status: 'paid',
    should_be_paid_at: '',
    paid: true,
});

const dialog = ref(false);
const filterDialog = ref(false);
const deleteDialog = ref(false);
const venteToDelete = ref(null);
const currentPage = ref(1);

const filterForm = useForm({
    date_debut: props.filters?.date_debut || '',
    date_fin: props.filters?.date_fin || '',
    paid: props.filters?.paid === true ? true : props.filters?.paid === false ? false : '',
    commercial_id: props.filters?.commercial_id || '',
});

const productStats = computed(() => {
    if (!props.ventes?.data || !props.ventes.data.length) return [];
    
    const stats = {};
    props.ventes.data.forEach(vente => {
        if (!vente?.product?.id) return;
        
        if (!stats[vente.product.id]) {
            stats[vente.product.id] = {
                product: vente.product,
                totalQuantity: 0,
                totalAmount: 0
            };
        }
        stats[vente.product.id].totalQuantity += vente.quantity;
        stats[vente.product.id].totalAmount += vente.price * vente.quantity;
    });
    
    return Object.values(stats);
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

const applyPaidFilter = () => {
    filterForm.get(route('ventes.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

watch(() => filterForm.paid, (newValue) => {
    applyPaidFilter();
});

watch(() => form.payment_status, (newValue) => {
    form.paid = newValue === 'paid';
    if (newValue === 'paid') {
        form.should_be_paid_at = '';
    }
});

watch(() => form.product_id, (newValue) => {
    if (newValue) {
        const selectedProduct = props.produits.find(p => p.id === newValue);
        if (selectedProduct) {
            form.price = selectedProduct.price;
        }
    } else {
        form.price = '';
    }
});

const formatPrice = (price) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(price);
};

const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('fr-FR');
};

const togglePaid = (vente) => {
    useForm({
        paid: !vente.paid,
    }).patch(route('ventes.update', vente.id));
};

const formatNumber = (number) => {
    return new Intl.NumberFormat('fr-FR').format(number || 0);
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR', { 
        style: 'currency', 
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount || 0);
};

const confirmDelete = (vente) => {
    venteToDelete.value = vente;
    deleteDialog.value = true;
};

const deleteVente = () => {
    router.delete(route('ventes.destroy', venteToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            venteToDelete.value = null;
        },
    });
};

const changePage = (page) => {
    filterForm.get(route('ventes.index', { page }), {
        preserveState: true,
        preserveScroll: true,
    });
};

watch([() => filterForm.date_debut, () => filterForm.date_fin, () => filterForm.commercial_id], () => {
    currentPage.value = 1;
});
</script>

<template>
    <Head title="Ventes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ventes</h2>
                <div class="flex gap-2">
                    <v-btn-group class="mr-2">
                        <v-btn 
                            :color="filterForm.paid === '' ? 'primary' : undefined"
                            @click="filterForm.paid = ''"
                        >
                            Tous
                        </v-btn>
                        <v-btn 
                            :color="filterForm.paid === true ? 'primary' : undefined"
                            @click="filterForm.paid = true"
                        >
                            Payées
                        </v-btn>
                        <v-btn 
                            :color="filterForm.paid === false ? 'primary' : undefined"
                            @click="filterForm.paid = false"
                        >
                            Impayées
                        </v-btn>
                    </v-btn-group>
                    <v-btn color="secondary" @click="filterDialog = true">
                        Plus de filtres
                    </v-btn>
                    <v-btn color="primary" @click="dialog = true">
                        Nouvelle vente
                    </v-btn>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <v-row class="mb-6">
                    <v-col cols="12" md="3">
                        <v-card elevation="2" class="rounded-lg">
                            <v-card-item>
                                <div class="d-flex justify-space-between align-center">
                                    <div>
                                        <div class="text-subtitle-2 mb-1">Total Ventes</div>
                                        <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics.total_amount) }}</div>
                                        <div class="text-caption mt-1">
                                            {{ formatNumber(statistics.total_ventes) }} ventes
                                        </div>
                                    </div>
                                    <v-icon size="48" color="primary">mdi-cart</v-icon>
                                </div>
                            </v-card-item>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="3">
                        <v-card elevation="2" class="rounded-lg">
                            <v-card-item>
                                <div class="d-flex justify-space-between align-center">
                                    <div>
                                        <div class="text-subtitle-2 mb-1">Ventes Payées</div>
                                        <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics.paid_amount) }}</div>
                                        <div class="text-caption mt-1">
                                            {{ formatNumber(statistics.paid_count) }} ventes payées
                                        </div>
                                    </div>
                                    <v-icon size="48" color="success">mdi-cash-check</v-icon>
                                </div>
                            </v-card-item>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="3">
                        <v-card elevation="2" class="rounded-lg">
                            <v-card-item>
                                <div class="d-flex justify-space-between align-center">
                                    <div>
                                        <div class="text-subtitle-2 mb-1">Ventes Impayées</div>
                                        <div class="text-h5 font-weight-bold">{{ formatCurrency(statistics.unpaid_amount) }}</div>
                                        <div class="text-caption mt-1">
                                            {{ formatNumber(statistics.unpaid_count) }} ventes impayées
                                        </div>
                                    </div>
                                    <v-icon size="48" color="error">mdi-cash-remove</v-icon>
                                </div>
                            </v-card-item>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="3">
                        <v-card elevation="2" class="rounded-lg">
                            <v-card-item>
                                <div class="d-flex justify-space-between align-center">
                                    <div>
                                        <div class="text-subtitle-2 mb-1">Taux de Paiement</div>
                                        <div class="text-h5 font-weight-bold">
                                            {{ formatNumber((statistics.paid_count / statistics.total_ventes) * 100) }}%
                                        </div>
                                        <div class="text-caption mt-1">
                                            des ventes sont payées
                                        </div>
                                    </div>
                                    <v-icon size="48" color="info">mdi-chart-pie</v-icon>
                                </div>
                            </v-card-item>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Product Statistics -->
                <v-card class="mb-6">
                    <v-card-title class="d-flex align-center">
                        <v-icon start color="primary">mdi-chart-box</v-icon>
                        Statistiques par Produit
                    </v-card-title>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Quantité Totale</th>
                                <th>Montant Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="stat in productStats" :key="stat.product.id">
                                <td>{{ stat.product.name }}</td>
                                <td>{{ formatNumber(stat.totalQuantity) }}</td>
                                <td>{{ formatCurrency(stat.totalAmount) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>

                <!-- Main Table -->
                <v-card>
                    <v-table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Produit</th>
                                <th>Client</th>
                                <th>Quantité</th>
                                <th>Prix Total</th>
                                <th>Statut</th>
                                <th>Date Échéance</th>
                                <th>Commercial</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="vente in ventes.data" :key="vente.id">
                                <td>{{ formatDate(vente.created_at) }}</td>
                                <td>{{ vente.product?.name }}</td>
                                <td>{{ vente.customer?.name }}</td>
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
                                <td>{{ vente.commercial?.name }}</td>
                                <td>
                                    <v-btn 
                                        icon="mdi-delete" 
                                        variant="text" 
                                        color="error"
                                        @click="confirmDelete(vente)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                    <!-- Add pagination -->
                    <div class="d-flex justify-center mt-4" v-if="ventes.links && ventes.links.length > 3">
                        <v-pagination
                            v-model="currentPage"
                            :length="Math.ceil(ventes.total / ventes.per_page)"
                            :total-visible="7"
                            @update:model-value="changePage"
                        ></v-pagination>
                    </div>
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
                        <v-radio-group
                            v-model="form.payment_status"
                            label="Statut de paiement"
                            :error-messages="form.errors.paid"
                            class="mt-4"
                        >
                            <v-radio
                                label="Payé"
                                value="paid"
                                color="success"
                            />
                            <v-radio
                                label="Non payé"
                                value="unpaid"
                                color="error"
                            />
                        </v-radio-group>
                        <v-text-field
                            v-if="form.payment_status === 'unpaid'"
                            v-model="form.should_be_paid_at"
                            label="Date d'échéance"
                            type="date"
                            :error-messages="form.errors.should_be_paid_at"
                            class="mt-4"
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

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Confirmer la suppression</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cette vente ? Cette action est irréversible.
                    <div v-if="venteToDelete" class="mt-4">
                        <strong>Détails de la vente :</strong>
                        <div>Produit : {{ venteToDelete.product?.name }}</div>
                        <div>Client : {{ venteToDelete.customer?.name }}</div>
                        <div>Montant : {{ formatPrice(venteToDelete.price * venteToDelete.quantity) }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="deleteDialog = false">
                        Annuler
                    </v-btn>
                    <v-btn color="error" variant="text" @click="deleteVente">
                        Confirmer la suppression
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 