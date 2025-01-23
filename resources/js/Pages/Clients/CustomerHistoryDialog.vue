<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue: Boolean,
    customer: Object,
    orders: Array,
    ventes: Array,
});

const emit = defineEmits(['update:modelValue']);

const dialog = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const currentTab = ref('ventes'); // 'ventes' or 'orders'
const paymentFilter = ref('all'); // 'all', 'paid', 'unpaid'
const orderStatusFilter = ref('all'); // 'all', 'DELIVERED', 'WAITING', 'CANCELLED'

// Filter ventes based on payment status
const filteredVentes = computed(() => {
    if (paymentFilter.value === 'all') return props.ventes;
    return props.ventes.filter(vente => 
        paymentFilter.value === 'paid' ? vente.is_paid : !vente.is_paid
    );
});

// Filter orders based on status
const filteredOrders = computed(() => {
    if (orderStatusFilter.value === 'all') return props.orders;
    return props.orders.filter(order => order.status === orderStatusFilter.value);
});

// Calculate ventes statistics
const ventesStats = computed(() => {
    const stats = {};
    
    props.ventes.forEach(vente => {
        const productId = vente.product.id;
        if (!stats[productId]) {
            stats[productId] = {
                name: vente.product.name,
                total_quantity: 0,
                total_quantity_paid: 0,
                total_quantity_unpaid: 0,
                total_amount: 0,
                total_amount_paid: 0,
                total_amount_unpaid: 0,
            };
        }
        
        stats[productId].total_quantity += vente.quantity;
        stats[productId].total_amount += vente.total_price;
        
        if (vente.is_paid) {
            stats[productId].total_quantity_paid += vente.quantity;
            stats[productId].total_amount_paid += vente.total_price;
        } else {
            stats[productId].total_quantity_unpaid += vente.quantity;
            stats[productId].total_amount_unpaid += vente.total_price;
        }
    });
    
    return Object.values(stats);
});

// Calculate ventes totals
const ventesTotals = computed(() => {
    return props.ventes.reduce((acc, vente) => {
        const venteTotal = vente.total_price || 0;
        acc.total += venteTotal;
        if (vente.is_paid) {
            acc.total_paid += venteTotal;
        } else {
            acc.total_unpaid += venteTotal;
        }
        return acc;
    }, { total: 0, total_paid: 0, total_unpaid: 0 });
});

// Calculate orders statistics
const orderStats = computed(() => {
    const stats = {
        DELIVERED: 0,
        WAITING: 0,
        CANCELLED: 0,
    };
    
    props.orders.forEach(order => {
        stats[order.status]++;
    });
    
    return stats;
});
</script>

