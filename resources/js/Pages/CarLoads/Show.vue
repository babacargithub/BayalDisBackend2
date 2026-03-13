<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Swal from 'sweetalert2';
import moment from 'moment';

const props = defineProps({
    carLoad: { type: Object, required: true },
    products: { type: Array, required: true },
    missingInventoryProducts: { type: Array, default: () => [] },
});

// ─── Computed helpers ────────────────────────────────────────────────────────
const isActive = computed(() => props.carLoad.status === 'ACTIVE');
const isLoading = computed(() => props.carLoad.status === 'LOADING');
const isUnloaded = computed(() => props.carLoad.status === 'UNLOADED');
const statusColor = computed(() => {
    if (isActive.value) return 'success';
    if (isLoading.value) return 'warning';
    return 'default';
});
const statusLabel = computed(() => {
    if (isActive.value) return 'Actif';
    if (isLoading.value) return 'En chargement';
    return 'Terminé';
});
const formatDate = (dateStr) =>
    dateStr ? new Date(dateStr).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '-';

const inventoryResultValue = (item) => item.total_sold + item.total_returned - item.total_loaded;
const inventoryResultColor = (result) => {
    if (result > 0) return 'error';
    if (result < 0) return 'warning';
    return 'success';
};

// ─── Tabs ────────────────────────────────────────────────────────────────────
const activeTab = ref('articles');
const currentDate = moment().format('YYYY-MM-DD');

// ─── Snackbars ───────────────────────────────────────────────────────────────
const showSuccessSnackbar = ref(false);
const showErrorSnackbar = ref(false);
const successMessage = ref('');
const errorMessage = ref('');

const notifySuccess = (msg) => { successMessage.value = msg; showSuccessSnackbar.value = true; };
const notifyError = (errors) => {
    errorMessage.value = typeof errors === 'string' ? errors : Object.values(errors).flat().join(', ');
    showErrorSnackbar.value = true;
};

// ─── Car load lifecycle actions ───────────────────────────────────────────────
const actionForm = useForm({});

const activateCarLoad = async () => {
    const result = await Swal.fire({
        title: 'Activer le chargement?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Oui, activer',
        cancelButtonText: 'Annuler',
    });
    if (result.isConfirmed) {
        actionForm.post(route('car-loads.activate', props.carLoad.id), {
            onSuccess: () => notifySuccess('Chargement activé avec succès'),
            onError: notifyError,
        });
    }
};

const unloadCarLoad = async () => {
    const result = await Swal.fire({
        title: 'Décharger le véhicule?',
        text: 'Cette action est irréversible.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Oui, décharger',
        cancelButtonText: 'Annuler',
    });
    if (result.isConfirmed) {
        actionForm.post(route('car-loads.unload', props.carLoad.id), {
            onSuccess: () => notifySuccess('Chargement déchargé avec succès'),
            onError: notifyError,
        });
    }
};

const createFromPreviousCarLoad = async () => {
    const result = await Swal.fire({
        title: 'Créer un nouveau chargement?',
        text: 'Un nouveau chargement sera créé à partir de celui-ci.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Oui, créer',
        cancelButtonText: 'Annuler',
    });
    if (result.isConfirmed) {
        actionForm.post(route('car-loads.create-from-previous', props.carLoad.id));
    }
};

// ─── Articles tab — Product filter & autocomplete ────────────────────────────
const showParentProductsOnly = ref(false);
const filteredProducts = computed(() =>
    showParentProductsOnly.value
        ? props.products.filter((product) => product.parent_id === null)
        : props.products
);

// ─── Articles tab — Grouped display ──────────────────────────────────────────
const groupedCarLoadItems = computed(() => {
    const groups = {};
    for (const item of props.carLoad.items ?? []) {
        const productId = item.product_id;
        if (!groups[productId]) {
            groups[productId] = {
                product_id: productId,
                product: item.product,
                total_quantity_loaded: 0,
                total_quantity_left: 0,
                items: [],
            };
        }
        groups[productId].total_quantity_loaded += item.quantity_loaded ?? 0;
        groups[productId].total_quantity_left += item.quantity_left ?? 0;
        groups[productId].items.push(item);
    }
    return Object.values(groups).sort((a, b) => a.product.name.localeCompare(b.product.name));
});

