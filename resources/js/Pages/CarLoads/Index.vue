<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useForm } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { formatAmount } from '@/helpers';
import TableWithInlineEdit from '@/Components/TableWithInlineEditTemplate.vue';

const props = defineProps({
    carLoads: {
        type: Object,
        required: true
    },
    commercials: {
        type: Array,
        required: true
    },
    products: {
        type: Array,
        required: true
    }
});

const showNewDialog = ref(false);
const showEditDialog = ref(false);
const editingCarLoad = ref(null);
const showItemsDialog = ref(false);
const selectedCarLoad = ref(null);
const showInventoryDialog = ref(false);
const selectedInventory = ref(null);

const form = useForm({
    name: '',
    commercial_id: null,
    comment: '',
    return_date: null,
});

const itemForm = useForm({
    items: [
        {
            product_id: null,
            quantity_loaded: null,
            comment: ''
        }
    ]
});

const inventoryForm = useForm({
    name: ''
});

const headers = [
    { text: 'Nom', value: 'name' },
    { text: 'Commercial', value: 'commercial.name' },
    { text: 'Chargement', value: 'load_date', align: 'center' },
    { text: 'Date de retour', value: 'return_date', align: 'center' },
    { text: 'Actions', value: 'actions', sortable: false },
];

const inventoryHeaders = [
    { title: 'Produit', key: 'product.name' },
    { title: 'Qté chargée', key: 'total_loaded', type: 'number' },
    { title: 'Qté vendu', key: 'total_sold', type: 'number' },
    { title: 'Qté retournée', key: 'total_returned', type: 'number' },
    { title: 'Résultat', key: 'result', align: 'center' },
    { title: 'Commentaire', key: 'comment' },
    { title: 'Actions', key: 'actions', sortable: false, align: 'right' }
];

const deleteCarLoad = async (id) => {
    const result = await Swal.fire({
        title: 'Êtes-vous sûr?',
        text: "Cette action est irréversible!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#030ccc',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Oui, supprimer!',
        cancelButtonText: 'Annuler'
    });

    if (result.isConfirmed) {
        form.delete(route('car-loads.destroy', id), {
            onSuccess: () => {
                Swal.fire(
                    'Supprimé!',
                    'Le chargement a été supprimé.',
                    'success'
                );
            }
        });
    }
};

const openEditDialog = (carLoad) => {
    editingCarLoad.value = carLoad;
    form.name = carLoad.name;
    form.commercial_id = carLoad.commercial_id;
    form.comment = carLoad.comment;
    showEditDialog.value = true;
};

const submit = () => {
    if (editingCarLoad.value) {
        form.put(route('car-loads.update', editingCarLoad.value.id), {
            onSuccess: () => {
                showEditDialog.value = false;
                editingCarLoad.value = null;
                form.reset();
            }
        });
    } else {
        form.post(route('car-loads.store'), {
            onSuccess: () => {
                showNewDialog.value = false;
                form.reset();
            }
        });
    }
};

const openItemsDialog = (carLoad) => {
    selectedCarLoad.value = carLoad;
    showItemsDialog.value = true;
};

const addItemRow = () => {
    itemForm.items.push({
        product_id: null,
        quantity_loaded: null,
        comment: ''
    });
};

const removeItemRow = (index) => {
    itemForm.items.splice(index, 1);
};

const submitItems = () => {
    itemForm.post(route('car-loads.inventories.items.store', {
        carLoad: selectedCarLoad.value.id,
        inventory: selectedCarLoad.value.inventory.id
    }), {
        onSuccess: () => {
            showAddItemsForm.value = false;
            itemForm.reset();
            successMessage.value = 'Articles ajoutés avec succès';
            showSuccessSnackbar.value = true;
        },
        onError: (errors) => {
            errorMessage.value = Object.values(errors).flat().join(', ');
            showErrorSnackbar.value = true;
        }
    });
};

// Add new ref for form error message
const formError = ref('');

// Add new ref for form visibility
const showAddItemsForm = ref(false);

