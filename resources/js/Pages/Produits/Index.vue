<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
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

const transformDialog = ref(false);
const transformForm = useForm({
    variant_id: null,
    quantity: null,
    quantity_transformed: 0,
    unused_quantity: 0,
});
const selectedParentProduct = ref(null);

const selectedVariant = computed(() => {
    if (!transformForm.variant_id) return null;
    return props.products.find(p => p.id === transformForm.variant_id);
});

const quantityTransformed = computed(() => {
    if (!selectedVariant.value  || !selectedParentProduct.value) {
        throw new Error(`Données invalides pour le calcul de la quantité transformée Variante: ${selectedVariant.value?.name} Quantité: ${transformForm.quantity} Produit de base: ${selectedParentProduct.value?.name}`);
    }
    if (!transformForm.quantity) {
        return 0;
    }
    // Calculate how many pieces will be used from parent product
    const totalPiecesNeeded = (transformForm.quantity * selectedParentProduct.value.base_quantity)/selectedVariant.value.base_quantity;
    return totalPiecesNeeded;
});

// Add watchers for variant_id and quantity
watch([() => transformForm.variant_id, () => transformForm.quantity], () => {
    try {
        transformForm.quantity_transformed = quantityTransformed.value;
    } catch (error) {
        // Reset transformed quantity if calculation is not possible
        transformForm.quantity_transformed = 0;
    }
}, { immediate: true });

const totalPiecesNeeded = computed(() => {
    if (!selectedVariant.value || !transformForm.quantity) return 0;
    return (transformForm.quantity * selectedParentProduct.value.base_quantity) + Number(transformForm.unused_quantity || 0);
});

const hasEnoughStock = computed(() => {
    if (!selectedParentProduct.value) return false;
    return transformForm.quantity <= selectedParentProduct.value.stock_available;
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

const openTransformDialog = (product) => {
    selectedParentProduct.value = product;
    transformDialog.value = true;
    transformForm.reset();
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

const submitTransform = () => {
    if (!selectedParentProduct.value || !selectedVariant.value) return;

    // Check if quantity is higher than available stock
    if (transformForm.quantity > selectedParentProduct.value.stock_available) {
        transformForm.setError('quantity', `Stock insuffisant. Stock disponible: ${selectedParentProduct.value.stock_available} pièces`);
        return;
    }

    transformForm.quantity_transformed = quantityTransformed.value;
    transformForm.clearErrors();
    transformForm.post(route('products.transform', selectedParentProduct.value.id), {
        onSuccess: () => {
            transformDialog.value = false;
            selectedParentProduct.value = null;
            transformForm.reset();
        },
        preserveScroll: true
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
                                <th>Total vendu</th>
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
                                <td>{{ product.total_sold }}</td>
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
                                        v-if="!product.parent_id"
                                        icon="mdi-forward"
                                        variant="text"
                                        color="secondary"
                                        @click="openTransformDialog(product)"
                                        :title="'Transformer en variants'"
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

        <!-- Stock Entries Dialog -->
        <v-dialog v-model="stockEntriesDialog" max-width="800px">
            <v-card>
                <v-card-title class="text-h5">
                    Gestion du stock - {{ selectedProduct?.name }}
                </v-card-title>
                <v-card-text>
                    <v-table v-if="selectedProduct">
                        <thead>
                            <tr>
                                <th>Date d'entrée</th>
                                <th>Quantité initiale</th>
                                <th>Quantité restante</th>
                                <th>Prix unitaire</th>
                                <th>Valeur du stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in stockEntryForm.stock_entries" :key="entry.id">
                                <td>{{ new Date(entry.created_at).toLocaleDateString('fr-FR') }}</td>
                                <td>{{ entry.quantity }}</td>
                                <td>
                                    <v-text-field
                                        v-model.number="entry.quantity_left"
                                        type="number"
                                        density="compact"
                                        variant="outlined"
                                        hide-details
                                        :max="entry.quantity"
                                        :min="0"
                                    />
                                </td>
                                <td>{{ formatPrice(entry.unit_price) }}</td>
                                <td>{{ formatPrice(entry.quantity_left * entry.unit_price) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="text"
                        @click="stockEntriesDialog = false"
                        :disabled="stockEntryForm.processing"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="updateStockEntries"
                        :loading="stockEntryForm.processing"
                        :disabled="stockEntryForm.processing"
                    >
                        Mettre à jour
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="transformDialog" max-width="600px">
            <v-card>
                <v-card-title>
                    <span class="text-h5">Transformer en variants</span>
                </v-card-title>

                <v-card-text>
                    <v-alert
                        v-if="transformForm.errors.error"
                        type="error"
                        class="mb-4"
                        closable
                    >
                        {{ transformForm.errors.error }}
                    </v-alert>

                    <v-form @submit.prevent="submitTransform">
                        <v-select
                            v-model="transformForm.variant_id"
                            :items="products.filter(p => p.parent_id === selectedParentProduct?.id)"
                            item-title="name"
                            item-value="id"
                            label="Sous produit"
                            :error-messages="transformForm.errors.variant_id"
                            variant="outlined"
                            class="mb-4"
                        />

                        <v-text-field
                            v-model.number="transformForm.quantity"
                            type="number"
                            label="Quantité"
                            :error-messages="transformForm.errors.quantity"
                            variant="outlined"
                            class="mb-4"
                            :hint="selectedVariant ? `Stock disponible: ${selectedParentProduct.stock_available} pièces` : ''"
                            persistent-hint
                        />

                        <v-text-field
                            v-model="transformForm.quantity_transformed"
                            type="number"
                            label="Quantité de pièces transformées"
                            variant="outlined"
                            class="mb-4"
                            disabled
                            :error-messages="transformForm.errors.quantity_transformed"
                            :hint="selectedVariant ? `${transformForm.quantity || 0} cartons ${selectedParentProduct.name} × ${selectedParentProduct.base_quantity}  = ${quantityTransformed} paquets de ${selectedVariant.name}` : ''"
                            persistent-hint
                        />

                        <v-text-field
                            v-model.number="transformForm.unused_quantity"
                            type="number"
                            label="Quantité inutilisée"
                            :error-messages="transformForm.errors.unused_quantity"
                            variant="outlined"
                            class="mb-4"
                            :hint="selectedParentProduct ? `Maximum: ${selectedParentProduct.base_quantity - 1} pièces` : ''"
                            persistent-hint
                        />

                        <div v-if="!hasEnoughStock" class="text-error mb-4">
                            Stock insuffisant. Total disponible: {{ selectedParentProduct.stock_available }} pièces
                        </div>
                    </v-form>
                </v-card-text>

                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn
                        color="grey"
                        variant="text"
                        @click="transformDialog = false"
                    >
                        Annuler
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="submitTransform"
                        :loading="transformForm.processing"
                    >
                        Transformer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template> 