const expandedProductGroups = ref([]);

// ─── Articles tab — Add items ─────────────────────────────────────────────────
const showAddItemsForm = ref(false);

const itemForm = useForm({
    items: [{ product_id: null, quantity_loaded: null, loaded_at: currentDate, comment: '' }],
});

const addItemRow = () => {
    itemForm.items.push({ product_id: null, quantity_loaded: null, loaded_at: moment().format('YYYY-MM-DD'), comment: '' });
};

const removeItemRow = (index) => {
    if (itemForm.items.length > 1) itemForm.items.splice(index, 1);
};

const submitItems = () => {
    itemForm.post(route('car-loads.items.store', { carLoad: props.carLoad.id }), {
        preserveScroll: true,
        onSuccess: () => {
            showAddItemsForm.value = false;
            itemForm.reset();
            notifySuccess('Articles ajoutés avec succès');
        },
        onError: notifyError,
    });
};

// ─── Articles tab — Inline edit ───────────────────────────────────────────────
const editingItemId = ref(null);
const editingItemQuantityLoaded = ref(null);
const editingItemQuantityLeft = ref(null);
const editItemForm = useForm({ quantity_loaded: null, quantity_left: null });

const startEditingItem = (item) => {
    editingItemId.value = item.id;
    editingItemQuantityLoaded.value = item.quantity_loaded;
    editingItemQuantityLeft.value = item.quantity_left;
};

const cancelEditingItem = () => {
    editingItemId.value = null;
    editItemForm.reset();
};

const saveEditingItem = (item) => {
    editItemForm.quantity_loaded = editingItemQuantityLoaded.value;
    editItemForm.quantity_left = editingItemQuantityLeft.value;
    editItemForm.put(route('car-loads.items.update', { carLoad: props.carLoad.id, item: item.id }), {
        preserveScroll: true,
        onSuccess: () => { editingItemId.value = null; notifySuccess('Article mis à jour'); },
        onError: (errors) => { notifyError(errors); editingItemId.value = null; },
    });
};

// ─── Articles tab — Delete ────────────────────────────────────────────────────
const showDeleteItemDialog = ref(false);
const itemToDeleteId = ref(null);
const deleteItemForm = useForm({});

const requestDeleteItem = (id) => { itemToDeleteId.value = id; showDeleteItemDialog.value = true; };
const confirmDeleteItem = () => {
    deleteItemForm.delete(route('car-loads.items.destroy', { carLoad: props.carLoad.id, item: itemToDeleteId.value }), {
        preserveScroll: true,
        onSuccess: () => { showDeleteItemDialog.value = false; itemToDeleteId.value = null; notifySuccess('Article supprimé'); },
        onError: (errors) => { notifyError(errors); showDeleteItemDialog.value = false; },
    });
};

// ─── Articles tab — helpers ───────────────────────────────────────────────────
const goToProductHistory = (productId) => {
    window.open(route('car-loads.product.history', { carLoad: props.carLoad.id, product: productId }));
};
const exportCarLoadItemsPdf = () => {
    window.open(route('car-loads.items.export-pdf', { carLoad: props.carLoad.id }));
};

// ─── Inventaire tab — Create inventory ───────────────────────────────────────
const inventoryForm = useForm({ name: '' });

const createInventory = () => {
    inventoryForm.post(route('car-loads.inventories.store', { carLoad: props.carLoad.id }), {
        preserveScroll: true,
        onSuccess: () => { inventoryForm.reset(); notifySuccess('Inventaire créé avec succès'); },
        onError: notifyError,
    });
};

// ─── Inventaire tab — Inline edit ─────────────────────────────────────────────
const editingInventoryItemId = ref(null);
const editingInventoryReturnedQuantity = ref(null);
const editInventoryItemForm = useForm({ total_returned: null });

const startEditingInventoryItem = (item) => {
    editingInventoryItemId.value = item.id;
    editingInventoryReturnedQuantity.value = item.total_returned;
};

const cancelEditingInventoryItem = () => {
    editingInventoryItemId.value = null;
    editInventoryItemForm.reset();
};

