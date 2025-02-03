<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    products: Array,
    total_stock_value: Number,
    base_products: Array
});

const form = useForm({
    name: '',
    price: '',
    cost_price: '',
    parent_id: null,
    base_quantity: 0
});

const dialog = ref(false);
const editedItem = ref(null);
const deleteDialog = ref(false);
const itemToDelete = ref(null);
const deleteForm = ref(null);
const stockEntriesDialog = ref(false);
const selectedProduct = ref(null);
const stockEntryForm = useForm({
    stock_entries: []
});

const margin = computed(() => {
    const price = Number(form.price) || 0;
    const costPrice = Number(form.cost_price) || 0;
    if (!price || !costPrice) return 0;
    return ((price - costPrice) / costPrice * 100).toFixed(2);
});

const openDialog = (item = null) => {
    editedItem.value = item;
    if (item) {
        form.name = item.name;
        form.price = item.price;
        form.cost_price = item.cost_price;
        form.parent_id = item.parent_id;
        form.base_quantity = item.base_quantity;
    } else {
        form.reset();
    }
    dialog.value = true;
};

const openDeleteDialog = (item) => {
    itemToDelete.value = item;
    deleteDialog.value = true;
};

const openStockEntriesDialog = (product) => {
    selectedProduct.value = product;
    stockEntryForm.stock_entries = product.stock_entries.map(entry => ({
        id: entry.id,
        quantity_left: entry.quantity_left,
        quantity: entry.quantity,
        unit_price: entry.unit_price,
        created_at: entry.created_at
    })).sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    stockEntriesDialog.value = true;
};

const submit = () => {
    if (editedItem.value) {
        form.put(route('produits.update', editedItem.value.id), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                editedItem.value = null;
            },
        });
    } else {
        form.post(route('produits.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            },
        });
    }
};

const deleteProduct = () => {
    if (itemToDelete.value) {
        deleteForm.value = useForm({});
        deleteForm.value.delete(route('produits.destroy', itemToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                deleteDialog.value = false;
                itemToDelete.value = null;
                deleteForm.value = null;
            },
            onError: (errors) => {
                console.error('Delete failed:', errors);
            }
        });
    }
};

const updateStockEntries = () => {
    stockEntryForm.put(route('products.update-stock-entries', selectedProduct.value.id), {
        onSuccess: () => {
            stockEntriesDialog.value = false;
            selectedProduct.value = null;
        },
    });
};

const formatPrice = (price) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF'
    }).format(price);
};

const calculateMargin = (price, costPrice) => {
    price = Number(price) || 0;
    costPrice = Number(costPrice) || 0;
    if (!price || !costPrice) return '0%';
    return ((price - costPrice) / costPrice * 100).toFixed(2) + '%';
};
</script>

<template>
    <Head title="Produits" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Produits</h2>
                    <div class="text-subtitle-1 mt-2">
                        Valeur totale du stock: {{ formatPrice(props.total_stock_value) }}
                    </div>
                </div>
                <v-btn color="primary" @click="openDialog()">
                    Ajouter un produit
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
                                <th>Type</th>
                                
                                <th>Qt base</th>
                                <th>Prix de Vente</th>
                                <th>Prix de Revient</th>
                                <th>Marge</th>
                                <th>Stock</th>
                                <th>Valeur du stock</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="product in products" :key="product.id">
                                <td>{{ product.name }}</td>
                                <td>
                                    <v-icon
  :color="product.is_base_product ? 'primary' : 'info'"
  :title="product.is_base_product ? 'Produit de base' : 'Variante'"
>
  {{ product.is_base_product ? 'mdi-package-variant' : 'mdi-package-variant-closed' }}
</v-icon>
                                </td>
                                <td>{{ product.base_quantity }}</td>
                                <td>{{ formatPrice(product.price) }}</td>
                                <td>{{ formatPrice(product.cost_price) }}</td>
                                <td>{{ calculateMargin(product.price, product.cost_price) }}</td>
                                <td>{{ product.stock_available }}</td>
                                <td>{{ formatPrice(product.stock_value) }}</td>
                                <td>
                                    <v-btn 
                                        icon="mdi-package-variant-plus" 
                                        variant="text" 
                                        color="info"
                                        @click="openStockEntriesDialog(product)"
                                        :title="'Gérer le stock'"
                                    />
                                    <v-btn 
                                        icon="mdi-pencil" 
                                        variant="text" 
                                        color="primary"
                                        @click="openDialog(product)"
                                    />
                                    <v-btn 
                                        icon="mdi-delete" 
                                        variant="text" 
                                        color="error"
                                        @click="openDeleteDialog(product)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </div>
        </div>

        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title>{{ editedItem ? 'Modifier le Produit' : 'Nouveau Produit' }}</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.name"
                            label="Nom"
                            :error-messages="form.errors.name"
                        />
                        <v-select
                            v-model="form.parent_id"
                            :items="base_products"
                            item-title="name"
                            item-value="id"
                            label="Produit de base"
                            clearable
                            :error-messages="form.errors.parent_id"
                            :hint="form.parent_id ? 'Ce produit sera une variante' : 'Ce produit sera un produit de base'"
                            persistent-hint
                        />
                        <v-text-field
                            v-model.number="form.base_quantity"
                            label="Quantité de base"
                            type="number"
                            :error-messages="form.errors.base_quantity"
                        />
                        <v-text-field
                            v-model="form.cost_price"
                            label="Prix de Revient"
                            type="number"
                            :error-messages="form.errors.cost_price"
                        />
                        <v-text-field
                            v-model="form.price"
                            label="Prix de Vente"
                            type="number"
                            :error-messages="form.errors.price"
                        />
                        <div v-if="form.price && form.cost_price" class="text-subtitle-1 mb-4">
                            Marge: {{ margin }}%
                        </div>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" @click="dialog = false">Annuler</v-btn>
                            <v-btn 
                                color="primary" 
                                type="submit" 
                                :loading="form.processing"
                            >
                                {{ editedItem ? 'Mettre à jour' : 'Sauvegarder' }}
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <v-dialog v-model="deleteDialog" max-width="500px">
            <v-card>
                <v-card-title class="text-h5">Supprimer le produit</v-card-title>
                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer ce produit ?
                    <br>
                    Cette action est irréversible.
                    <div v-if="itemToDelete" class="mt-4">
                        <strong>Détails du produit :</strong>
                        <div>Nom : {{ itemToDelete.name }}</div>
                        <div>Prix de Vente : {{ formatPrice(itemToDelete.price) }}</div>
                        <div>Prix de Revient : {{ formatPrice(itemToDelete.cost_price) }}</div>
                        <div>Marge : {{ calculateMargin(itemToDelete.price, itemToDelete.cost_price) }}</div>
                        <div v-if="itemToDelete.ventes_count > 0" class="mt-2 text-error">
                            Attention : Ce produit a {{ itemToDelete.ventes_count }} vente(s) associée(s).
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn 
                        color="primary" 
                        variant="text" 
                        @click="deleteDialog = false"
                        :disabled="deleteForm?.processing"
                    >
                        Annuler
                    </v-btn>
                    <v-btn 
                        color="error" 
                        variant="text" 
                        @click="deleteProduct"
                        :loading="deleteForm?.processing"
                        :disabled="deleteForm?.processing || (itemToDelete?.ventes_count > 0)"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 