<template>
    <v-dialog
        v-model="dialog"
        
    >
        <v-card>
            <v-toolbar dark color="primary">
                <v-btn
                    icon
                    dark
                    @click="dialog = false"
                >
                    <v-icon>mdi-close</v-icon>
                </v-btn>
                <v-toolbar-title>Historique - {{ customer?.name }}</v-toolbar-title>
                <v-spacer></v-spacer>
            </v-toolbar>

            <v-card-text>
                <div class="py-4">
                    <!-- Tabs -->
                    <v-card class="mb-6">
                        <v-tabs v-model="currentTab">
                            <v-tab value="ventes">Ventes</v-tab>
                            <v-tab value="orders">Commandes</v-tab>
                        </v-tabs>
                    </v-card>

                    <!-- Ventes Tab Content -->
                    <div v-if="currentTab === 'ventes'">
                        <!-- Payment Status Filter -->
                        <div class="mb-6">
                            <v-btn-group>
                                <v-btn 
                                    :color="paymentFilter === 'all' ? 'primary' : undefined"
                                    @click="paymentFilter = 'all'"
                                >
                                    Toutes les ventes
                                </v-btn>
                                <v-btn 
                                    :color="paymentFilter === 'paid' ? 'primary' : undefined"
                                    @click="paymentFilter = 'paid'"
                                >
                                    Payées
                                </v-btn>
                                <v-btn 
                                    :color="paymentFilter === 'unpaid' ? 'primary' : undefined"
                                    @click="paymentFilter = 'unpaid'"
                                >
                                    Non payées
                                </v-btn>
                            </v-btn-group>
                        </div>

                        <!-- Rest of the ventes content... -->
                        <!-- [Previous ventes content remains the same] -->
                        
                        <!-- Ventes Total Statistics -->
                        <v-card class="mb-6">
                            <v-card-text>
                                <div class="grid grid-cols-3 gap-4">
                                    <div class="text-center">
                                        <div class="text-h6">Total des ventes</div>
                                        <div class="text-h4">{{ ventesTotals.total }} FCFA</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-h6 text-success">Total payé</div>
                                        <div class="text-h4 text-success">{{ ventesTotals.total_paid }} FCFA</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-h6 text-error">Total non payé</div>
                                        <div class="text-h4 text-error">{{ ventesTotals.total_unpaid }} FCFA</div>
                                    </div>
                                </div>
                            </v-card-text>
                        </v-card>

                        <!-- Ventes Product Statistics -->
                        <v-card class="mb-6">
                            <v-card-title>Statistiques par produit</v-card-title>
                            <v-card-text>
                                <v-table>
                                    <thead>
                                        <tr>
                                            <th>Produit</th>
                                            <th class="text-right">Quantité totale</th>
                                            <th class="text-right">Quantité payée</th>
                                            <th class="text-right">Quantité non payée</th>
                                            <th class="text-right">Montant total</th>
                                            <th class="text-right">Montant payé</th>
                                            <th class="text-right">Montant non payé</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="stat in ventesStats" :key="stat.name">
                                            <td>{{ stat.name }}</td>
                                            <td class="text-right">{{ stat.total_quantity }}</td>
                                            <td class="text-right text-success">{{ stat.total_quantity_paid }}</td>
                                            <td class="text-right text-error">{{ stat.total_quantity_unpaid }}</td>
                                            <td class="text-right">{{ stat.total_amount }} FCFA</td>
                                            <td class="text-right text-success">{{ stat.total_amount_paid }} FCFA</td>
                                            <td class="text-right text-error">{{ stat.total_amount_unpaid }} FCFA</td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card-text>
                        </v-card>

                        <!-- Ventes List -->
                        <v-card>
                            <v-card-title>Liste des ventes</v-card-title>
                            <v-card-text>
                                <v-table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Produit</th>
                                            <th class="text-right">Quantité</th>
                                            <th class="text-right">Prix unitaire</th>
                                            <th class="text-right">Total</th>
                                            <th>Paiement</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="vente in filteredVentes" :key="vente.id">
                                            <td>{{ new Date(vente.created_at).toLocaleDateString() }}</td>
                                            <td>{{ vente.product.name }}</td>
                                            <td class="text-right">{{ vente.quantity }}</td>
                                            <td class="text-right">{{ vente.price }} FCFA</td>
                                            <td class="text-right">{{ vente.total_price }} FCFA</td>
                                            <td>
                                                <v-chip
                                                    :color="vente.is_paid ? 'success' : 'error'"
                                                    small
                                                >
                                                    {{ vente.is_paid ? 'Payée' : 'Non payée' }}
                                                </v-chip>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card-text>
                        </v-card>
                    </div>

                    <!-- Orders Tab Content -->
                    <div v-if="currentTab === 'orders'">
                        <!-- Order Status Filter -->
                        <div class="mb-6">
                            <v-btn-group>
                                <v-btn 
                                    :color="orderStatusFilter === 'all' ? 'primary' : undefined"
                                    @click="orderStatusFilter = 'all'"
                                >
                                    Toutes les commandes
                                </v-btn>
                                <v-btn 
                                    :color="orderStatusFilter === 'DELIVERED' ? 'success' : undefined"
                                    @click="orderStatusFilter = 'DELIVERED'"
                                >
                                    Livrées
                                </v-btn>
                                <v-btn 
                                    :color="orderStatusFilter === 'WAITING' ? 'warning' : undefined"
                                    @click="orderStatusFilter = 'WAITING'"
                                >
                                    En attente
                                </v-btn>
                                <v-btn 
                                    :color="orderStatusFilter === 'CANCELLED' ? 'error' : undefined"
                                    @click="orderStatusFilter = 'CANCELLED'"
                                >
                                    Annulées
                                </v-btn>
                            </v-btn-group>
                        </div>

                        <!-- Orders Statistics -->
                        <v-card class="mb-6">
                            <v-card-text>
                                <div class="grid grid-cols-3 gap-4">
                                    <div class="text-center">
                                        <div class="text-h6 text-success">Livrées</div>
                                        <div class="text-h4 text-success">{{ orderStats.DELIVERED }}</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-h6 text-warning">En attente</div>
                                        <div class="text-h4 text-warning">{{ orderStats.WAITING }}</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-h6 text-error">Annulées</div>
                                        <div class="text-h4 text-error">{{ orderStats.CANCELLED }}</div>
                                    </div>
                                </div>
                            </v-card-text>
                        </v-card>

                        <!-- Orders List -->
                        <v-card>
                            <v-card-title>Liste des commandes</v-card-title>
                            <v-card-text>
                                <div class="space-y-4">
                                    <v-expansion-panels>
                                        <v-expansion-panel
                                            v-for="order in filteredOrders"
                                            :key="order.id"
                                        >
                                            <template #title>
                                                <div class="flex items-center justify-between w-full">
                                                    <div>
                                                        {{ new Date(order.created_at).toLocaleDateString() }}
                                                        <v-chip
                                                            :color="order.status === 'DELIVERED' ? 'success' : 
                                                                   order.status === 'WAITING' ? 'warning' : 'error'"
                                                            class="ml-2"
                                                            small
                                                        >
                                                            {{ order.status === 'DELIVERED' ? 'Livrée' :
                                                               order.status === 'WAITING' ? 'En attente' : 'Annulée' }}
                                                        </v-chip>
                                                        <v-chip
                                                            :color="order.is_paid ? 'success' : 'error'"
                                                            class="ml-2"
                                                            small
                                                        >
                                                            {{ order.is_paid ? 'Payée' : 'Non payée' }}
                                                        </v-chip>
                                                    </div>
                                                    <div class="text-right">
                                                        Total: {{ order.total_price }} FCFA
                                                    </div>
                                                </div>
                                            </template>
                                            <v-expansion-panel-text>
                                                <v-table>
                                                    <thead>
                                                        <tr>
                                                            <th>Produit</th>
                                                            <th class="text-right">Quantité</th>
                                                            <th class="text-right">Prix unitaire</th>
                                                            <th class="text-right">Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="item in order.items" :key="item.id">
                                                            <td>{{ item.product.name }}</td>
                                                            <td class="text-right">{{ item.quantity }}</td>
                                                            <td class="text-right">{{ item.price }} FCFA</td>
                                                            <td class="text-right">{{ item.total_price }} FCFA</td>
                                                        </tr>
                                                    </tbody>
                                                </v-table>
                                            </v-expansion-panel-text>
                                        </v-expansion-panel>
                                    </v-expansion-panels>
                                </div>
                            </v-card-text>
                        </v-card>
                    </div>
                </div>
            </v-card-text>
        </v-card>
    </v-dialog>
</template> 