// Add new refs for confirmation dialog and snackbar
const showConfirmDialog = ref(false);
const itemToDelete = ref(null);
const showSuccessSnackbar = ref(false);
const showErrorSnackbar = ref(false);
const successMessage = ref('');
const errorMessage = ref('');
const editingItemId = ref(null);
const editingQuantity = ref(null);
const editingInventoryItemId = ref(null);
const editingReturnedQuantity = ref(null);
const showInventoryItemDeleteDialog = ref(false);
const inventoryItemToDelete = ref(null);

// Create a separate form for editing items
const editItemForm = useForm({
    quantity_loaded: null
});

// Create a separate form for editing inventory items
const editInventoryItemForm = useForm({
    total_returned: null
});

// Replace deleteItem function
const deleteItem = (id) => {
    itemToDelete.value = id;
    showConfirmDialog.value = true;
};

const confirmDelete = () => {
    form.delete(route('car-loads.items.destroy', { 
        carLoad: selectedCarLoad.value.id,
        item: itemToDelete.value 
    }), {
        preserveScroll: true,
        onSuccess: (page) => {
            showConfirmDialog.value = false;
            itemToDelete.value = null;
            successMessage.value = 'L\'article a été supprimé avec succès';
            showSuccessSnackbar.value = true;
            // Update the selected car load with the fresh data
            selectedCarLoad.value = page.props.carLoads.data.find(
                carLoad => carLoad.id === selectedCarLoad.value.id
            );
        }
    });
};

const startEditing = (item) => {
    editingItemId.value = item.id;
    editingQuantity.value = item.quantity_loaded;
    editItemForm.quantity_loaded = item.quantity_loaded;
};

const cancelEditing = () => {
    editingItemId.value = null;
    editingQuantity.value = null;
    editItemForm.reset();
};

const saveEditing = (item) => {
    editItemForm.quantity_loaded = editingQuantity.value;
    editItemForm.put(route('car-loads.items.update', { 
        carLoad: selectedCarLoad.value.id,
        item: item.id 
    }), {
        preserveScroll: true,
        onSuccess: (page) => {
            editingItemId.value = null;
            editingQuantity.value = null;
            successMessage.value = 'La quantité a été mise à jour avec succès';
            showSuccessSnackbar.value = true;
            // Update the selected car load with the fresh data
            selectedCarLoad.value = page.props.carLoads.data.find(
                carLoad => carLoad.id === selectedCarLoad.value.id
            );
        }
    });
};

const openInventoryDialog = (carLoad) => {
    selectedCarLoad.value = carLoad;
    showInventoryDialog.value = true;
};

const createInventory = () => {
    inventoryForm.post(route('car-loads.inventories.store', { carLoad: selectedCarLoad.value.id }), {
        onSuccess: () => {
            inventoryForm.reset();
            showInventoryDialog.value = false;
        }
    });
};

const exportInventoryPdf = (carLoadId, inventoryId) => {
    window.open(route('car-loads.inventories.export-pdf', { carLoad: carLoadId, inventory: inventoryId }));
};

const exportCarLoadItemsPdf = (carLoadId) => {
    // () => window.open(route('car-loads.items.export-pdf', { carLoad: selectedCarLoad.id }))
    window.open(route('car-loads.items.export-pdf', { carLoad: carLoadId }));
};

const deleteInventoryItem = async (item) => {
    inventoryItemToDelete.value = item;
    showInventoryItemDeleteDialog.value = true;
};

const confirmInventoryItemDelete = () => {
    form.delete(route('car-loads.inventories.items.destroy', {
        carLoad: selectedCarLoad.value.id,
        inventory: selectedCarLoad.value.inventory.id,
        item: inventoryItemToDelete.value.id
    }), {
        preserveScroll: true,
        onSuccess: (page) => {
            showInventoryItemDeleteDialog.value = false;
            inventoryItemToDelete.value = null;
            successMessage.value = 'L\'article a été supprimé avec succès';
            showSuccessSnackbar.value = true;
            selectedCarLoad.value = page.props.carLoads.data.find(
                carLoad => carLoad.id === selectedCarLoad.value.id
            );
        },
        onError: (errors) => {
            errorMessage.value = Object.values(errors).flat().join(', ');
            showErrorSnackbar.value = true;
            showInventoryItemDeleteDialog.value = false;
            inventoryItemToDelete.value = null;
        }
    });
};