const saveEditingInventoryItem = (item) => {
    editInventoryItemForm.total_returned = editingInventoryReturnedQuantity.value;
    editInventoryItemForm.put(route('car-loads.inventories.items.update', {
        carLoad: props.carLoad.id,
        inventory: props.carLoad.inventory.id,
        item: item.id,
    }), {
        preserveScroll: true,
        onSuccess: () => { editingInventoryItemId.value = null; notifySuccess('Quantité retournée mise à jour'); },
        onError: (errors) => { notifyError(errors); editingInventoryItemId.value = null; },
    });
};

// ─── Inventaire tab — Delete ──────────────────────────────────────────────────
const showDeleteInventoryItemDialog = ref(false);
const inventoryItemToDelete = ref(null);
const deleteInventoryItemForm = useForm({});

const requestDeleteInventoryItem = (item) => { inventoryItemToDelete.value = item; showDeleteInventoryItemDialog.value = true; };
const confirmDeleteInventoryItem = () => {
    deleteInventoryItemForm.delete(route('car-loads.inventories.items.destroy', {
        carLoad: props.carLoad.id,
        inventory: props.carLoad.inventory.id,
        item: inventoryItemToDelete.value.id,
    }), {
        preserveScroll: true,
        onSuccess: () => { showDeleteInventoryItemDialog.value = false; inventoryItemToDelete.value = null; notifySuccess('Article supprimé de l\'inventaire'); },
        onError: (errors) => { notifyError(errors); showDeleteInventoryItemDialog.value = false; },
    });
};

// ─── Inventaire tab — Close & new car load ────────────────────────────────────
const closeInventoryForm = useForm({});
const closeInventory = async () => {
    const result = await Swal.fire({
        title: 'Clôturer l\'inventaire?',
        text: 'Cette action est irréversible.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Oui, clôturer',
        cancelButtonText: 'Annuler',
    });
    if (result.isConfirmed) {
        closeInventoryForm.put(route('car-loads.inventories.close', {
            carLoad: props.carLoad.id,
            inventory: props.carLoad.inventory.id,
        }), {
            preserveScroll: true,
            onSuccess: () => notifySuccess('Inventaire clôturé avec succès'),
            onError: notifyError,
        });
    }
};

const createNewCarLoadFromInventoryForm = useForm({});
const createNewCarLoadFromInventory = () => {
    createNewCarLoadFromInventoryForm.post(route('car-loads.create-from-inventory', { inventory: props.carLoad.inventory.id }), {
        onSuccess: () => notifySuccess('Nouveau chargement créé avec succès'),
        onError: (errors) => notifyError(errors.error ?? errors.message ?? 'Une erreur est survenue'),
    });
};

// ─── Inventaire tab — Bulk entry form ────────────────────────────────────────
// One row per product not yet inventoried. User types total_returned for each,
// then submits everything in a single request.
const buildInventoryEntryRows = (missingProducts) =>
    missingProducts.map((product) => ({
        product_id: product.id,
        product_name: product.name,
        quantity_loaded: product.quantity_loaded,
        total_returned: null,
        comment: '',
    }));

const inventoryEntryRows = ref(buildInventoryEntryRows(props.missingInventoryProducts));

// Keep in sync after Inertia page refreshes (e.g. after a partial submit).
watch(
    () => props.missingInventoryProducts,
    (updatedMissingProducts) => {
        inventoryEntryRows.value = buildInventoryEntryRows(updatedMissingProducts);
    },
);

const inventoryEntryForm = useForm({ items: [] });

const submitInventoryEntries = () => {
    inventoryEntryForm.items = inventoryEntryRows.value.map((row) => ({
        product_id: row.product_id,
        total_returned: row.total_returned ?? 0,
        comment: row.comment,
    }));
    inventoryEntryForm.post(route('car-loads.inventories.items.store', {
        carLoad: props.carLoad.id,
        inventory: props.carLoad.inventory.id,
    }), {
        preserveScroll: true,
        onSuccess: () => notifySuccess('Inventaire saisi avec succès'),
        onError: notifyError,
    });
};