const startEditingInventoryItem = (item) => {
    editingInventoryItemId.value = item.id;
    editingReturnedQuantity.value = item.total_returned;
    editInventoryItemForm.total_returned = item.total_returned;
};

const cancelEditingInventoryItem = () => {
    editingInventoryItemId.value = null;
    editingReturnedQuantity.value = null;
    editInventoryItemForm.reset();
};

const saveEditingInventoryItem = (item) => {
    editInventoryItemForm.total_returned = editingReturnedQuantity.value;
    editInventoryItemForm.put(route('car-loads.inventories.items.update', {
        carLoad: selectedCarLoad.value.id,
        inventory: selectedCarLoad.value.inventory.id,
        item: item.id
    }), {
        preserveScroll: true,
        onSuccess: (page) => {
            editingInventoryItemId.value = null;
            editingReturnedQuantity.value = null;
            successMessage.value = 'La quantité a été mise à jour avec succès';
            showSuccessSnackbar.value = true;
            selectedCarLoad.value = page.props.carLoads.data.find(
                carLoad => carLoad.id === selectedCarLoad.value.id
            );
        },
        onError: (errors) => {
            errorMessage.value = Object.values(errors).flat().join(', ');
            showErrorSnackbar.value = true;
            editingInventoryItemId.value = null;
            editingReturnedQuantity.value = null;
        }
    });
};
</script>

<template>
    <Head title="Chargements Véhicule" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Chargements Véhicule
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 bg-white border-b border-gray-200">
                        <div class="mb-4">
                            <v-btn
                                color="primary"
                                @click="showNewDialog = true"
                            >
                                <v-icon>mdi-plus</v-icon>
                                Nouveau Chargement
                            </v-btn>
                        </div>

                        <v-data-table
                            :headers="headers"
                            :items="carLoads.data"
                            :items-per-page="10"
                            class="elevation-1"
                        >
                        
                            <template v-slot:item.load_date="{ item }">
                                {{ item.load_date ? new Date(item.load_date).toLocaleDateString('fr-FR', { 
                                    day: '2-digit',
                                    month: '2-digit',
                                    year: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) : '-' }}
                            </template>

                            <template v-slot:item.return_date="{ item }">
                                {{ item.return_date ? new Date(item.return_date).toLocaleDateString('fr-FR', { 
                                    day: '2-digit',
                                    month: '2-digit',
                                    year: 'numeric'
                                }) : '-' }}
                            </template>

                            <template v-slot:item.actions="{ item }">
                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            density="comfortable"
                                            variant="text"
                                            class="mr-2"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="openItemsDialog(item)"
                                        >
                                            <v-icon>mdi-eye</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Voir les articles</span>
                                </v-tooltip>

                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            density="comfortable"
                                            variant="text"
                                            class="mr-2"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="openEditDialog(item)"
                                        >
                                            <v-icon>mdi-pencil</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Modifier</span>
                                </v-tooltip>

                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            density="comfortable"
                                            variant="text"
                                            color="error"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="deleteCarLoad(item.id)"
                                        >
                                            <v-icon>mdi-delete</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Supprimer</span>
                                </v-tooltip>

                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn
                                            icon
                                            small
                                            density="comfortable"
                                            variant="text"
                                            class="mr-2"
                                            v-bind="attrs"
                                            v-on="on"
                                            @click="openInventoryDialog(item)"
                                        >
                                            <v-icon>mdi-clipboard-check</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Inventaire</span>
                                </v-tooltip>
                            </template>
                        </v-data-table>

                        <!-- New/Edit Dialog -->
                        <v-dialog
                            v-model="showNewDialog"
                            max-width="600px"
                        >
                            <v-card>
                                <v-card-title>
                                    <span class="text-h5">{{ editingCarLoad ? 'Modifier' : 'Nouveau' }} Chargement</span>
                                </v-card-title>

                                <v-card-text>
                                    <v-form @submit.prevent="submit">
                                        <v-text-field
                                            v-model="form.name"
                                            label="Nom"
                                            required
                                            :error-messages="form.errors.name"
                                        ></v-text-field>

                                        <v-select
                                            v-model="form.commercial_id"
                                            :items="commercials"
                                            item-title="name"
                                            item-value="id"
                                            label="Commercial"
                                            required
                                            :error-messages="form.errors.commercial_id"
                                        ></v-select>

                                        <v-text-field
                                            v-model="form.return_date"
                                            label="Date de retour"
                                            type="date"
                                            required
                                            :error-messages="form.errors.return_date"
                                        ></v-text-field>

                                        <v-textarea
                                            v-model="form.comment"
                                            label="Commentaire"
                                            :error-messages="form.errors.comment"
                                        ></v-textarea>
                                    </v-form>
                                </v-card-text>

                                <v-card-actions>
                                    <v-spacer></v-spacer>
                                    <v-btn
                                        color="error"
                                        text
                                        @click="showNewDialog = showEditDialog = false"
                                    >
                                        Annuler
                                    </v-btn>
                                    <v-btn
                                        color="primary"
                                        @click="submit"
                                        :loading="form.processing"
                                    >
                                        {{ editingCarLoad ? 'Modifier' : 'Créer' }}
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <!-- Items Dialog -->
                        <v-dialog v-model="showItemsDialog" max-width="800px">
                            <v-card>
                                <v-card-title class="text-h5">
                                    <div>
                                     {{ selectedCarLoad?.name }}
                                    </div>
                                </v-card-title>

                                <v-card-text>
                                    <!-- Existing Items Table -->
                                    <v-data-table
                                        v-if="selectedCarLoad?.items?.length"
                                        :headers="[
                                            { title: 'Produit', key: 'product.name' },
                                            { title: 'Quantité', key: 'quantity_loaded' },
                                            { title: 'Commentaire', key: 'comment' },
                                            { title: 'Chargé le', key: 'created_at' },
                                            { title: 'Actions', key: 'actions', sortable: false }
                                        ]"
                                        :items="selectedCarLoad.items"
                                        hide-default-footer
                                        class="elevation-1 mb-4"
                                    >
                                        <template v-slot:item.quantity_loaded="{ item }">
                                            <template v-if="editingItemId === item.id">
                                                <v-text-field
                                                    v-model="editingQuantity"
                                                    type="number"
                                                    dense
                                                    hide-details
                                                    class="mt-0 pt-0"
                                                    @keyup.enter="saveEditing(item)"
                                                    @keyup.esc="cancelEditing"
                                                ></v-text-field>
                                            </template>
                                            <template v-else>
                                                {{ item.quantity_loaded }}
                                            </template>
                                        </template>
                                        <template v-slot:item.created_at="{ item }">
                                            {{ new Date(item.created_at).toLocaleDateString('fr-FR', { 
                                                day: '2-digit',
                                                month: '2-digit',
                                                year: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit'
                                            }) }}
                                            </template>
                                        <template v-slot:item.actions="{ item }">
                                            <template v-if="editingItemId === item.id">
                                                <v-btn 
                                                    icon 
                                                    small 
                                                    density="comfortable"
                                                    variant="text"
                                                    color="success"
                                                    class="mr-2"
                                                    @click="saveEditing(item)"
                                                >
                                                    <v-icon>mdi-check</v-icon>
                                                </v-btn>
                                                <v-btn 
                                                    icon 
                                                    small 
                                                    density="comfortable"
                                                    variant="text"
                                                    color="grey"
                                                    @click="cancelEditing"
                                                >
                                                    <v-icon>mdi-close</v-icon>
                                                </v-btn>
                                            </template>
                                            <template v-else>
                                                <v-btn 
                                                    icon 
                                                    small 
                                                    density="comfortable"
                                                    variant="text"
                                                    class="mr-2"
                                                    @click="startEditing(item)"
                                                >
                                                    <v-icon>mdi-pencil</v-icon>
                                                </v-btn>
                                                <v-btn 
                                                    icon 
                                                    small 
                                                    density="comfortable"
                                                    variant="text"
                                                    color="error" 
                                                    @click="deleteItem(item.id)"
                                                >
                                                    <v-icon>mdi-delete</v-icon>
                                                </v-btn>
                                            </template>
                                        </template>
                                    </v-data-table>

                                    <div v-else class="text-center py-4">
                                        Aucun article dans ce chargement
                                    </div>

                                    <!-- Add export button -->
                                    <div v-if="selectedCarLoad?.items?.length" class="d-flex justify-end mb-4">
                                        <v-btn
                                            color="info"
                                            @click="exportCarLoadItemsPdf(selectedCarLoad.id)"
                                            class="px-6"
                                        >
                                            <v-icon left>mdi-file-pdf-box</v-icon>
                                            Exporter en PDF
                                        </v-btn>
                                    </div>

                                    <!-- Add error message display -->
                                    <div v-if="formError" class="text-center py-2">
                                        <span class="text-error">{{ formError }}</span>
                                    </div>

                                    <v-divider class="my-4"></v-divider>

                                    <!-- Toggle button for add items form -->
                                    <div v-if="selectedCarLoad?.items?.length" class="d-flex justify-center mb-4">
                                        <v-btn
                                            color="primary"
                                            variant="text"
                                            @click="showAddItemsForm = !showAddItemsForm"
                                        >
                                            <v-icon>{{ showAddItemsForm ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                                            {{ showAddItemsForm ? 'Masquer le formulaire' : 'Ajouter des articles' }}
                                        </v-btn>
                                    </div>

                                    <!-- Add New Items Form -->
                                    <div v-if="!selectedCarLoad?.items?.length || showAddItemsForm">
                                        <div class="text-h6 mb-4">Ajouter des articles</div>
                                        <v-form @submit.prevent="submitItems">
                                            <div v-for="(item, index) in itemForm.items" :key="index" class="d-flex align-center mb-4">
                                                <v-select
                                                    v-model="item.product_id"
                                                    :items="products"
                                                    item-title="name"
                                                    item-value="id"
                                                    label="Produit"
                                                    class="mr-2"
                                                    :error-messages="itemForm.errors[`items.${index}.product_id`]"
                                                    required
                                                ></v-select>

                                                <v-text-field
                                                    v-model="item.quantity_loaded"
                                                    type="number"
                                                    label="Quantité"
                                                    class="mr-2"
                                                    :error-messages="itemForm.errors[`items.${index}.quantity_loaded`]"
                                                    required
                                                ></v-text-field>

                                                <v-text-field
                                                    v-model="item.comment"
                                                    label="Commentaire"
                                                    class="mr-2"
                                                    :error-messages="itemForm.errors[`items.${index}.comment`]"
                                                ></v-text-field>

                                                <v-btn
                                                    icon
                                                    small
                                                    density="comfortable"
                                                    variant="text"
                                                    color="error"
                                                    @click="removeItemRow(index)"
                                                    :disabled="itemForm.items.length === 1"
                                                >
                                                    <v-icon>mdi-delete</v-icon>
                                                </v-btn>
                                            </div>

                                            <div class="d-flex justify-end mb-4">
                                                <v-btn
                                                    color="primary"
                                                    text
                                                    @click="addItemRow"
                                                >
                                                    <v-icon left>mdi-plus</v-icon>
                                                    Ajouter une ligne
                                                </v-btn>
                                            </div>
                                        </v-form>
                                    </div>
                                </v-card-text>

                                <v-card-actions>
                                    <v-spacer></v-spacer>
                                    <v-btn
                                        color="error"
                                        text
                                        @click="showItemsDialog = false"
                                    >
                                        Annuler
                                    </v-btn>
                                    <v-btn
                                        color="primary"
                                        @click="submitItems"
                                        :loading="itemForm.processing"
                                    >
                                        Enregistrer
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <!-- Inventory Dialog -->
                        <v-dialog v-model="showInventoryDialog" max-width="1200">
                            <v-card>
                                <v-card-title class="text-h5 pa-4">
                                    Inventaire - {{ selectedCarLoad?.name }}
                                </v-card-title>

                                <v-card-text class="pa-4">
                                    <template v-if="selectedCarLoad?.inventory">
                                        <div class="text-subtitle-1 mb-4">
                                            {{ selectedCarLoad?.inventory.name }}
                                        </div>

                                        <v-window v-model="selectedCarLoad.inventory">
                                            <v-window-item
                                                v-for="inventory in [selectedCarLoad.inventory]"
                                                :key="inventory.id"
                                                :value="inventory"
                                                class="pa-2"
                                            >
                                                <table-with-inline-edit
                                                    :items="inventory.items"
                                                    :headers="inventoryHeaders"
                                                    :routes="{
                                                        update: 'car-loads.inventories.items.update',
                                                        delete: 'car-loads.inventories.items.destroy',
                                                        store: 'car-loads.inventories.items.store'
                                                    }"
                                                    :parent-id="inventory.id"
                                                    :additional-route-params="{ carLoad: selectedCarLoad.id, inventory: inventory.id }"
                                                    editable-field="total_returned"
                                                    class="inventory-table"
                                                    show-actions
                                                >
                                                    <template #item.result="{ item }">
                                                        <div class="d-flex align-center justify-center">
                                                            <v-icon
                                                                :color="(item.total_sold + item.total_returned - item.total_loaded) >= 0 ? 'success' : 'error'"
                                                                class="mr-2"
                                                            >
                                                                {{ (item.total_sold + item.total_returned - item.total_loaded) >= 0 ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                                                            </v-icon>
                                                            <template v-if="(item.total_sold + item.total_returned - item.total_loaded) < 0">
                                                                <span class="font-weight-bold error--text">
                                                                    {{ item.total_sold + item.total_returned - item.total_loaded }}
                                                                </span>
                                                            </template>
                                                            <template v-else>
                                                                {{ item.total_sold + item.total_returned - item.total_loaded }}
                                                            </template>
                                                        </div>
                                                    </template>

                                                    <template #item.total_returned="{ item }">
                                                        <template v-if="editingInventoryItemId === item.id">
                                                            <v-text-field
                                                                v-model="editingReturnedQuantity"
                                                                type="number"
                                                                dense
                                                                hide-details
                                                                class="mt-0 pt-0"
                                                                @keyup.enter="saveEditingInventoryItem(item)"
                                                                @keyup.esc="cancelEditingInventoryItem"
                                                            ></v-text-field>
                                                        </template>
                                                        <template v-else>
                                                            {{ item.total_returned }}
                                                        </template>
                                                    </template>

                                                    <template #item.actions="{ item }">
                                                        <div class="d-flex justify-center">
                                                            <template v-if="editingInventoryItemId === item.id">
                                                                <v-btn 
                                                                    icon 
                                                                    small 
                                                                    density="comfortable"
                                                                    variant="text"
                                                                    color="success"
                                                                    class="mr-2"
                                                                    @click="saveEditingInventoryItem(item)"
                                                                >
                                                                    <v-icon>mdi-check</v-icon>
                                                                </v-btn>
                                                                <v-btn 
                                                                    icon 
                                                                    small 
                                                                    density="comfortable"
                                                                    variant="text"
                                                                    color="grey"
                                                                    @click="cancelEditingInventoryItem"
                                                                >
                                                                    <v-icon>mdi-close</v-icon>
                                                                </v-btn>
                                                            </template>
                                                            <template v-else>
                                                                <v-btn
                                                                    icon
                                                                    small
                                                                    density="comfortable"
                                                                    variant="text"
                                                                    class="mr-2"
                                                                    @click="startEditingInventoryItem(item)"
                                                                >
                                                                    <v-icon>mdi-pencil</v-icon>
                                                                </v-btn>
                                                                <v-btn
                                                                    icon
                                                                    small
                                                                    density="comfortable"
                                                                    variant="text"
                                                                    color="error"
                                                                    @click="deleteInventoryItem(item)"
                                                                >
                                                                    <v-icon>mdi-delete</v-icon>
                                                                </v-btn>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </table-with-inline-edit>

                                                <!-- Inventory Item Delete Confirmation Dialog -->
                                                <v-dialog v-model="showInventoryItemDeleteDialog" max-width="400px">
                                                    <v-card>
                                                        <v-card-title class="text-h5">
                                                            Confirmation
                                                        </v-card-title>

                                                        <v-card-text>
                                                            Êtes-vous sûr de vouloir supprimer cet article? Cette action est irréversible!
                                                        </v-card-text>

                                                        <v-card-actions>
                                                            <v-spacer></v-spacer>
                                                            <v-btn
                                                                color="grey darken-1"
                                                                text
                                                                @click="showInventoryItemDeleteDialog = false"
                                                            >
                                                                Annuler
                                                            </v-btn>
                                                            <v-btn
                                                                color="error"
                                                                @click="confirmInventoryItemDelete"
                                                                :loading="form.processing"
                                                            >
                                                                Supprimer
                                                            </v-btn>
                                                        </v-card-actions>
                                                    </v-card>
                                                </v-dialog>

                                                <div class="d-flex justify-space-between mt-6">
                                                    <v-btn
                                                        color="info"
                                                        @click="exportInventoryPdf(selectedCarLoad.id, inventory.id)"
                                                        class="px-6"
                                                    >
                                                        <v-icon left>mdi-file-pdf-box</v-icon>
                                                        Exporter en PDF
                                                    </v-btn>
                                                    <v-btn
                                                        color="primary"
                                                        :disabled="inventory.closed"
                                                        @click="() => closeInventory(inventory)"
                                                        class="px-6"
                                                    >
                                                        Clôturer l'inventaire
                                                    </v-btn>
                                                </div>
                                            </v-window-item>
                                        </v-window>
                                    </template>
                                    <template v-else>
                                        <v-form @submit.prevent="createInventory" class="mt-4">
                                            <v-text-field
                                                v-model="inventoryForm.name"
                                                label="Nom de l'inventaire"
                                                :error-messages="inventoryForm.errors.name"
                                            ></v-text-field>

                                            <div class="d-flex justify-end mt-4">
                                                <v-btn
                                                    color="primary"
                                                    type="submit"
                                                    :loading="inventoryForm.processing"
                                                >
                                                    Créer l'inventaire
                                                </v-btn>
                                            </div>
                                        </v-form>
                                    </template>
                                </v-card-text>

                                <v-card-actions class="pa-4">
                                    <v-spacer></v-spacer>
                                    <v-btn
                                        color="grey darken-1"
                                        text
                                        @click="showInventoryDialog = false"
                                        class="px-4"
                                    >
                                        Fermer
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <!-- Confirmation Dialog -->
                        <v-dialog v-model="showConfirmDialog" max-width="400px">
                            <v-card>
                                <v-card-title class="text-h5">
                                    Confirmation
                                </v-card-title>

                                <v-card-text>
                                    Êtes-vous sûr de vouloir supprimer cet article? Cette action est irréversible!
                                </v-card-text>

                                <v-card-actions>
                                    <v-spacer></v-spacer>
                                    <v-btn
                                        color="grey darken-1"
                                        text
                                        @click="showConfirmDialog = false"
                                    >
                                        Annuler
                                    </v-btn>
                                    <v-btn
                                        color="error"
                                        @click="confirmDelete"
                                        :loading="form.processing"
                                    >
                                        Supprimer
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <!-- Success Snackbar -->
                        <v-snackbar
                            v-model="showSuccessSnackbar"
                            color="success"
                            timeout="3000"
                        >
                            {{ successMessage }}
                        </v-snackbar>

                        <!-- Error Snackbar -->
                        <v-snackbar
                            v-model="showErrorSnackbar"
                            color="error"
                            timeout="5000"
                        >
                            {{ errorMessage }}
                        </v-snackbar>

                        <!-- Debug section -->
                      
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
/* Remove the SweetAlert styles as they're no longer needed */
</style> 