const exportInventoryPdf = () => {
    window.open(route('car-loads.inventories.export-pdf', {
        carLoad: props.carLoad.id,
        inventory: props.carLoad.inventory.id,
    }));
};

// ─── Expose computed properties for testing ──────────────────────────────────
defineExpose({ groupedCarLoadItems, filteredProducts, inventoryResultValue, statusLabel, statusColor, activeTab, showParentProductsOnly, inventoryEntryRows });

// ─── Table headers ─────────────────────────────────────────────────────────
const groupedItemTableHeaders = [
    { title: '', key: 'data-table-expand', width: '48px', sortable: false },
    { title: 'Produit', key: 'product.name' },
    { title: 'Total chargé', key: 'total_quantity_loaded', align: 'end' },
    { title: 'Total restant', key: 'total_quantity_left', align: 'end' },
    { title: 'Lots', key: 'lots_count', align: 'center', sortable: false },
];

const inventoryTableHeaders = [
    { title: 'Produit', key: 'product.name' },
    { title: 'Chargé', key: 'total_loaded', align: 'end' },
    { title: 'Vendu', key: 'total_sold', align: 'end' },
    { title: 'Retourné', key: 'total_returned', align: 'end' },
    { title: 'Résultat', key: 'result', align: 'center' },
    { title: 'Actions', key: 'actions', sortable: false, align: 'end' },
];
</script>

<template>
    <Head :title="`Chargement ${carLoad.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 sm:gap-4 min-w-0">
                <Link
                    :href="route('car-loads.index')"
                    class="text-gray-500 hover:text-gray-700 shrink-0"
                >
                    <v-icon>mdi-arrow-left</v-icon>
                </Link>
                <h2 class="font-semibold text-lg sm:text-xl text-gray-800 leading-tight truncate">
                    {{ carLoad.name }}
                </h2>
                <v-chip :color="statusColor" size="small" class="shrink-0">{{ statusLabel }}</v-chip>
            </div>
        </template>

        <div class="py-3 sm:py-6">
            <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8 space-y-3">

                <!-- Info card -->
                <v-card>
                    <v-card-text class="pa-3 sm:pa-4">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                            <div>
                                <p class="text-gray-400 text-xs">Équipe</p>
                                <p class="font-medium">{{ carLoad.team?.name ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-gray-400 text-xs">Chargé le</p>
                                <p class="font-medium">{{ formatDate(carLoad.load_date) }}</p>
                            </div>
                            <div>
                                <p class="text-gray-400 text-xs">Retour prévu</p>
                                <p class="font-medium">{{ formatDate(carLoad.return_date) }}</p>
                            </div>
                            <div v-if="carLoad.comment">
                                <p class="text-gray-400 text-xs">Commentaire</p>
                                <p class="font-medium">{{ carLoad.comment }}</p>
                            </div>
                        </div>
                    </v-card-text>
                    <v-card-actions class="px-3 pb-3 gap-2 flex-wrap">
                        <v-btn
                            v-if="isLoading"
                            color="primary"
                            variant="flat"
                            size="small"
                            prepend-icon="mdi-check-circle"
                            :loading="actionForm.processing"
                            @click="activateCarLoad"
                        >Activer</v-btn>
                        <v-btn
                            v-if="isActive"
                            color="warning"
                            variant="flat"
                            size="small"
                            prepend-icon="mdi-package-down"
                            :loading="actionForm.processing"
                            @click="unloadCarLoad"
                        >Décharger</v-btn>
                        <v-btn
                            v-if="isUnloaded"
                            color="info"
                            variant="flat"
                            size="small"
                            prepend-icon="mdi-content-copy"
                            :loading="actionForm.processing"
                            @click="createFromPreviousCarLoad"
                        >Nouveau à partir de celui-ci</v-btn>
                    </v-card-actions>
                </v-card>

                <!-- Tabs card -->
                <v-card>
                    <v-tabs v-model="activeTab" color="primary" density="compact">
                        <v-tab value="articles">
                            <v-icon start size="small">mdi-package-variant</v-icon>
                            Articles
                            <v-chip class="ml-1" size="x-small" color="primary" variant="tonal">
                                {{ carLoad.items?.length ?? 0 }}
                            </v-chip>
                        </v-tab>
                        <v-tab value="inventaire">
                            <v-icon start size="small">mdi-clipboard-list</v-icon>
                            Inventaire
                        </v-tab>
                    </v-tabs>

                    <v-window v-model="activeTab">

                        <!-- ═══════════ ARTICLES TAB ═══════════ -->
                        <v-window-item value="articles">
                            <v-card-text class="pa-2 sm:pa-4">

                                <!-- Toolbar -->
                                <div class="flex gap-2 flex-wrap mb-3">
                                    <v-btn
                                        v-if="!isUnloaded"
                                        color="primary"
                                        variant="flat"
                                        size="small"
                                        prepend-icon="mdi-plus"
                                        @click="showAddItemsForm = !showAddItemsForm"
                                    >
                                        <span class="hidden sm:inline">Ajouter des articles</span>
                                        <span class="sm:hidden">Ajouter</span>
                                    </v-btn>
                                    <v-btn
                                        variant="outlined"
                                        size="small"
                                        color="red"
                                        prepend-icon="mdi-file-pdf-box"
                                        @click="exportCarLoadItemsPdf"
                                    >PDF</v-btn>
                                </div>

                                <!-- Add items form (collapsible) -->
                                <v-expand-transition>
                                    <v-card v-if="showAddItemsForm" variant="outlined" class="mb-3">
                                        <v-card-text class="pa-2 sm:pa-3">
                                            <!-- Parent-only filter toggle -->
                                            <div class="flex items-center gap-2 mb-3">
                                                <v-btn
                                                    :color="showParentProductsOnly ? 'primary' : 'default'"
                                                    :variant="showParentProductsOnly ? 'flat' : 'outlined'"
                                                    size="x-small"
                                                    prepend-icon="mdi-filter-variant"
                                                    @click="showParentProductsOnly = !showParentProductsOnly"
                                                >
                                                    Produits parents uniquement
                                                </v-btn>
                                                <span class="text-xs text-gray-400">
                                                    {{ filteredProducts.length }} produit(s) disponible(s)
                                                </span>
                                            </div>
                                            <div
                                                v-for="(item, index) in itemForm.items"
                                                :key="index"
                                                class="flex flex-col sm:flex-row gap-2 mb-3 pb-3 border-b last:border-0 last:mb-0 last:pb-0"
                                            >
                                                <v-autocomplete
                                                    v-model="item.product_id"
                                                    :items="filteredProducts"
                                                    item-title="name"
                                                    item-value="id"
                                                    label="Produit"
                                                    density="compact"
                                                    hide-details
                                                    clearable
                                                    class="sm:flex-1"
                                                    :error-messages="itemForm.errors[`items.${index}.product_id`]"
                                                />
                                                <div class="flex gap-2">
                                                    <v-text-field
                                                        v-model="item.quantity_loaded"
                                                        type="number"
                                                        label="Qté"
                                                        density="compact"
                                                        hide-details
                                                        class="w-24"
                                                        :error-messages="itemForm.errors[`items.${index}.quantity_loaded`]"
                                                    />
                                                    <v-text-field
                                                        v-model="item.loaded_at"
                                                        type="date"
                                                        label="Date"
                                                        density="compact"
                                                        hide-details
                                                        class="w-36"
                                                    />
                                                    <v-btn
                                                        icon
                                                        density="compact"
                                                        variant="text"
                                                        color="error"
                                                        :disabled="itemForm.items.length === 1"
                                                        @click="removeItemRow(index)"
                                                    >
                                                        <v-icon>mdi-minus-circle</v-icon>
                                                    </v-btn>
                                                </div>
                                            </div>
                                            <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="addItemRow">
                                                Ligne
                                            </v-btn>
                                        </v-card-text>
                                        <v-card-actions class="pa-2">
                                            <v-spacer />
                                            <v-btn variant="text" size="small" @click="showAddItemsForm = false; itemForm.reset()">Annuler</v-btn>
                                            <v-btn color="primary" variant="flat" size="small" :loading="itemForm.processing" @click="submitItems">
                                                Enregistrer
                                            </v-btn>
                                        </v-card-actions>
                                    </v-card>
                                </v-expand-transition>

                                <!-- Items table — grouped by product with expand -->
                                <v-data-table
                                    :headers="groupedItemTableHeaders"
                                    :items="groupedCarLoadItems"
                                    v-model:expanded="expandedProductGroups"
                                    item-value="product_id"
                                    show-expand
                                    :items-per-page="100"
                                    density="compact"
                                    class="elevation-0"
                                >
                                    <!-- Summary row: total_quantity_loaded highlighted if has multiple lots -->
                                    <template #item.total_quantity_loaded="{ item }">
                                        <span :class="item.items.length > 1 ? 'font-semibold' : ''">
                                            {{ item.total_quantity_loaded }}
                                        </span>
                                    </template>

                                    <template #item.total_quantity_left="{ item }">
                                        <span :class="item.items.length > 1 ? 'font-semibold' : ''">
                                            {{ item.total_quantity_left }}
                                        </span>
                                    </template>

                                    <!-- Lots count badge -->
                                    <template #item.lots_count="{ item }">
                                        <v-chip v-if="item.items.length > 1" size="x-small" color="blue-grey-lighten-4" variant="flat">
                                            {{ item.items.length }} lots
                                        </v-chip>
                                        <span v-else class="text-gray-400 text-xs">1 lot</span>
                                    </template>

                                    <!-- Expanded rows: one row per individual load entry -->
                                    <template #expanded-row="{ columns, item }">
                                        <tr
                                            v-for="loadEntry in item.items"
                                            :key="loadEntry.id"
                                            class="bg-blue-grey-lighten-5"
                                        >
                                            <!-- indent spacer (expand column) -->
                                            <td></td>
                                            <!-- Product column: show date instead -->
                                            <td class="text-sm text-gray-500 py-1">
                                                <v-icon size="x-small" color="grey" class="mr-1">mdi-calendar</v-icon>
                                                {{ loadEntry.loaded_at ? new Date(loadEntry.loaded_at).toLocaleDateString('fr-FR') : '-' }}
                                            </td>
                                            <!-- quantity_loaded (inline edit) -->
                                            <td class="text-right py-1">
                                                <v-text-field
                                                    v-if="editingItemId === loadEntry.id"
                                                    v-model="editingItemQuantityLoaded"
                                                    type="number"
                                                    density="compact"
                                                    hide-details
                                                    class="w-20 ml-auto"
                                                />
                                                <span v-else class="text-gray-600 text-sm">{{ loadEntry.quantity_loaded }}</span>
                                            </td>
                                            <!-- quantity_left (inline edit) -->
                                            <td class="text-right py-1">
                                                <v-text-field
                                                    v-if="editingItemId === loadEntry.id"
                                                    v-model="editingItemQuantityLeft"
                                                    type="number"
                                                    density="compact"
                                                    hide-details
                                                    class="w-20 ml-auto"
                                                />
                                                <span v-else class="text-gray-600 text-sm">{{ loadEntry.quantity_left }}</span>
                                            </td>
                                            <!-- actions -->
                                            <td class="text-right py-1">
                                                <div class="flex gap-1 justify-end">
                                                    <template v-if="editingItemId === loadEntry.id">
                                                        <v-btn icon density="compact" variant="text" color="success" :loading="editItemForm.processing" @click="saveEditingItem(loadEntry)">
                                                            <v-icon size="small">mdi-check</v-icon>
                                                        </v-btn>
                                                        <v-btn icon density="compact" variant="text" @click="cancelEditingItem">
                                                            <v-icon size="small">mdi-close</v-icon>
                                                        </v-btn>
                                                    </template>
                                                    <template v-else>
                                                        <v-btn icon density="compact" variant="text" color="grey" @click="goToProductHistory(loadEntry.product_id)">
                                                            <v-icon size="small">mdi-history</v-icon>
                                                        </v-btn>
                                                        <v-btn icon density="compact" variant="text" :disabled="isUnloaded" @click="startEditingItem(loadEntry)">
                                                            <v-icon size="small">mdi-pencil</v-icon>
                                                        </v-btn>
                                                        <v-btn icon density="compact" variant="text" color="error" :disabled="isUnloaded" @click="requestDeleteItem(loadEntry.id)">
                                                            <v-icon size="small">mdi-delete</v-icon>
                                                        </v-btn>
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </v-data-table>

                            </v-card-text>
                        </v-window-item>

                        <!-- ═══════════ INVENTAIRE TAB ═══════════ -->
                        <v-window-item value="inventaire">
                            <v-card-text class="pa-2 sm:pa-4">

                                <!-- No inventory yet -->
                                <template v-if="!carLoad.inventory">
                                    <div class="text-center py-10">
                                        <v-icon size="52" color="grey-lighten-2">mdi-clipboard-list-outline</v-icon>
                                        <p class="text-gray-400 mt-2 mb-4">Aucun inventaire créé pour ce chargement.</p>
                                        <div class="flex justify-center gap-2 items-start flex-col sm:flex-row max-w-sm mx-auto">
                                            <v-text-field
                                                v-model="inventoryForm.name"
                                                label="Nom de l'inventaire"
                                                density="compact"
                                                hide-details
                                                class="w-full"
                                                :error-messages="inventoryForm.errors.name"
                                            />
                                            <v-btn
                                                color="primary"
                                                variant="flat"
                                                size="small"
                                                class="shrink-0"
                                                :loading="inventoryForm.processing"
                                                @click="createInventory"
                                            >Créer</v-btn>
                                        </div>
                                    </div>
                                </template>

                                <!-- Inventory exists -->
                                <template v-else>

                                    <!-- Inventory actions bar -->
                                    <div class="flex items-center gap-2 mb-3 flex-wrap">
                                        <div class="mr-auto">
                                            <span class="font-medium text-sm">{{ carLoad.inventory.name }}</span>
                                            <v-chip v-if="carLoad.inventory.closed" color="success" size="x-small" class="ml-2">Clôturé</v-chip>
                                            <v-chip v-else color="warning" size="x-small" class="ml-2">En cours</v-chip>
                                        </div>
                                        <v-btn size="small" variant="outlined" color="red" prepend-icon="mdi-file-pdf-box" @click="exportInventoryPdf">
                                            PDF
                                        </v-btn>
                                        <v-btn
                                            v-if="!carLoad.inventory.closed"
                                            size="small"
                                            color="warning"
                                            variant="flat"
                                            prepend-icon="mdi-lock"
                                            :loading="closeInventoryForm.processing"
                                            @click="closeInventory"
                                        >Clôturer</v-btn>
                                        <v-btn
                                            v-if="carLoad.inventory.closed"
                                            size="small"
                                            color="success"
                                            variant="flat"
                                            prepend-icon="mdi-plus"
                                            :loading="createNewCarLoadFromInventoryForm.processing"
                                            @click="createNewCarLoadFromInventory"
                                        >Nouveau chargement</v-btn>
                                    </div>

                                    <!-- Inventory items table -->
                                    <v-data-table
                                        :headers="inventoryTableHeaders"
                                        :items="carLoad.inventory.items ?? []"
                                        :items-per-page="100"
                                        density="compact"
                                        class="elevation-0"
                                    >
                                        <template #item.total_returned="{ item }">
                                            <v-text-field
                                                v-if="editingInventoryItemId === item.id"
                                                v-model="editingInventoryReturnedQuantity"
                                                type="number"
                                                density="compact"
                                                hide-details
                                                class="w-20"
                                            />
                                            <span v-else>{{ item.total_returned }}</span>
                                        </template>

                                        <template #item.result="{ item }">
                                            <v-chip
                                                :color="inventoryResultColor(inventoryResultValue(item))"
                                                size="x-small"
                                                variant="flat"
                                            >
                                                {{ inventoryResultValue(item) >= 0 ? '+' : '' }}{{ inventoryResultValue(item) }}
                                            </v-chip>
                                        </template>

                                        <template #item.actions="{ item }">
                                            <div class="flex gap-1 justify-end">
                                                <template v-if="editingInventoryItemId === item.id">
                                                    <v-btn icon density="compact" variant="text" color="success" :loading="editInventoryItemForm.processing" @click="saveEditingInventoryItem(item)">
                                                        <v-icon>mdi-check</v-icon>
                                                    </v-btn>
                                                    <v-btn icon density="compact" variant="text" @click="cancelEditingInventoryItem">
                                                        <v-icon>mdi-close</v-icon>
                                                    </v-btn>
                                                </template>
                                                <template v-else>
                                                    <v-btn
                                                        icon density="compact" variant="text"
                                                        :disabled="carLoad.inventory.closed"
                                                        @click="startEditingInventoryItem(item)"
                                                    >
                                                        <v-icon size="small">mdi-pencil</v-icon>
                                                    </v-btn>
                                                    <v-btn
                                                        icon density="compact" variant="text" color="error"
                                                        :disabled="carLoad.inventory.closed"
                                                        @click="requestDeleteInventoryItem(item)"
                                                    >
                                                        <v-icon size="small">mdi-delete</v-icon>
                                                    </v-btn>
                                                </template>
                                            </div>
                                        </template>
                                    </v-data-table>

                                    <!-- Inventory bulk entry form — one input row per un-inventoried product -->
                                    <template v-if="!carLoad.inventory.closed && inventoryEntryRows.length > 0">
                                        <v-divider class="my-4" />
                                        <p class="text-sm font-semibold mb-3">
                                            <v-icon size="small" color="primary" class="mr-1">mdi-clipboard-edit</v-icon>
                                            Saisie physique — {{ inventoryEntryRows.length }} produit(s) à inventorier
                                        </p>

                                        <v-table density="compact" class="mb-4 rounded border">
                                            <thead>
                                                <tr class="bg-grey-lighten-4">
                                                    <th class="text-left text-xs font-semibold">Produit</th>
                                                    <th class="text-right text-xs font-semibold">Qté chargée</th>
                                                    <th class="text-right text-xs font-semibold" style="width:160px">Qté retournée</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="(row, index) in inventoryEntryRows"
                                                    :key="row.product_id"
                                                >
                                                    <td class="text-sm py-2">{{ row.product_name }}</td>
                                                    <td class="text-right text-sm text-gray-400 py-2">{{ row.quantity_loaded }}</td>
                                                    <td class="text-right py-1">
                                                        <v-text-field
                                                            v-model.number="row.total_returned"
                                                            type="number"
                                                            density="compact"
                                                            hide-details
                                                            min="0"
                                                            placeholder="0"
                                                            class="w-28 ml-auto"
                                                            :error-messages="inventoryEntryForm.errors[`items.${index}.total_returned`]"
                                                        />
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-table>

                                        <div class="flex justify-end">
                                            <v-btn
                                                color="primary"
                                                variant="flat"
                                                size="small"
                                                prepend-icon="mdi-check-all"
                                                :loading="inventoryEntryForm.processing"
                                                @click="submitInventoryEntries"
                                            >
                                                Soumettre l'inventaire
                                            </v-btn>
                                        </div>
                                    </template>

                                </template>
                            </v-card-text>
                        </v-window-item>

                    </v-window>
                </v-card>
            </div>
        </div>

        <!-- Delete item confirmation -->
        <v-dialog v-model="showDeleteItemDialog" max-width="380">
            <v-card>
                <v-card-title class="text-base pa-4">Supprimer cet article?</v-card-title>
                <v-card-text class="pa-4 pt-0">Cette action est irréversible.</v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="showDeleteItemDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" :loading="deleteItemForm.processing" @click="confirmDeleteItem">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete inventory item confirmation -->
        <v-dialog v-model="showDeleteInventoryItemDialog" max-width="380">
            <v-card>
                <v-card-title class="text-base pa-4">Supprimer de l'inventaire?</v-card-title>
                <v-card-text class="pa-4 pt-0">Cette action est irréversible.</v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="showDeleteInventoryItemDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" :loading="deleteInventoryItemForm.processing" @click="confirmDeleteInventoryItem">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbars -->
        <v-snackbar v-model="showSuccessSnackbar" color="success" :timeout="3000" location="bottom">
            {{ successMessage }}
        </v-snackbar>
        <v-snackbar v-model="showErrorSnackbar" color="error" :timeout="5000" location="bottom">
            {{ errorMessage }}
        </v-snackbar>

    </AuthenticatedLayout>
</template>
