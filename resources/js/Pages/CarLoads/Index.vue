<script setup>
import { ref , onMounted} from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useForm } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { formatAmount } from '@/helpers';
import TableWithInlineEdit from '@/Components/TableWithInlineEditTemplate.vue';
import moment from "moment";

const props = defineProps({
    carLoads: {
        type: Object,
        required: true
    },
    teams: {
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
    team_id: null,
    comment: '',
    return_date: null,
});
const currentDate = moment().format('YYYY-MM-DD');

const itemForm = useForm({

    items: [
        {
            product_id: null,
            quantity_loaded: null,
            loaded_at: currentDate,
            comment: ''
        }
    ]
});

const inventoryForm = useForm({
    name: ''
});

const headers = [
    { text: 'Nom', value: 'name' },
    { text: 'Équipe responsable', value: 'team.name' },
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
    form.team_id = carLoad.team_id;
    form.comment = carLoad.comment || '';
    form.return_date = carLoad.return_date ? new Date(carLoad.return_date).toISOString().split('T')[0] : null;
    showEditDialog.value = true;
    showNewDialog.value = true;
};

const submit = () => {
    if (editingCarLoad.value) {
        form.put(route('car-loads.update', editingCarLoad.value.id), {
            onSuccess: () => {
                showNewDialog.value = false;
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

const closeDialog = () => {
    showNewDialog.value = false;
    showEditDialog.value = false;
    editingCarLoad.value = null;
    form.reset();
    form.clearErrors();
};

const openItemsDialog = (carLoad) => {
    selectedCarLoad.value = carLoad;
    showItemsDialog.value = true;
};

const addItemRow = () => {
  const currentDate = moment().format('YYYY-MM-DD');
    itemForm.items.push({
        product_id: null,
        quantity_loaded: null,
        loaded_at: currentDate,
        comment: ''
    });
};

const removeItemRow = (index) => {
    itemForm.items.splice(index, 1);
};

const submitItems = () => {
    itemForm.post(route('car-loads.items.store', {
        carLoad: selectedCarLoad.value.id
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
const editingQuantityLeft = ref(null);
const editingInventoryItemId = ref(null);
const editingReturnedQuantity = ref(null);
const showInventoryItemDeleteDialog = ref(false);
const inventoryItemToDelete = ref(null);

// Create a separate form for editing items
const editItemForm = useForm({
    quantity_loaded: null,
   quantity_left: null,
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
    editingQuantityLeft.value = item.quantity_left;
    editItemForm.quantity_loaded = item.quantity_loaded;
    editItemForm.quantity_left = item.quantity_left;
};

const cancelEditing = () => {
    editingItemId.value = null;
    editingQuantity.value = null;
    editingQuantityLeft.value = null;
    editItemForm.reset();
};

const saveEditing = (item) => {
    editItemForm.quantity_loaded = editingQuantity.value;
    editItemForm.quantity_left = editingQuantityLeft.value;
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

const closeInventory = (inventory) => {
    form.put(route('car-loads.inventories.close', {
        carLoad: selectedCarLoad.value.id,
        inventory: inventory.id
    }), {
        preserveScroll: true,
        onSuccess: (page) => {
            successMessage.value = 'L\'inventaire a été clôturé avec succès';
            showSuccessSnackbar.value = true;
            selectedCarLoad.value = page.props.carLoads.data.find(
                carLoad => carLoad.id === selectedCarLoad.value.id
            );
        },
        onError: (errors) => {
            errorMessage.value = Object.values(errors).flat().join(', ');
            showErrorSnackbar.value = true;
        }
    });
};

const addMissingProduct = (items) => {
    // Create a form with a single item
    const singleItemForm = useForm({
        items: items?.map((item) => {
          return {
            product_id: item.id,
            total_returned: item.total_returned,
            comment: ''

          }
        })
    });

    // Submit the form
    singleItemForm.post(route('car-loads.inventories.items.store', {
        carLoad: selectedCarLoad.value.id,
        inventory: selectedCarLoad.value.inventory.id
    }), {
        preserveScroll: true,
        onSuccess: (page) => {
            successMessage.value = 'Produit ajouté à l\'inventaire avec succès';
            showSuccessSnackbar.value = true;
            selectedCarLoad.value = page.props.carLoads.data.find(
                carLoad => carLoad.id === selectedCarLoad.value.id
            );
        },
        onError: (errors) => {
            errorMessage.value = Object.values(errors).flat().join(', ');
            showErrorSnackbar.value = true;
        }
    });
};

const createNewCarLoadFromInventory = () => {
    if (!selectedCarLoad.value?.inventory) return;

    const inventory = selectedCarLoad.value.inventory;
    const newCarLoadForm = useForm({
        name: `Crée à partir de ${inventory.name}`,
    });

    newCarLoadForm.post(route('car-loads.create-from-inventory', { inventory: inventory.id }), {
        onSuccess: (page) => {
            showInventoryDialog.value = false;
            // Check for flash messages from the backend
            if (page.props.flash.success) {
                successMessage.value = page.props.flash.success;
                showSuccessSnackbar.value = true;
            }
        },
        onError: (errors) => {
            // Handle error response from the server
            if (errors.error) {
                errorMessage.value = errors.error;
            } else if (errors.message) {
                errorMessage.value = errors.message;
            } else {
                errorMessage.value = 'Une erreur est survenue lors de la création du chargement';
            }
            showErrorSnackbar.value = true;
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
                            :items-per-page="100"
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

                               <template v-if="!item.inventory?.closed">
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
                               </template>

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

                        <!-- New/Edit Dialog of Car Load -->
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
                                            variant="outlined"
                                            color="primary"
                                        ></v-text-field>

                                        <v-select
                                            v-model="form.team_id"
                                            :items="teams"
                                            item-title="name"
                                            item-value="id"
                                            label="Équipe Responsable"
                                            required
                                            :error-messages="form.errors.team_id"
                                            variant="outlined"
                                            color="primary"
                                        ></v-select>

                                        <v-text-field
                                            v-model="form.return_date"
                                            label="Date de retour"
                                            type="date"
                                            required
                                            :error-messages="form.errors.return_date"
                                            variant="outlined"
                                            color="primary"
                                        ></v-text-field>

                                        <v-textarea
                                            v-model="form.comment"
                                            label="Commentaire"
                                            :error-messages="form.errors.comment"
                                            variant="outlined"
                                            color="primary"
                                        ></v-textarea>
                                    </v-form>
                                </v-card-text>

                                <v-card-actions>
                                    <v-spacer></v-spacer>
                                    <v-btn
                                        color="error"
                                        text
                                        @click="closeDialog"
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

                        <!-- Show Carload Items Dialog -->
                        <v-dialog v-model="showItemsDialog" max-width="800px">
                            <v-card>
                                <v-card-title class="text-h5">
                                    <div>
                                     {{ selectedCarLoad?.name }}
                                    </div>
                                </v-card-title>

                                <v-card-text>
                                  <!-- Toggle button for add items form -->
                                  <div v-if="selectedCarLoad?.items?.length && !selectedCarLoad?.inventory?.closed" class="d-flex justify-center mb-4">
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
                                  <div v-if="(!selectedCarLoad?.items?.length || showAddItemsForm) && !selectedCarLoad?.inventory?.closed">
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
                                            v-model="item.loaded_at"
                                            type="date"
                                            label="Chargé le "
                                            class="mr-2"
                                            :error-messages="itemForm.errors[`items.${index}.loaded_at`]"
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
                                            flat
                                            @click="addItemRow"
                                        >
                                          <v-icon left>mdi-plus</v-icon>
                                        </v-btn>
                                      </div>
                                      <div class="d-flex justify-end mb-4">
                                        <v-btn
                                            color="primary"
                                            @click="submitItems"
                                            v-if="!selectedCarLoad?.inventory?.closed"
                                            :loading="itemForm.processing"
                                        >
                                          Enregistrer
                                        </v-btn>
                                      </div>
                                    </v-form>
                                  </div>

                                  <!-- Existing Items Table -->
                                  <p>Valeur stock :
                                    <span class="font-bold ">{{selectedCarLoad?.stock_value?.toLocaleString()
                                      }}FCFA</span></p>
                                    <v-data-table
                                        v-if="selectedCarLoad?.items?.length > 0"
                                        :headers="[
                                            { title: 'Produit', key: 'product.name' },
                                            { title: 'Qté chargé', key: 'quantity_loaded' },
                                            { title: 'Reste', key: 'quantity_left' },
                                            { title: 'Commentaire', key: 'comment' },
                                            { title: 'Chargé le', key: 'loaded_at' },
                                            { title: 'Actions', key: 'actions', sortable: false }
                                        ]"
                                        :items="selectedCarLoad.items"
                                        :items-per-page="-1"
                                        hide-default-footer
                                        class="elevation-1 mb-4"
                                    >
                                        <template v-slot:item.quantity_loaded="{ item }">
                                            <template v-if="editingItemId === item.id && !selectedCarLoad?.inventory?.closed">
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
                                      <template v-slot:item.quantity_left="{ item }">
                                            <template v-if="editingItemId === item.id && !selectedCarLoad?.inventory?.closed">
                                                <v-text-field
                                                    v-model="editingQuantityLeft"
                                                    type="number"
                                                    dense
                                                    hide-details
                                                    class="mt-0 pt-0"
                                                    @keyup.enter="saveEditing(item)"
                                                    @keyup.esc="cancelEditing"
                                                ></v-text-field>
                                            </template>
                                            <template v-else>
                                                {{ item.quantity_left }}
                                            </template>
                                        </template>
                                        <template v-slot:item.loaded_at="{ item }">
                                            {{ new Date(item.loaded_at ??
                                            item.created_at).toLocaleDateString('fr-FR', {
                                                day: '2-digit',
                                                // month like feb.
                                                month: 'short',
                                                year: 'numeric',

                                            }) }}
                                            </template>
                                      <template v-slot:item.comment="{ item }">
                                        <v-btn variant="flat" v-if="item?.comment != null">
                                          <v-icon>mdi-comment</v-icon>

                                          <v-tooltip
                                              activator="parent"
                                              location="top"
                                          >{{item.comment}}</v-tooltip>
                                        </v-btn>

                                            </template>
                                        <template v-slot:item.actions="{ item }">
                                            <template v-if="!selectedCarLoad?.inventory?.closed">
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
                                        </template>
                                    </v-data-table>

                                    <div v-else class="text-center py-4">
                                        Aucun article dans ce chargement
                                    </div>

                                    <!-- Add export button -->
                                    <div v-if="selectedCarLoad?.items?.length" class="d-flex justify-end mb-4">
                                        <v-btn
                                            color="info"
                                            variant="text"
                                            @click="exportCarLoadItemsPdf(selectedCarLoad.id)"
                                            class="px-6"
                                        >
                                            <v-icon left>mdi-file-pdf-box</v-icon>
                                            PDF
                                        </v-btn>
                                    </div>

                                    <!-- Add error message display -->
                                    <div v-if="formError" class="text-center py-2">
                                        <span class="text-error">{{ formError }}</span>
                                    </div>

                                    <v-divider class="my-4"></v-divider>


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
                                                    :allow-add="!inventory?.closed"
                                                >
                                                    <template #add-form-fields="{ item, index, errors }">
                                                        <v-select
                                                            v-model="item.product_id"
                                                            :items="products"
                                                            item-title="name"
                                                            item-value="id"
                                                            label="Produit"
                                                            class="mr-2"
                                                            :error-messages="errors[`items.${index}.product_id`]"
                                                            required
                                                        ></v-select>

                                                        <v-text-field
                                                            v-model="item.total_returned"
                                                            type="number"
                                                            label="Quantité retournée"
                                                            class="mr-2"
                                                            :error-messages="errors[`items.${index}.total_returned`]"
                                                            required
                                                        ></v-text-field>

                                                        <v-text-field
                                                            v-model="item.comment"
                                                            label="Commentaire"
                                                            class="mr-2"
                                                            :error-messages="errors[`items.${index}.comment`]"
                                                        ></v-text-field>
                                                    </template>
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
                                                        <template v-if="editingInventoryItemId === item.id && !selectedCarLoad.inventory.closed">
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
                                                            <template v-if="!selectedCarLoad.inventory?.closed">
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

                                                <!-- Missing Products Section -->
                                                <div v-if="selectedCarLoad.missing_products?.length" class="mt-6">
                                                    <v-expansion-panels>
                                                        <v-expansion-panel>
                                                            <v-expansion-panel-title>
                                                                <div class="d-flex align-center">
                                                                    <v-icon color="warning" class="mr-2">
                                                                        mdi-alert-circle
                                                                    </v-icon>
                                                                    Produits non inventoriés ({{ selectedCarLoad.missing_products.length }})
                                                                </div>
                                                            </v-expansion-panel-title>
                                                            <v-expansion-panel-text>
                                                                <v-data-table
                                                                    :headers="[
                                                                        { title: 'Produit', key: 'name' },
                                                                        { title: 'Qté chargée', key: 'quantity_loaded' },
                                                                        { title: 'Qt retourné', key:
                                                                        'quantity_returned',
                                                                        align:
                                                                        'right' }
                                                                    ]"
                                                                    :items="selectedCarLoad.missing_products"
                                                                    :items-per-page="selectedCarLoad.missing_products?.length"
                                                                    hide-default-footer
                                                                    class="elevation-1"
                                                                >
                                                                    <template v-slot:item.quantity_returned="{ item }">
<!--                                                                      create input field for quantity returned-->
                                                                      <div class="v-row">
                                                                        <v-text-field
                                                                            v-model="item.total_returned"
                                                                            type="number"
                                                                            label="Qté retournée"
                                                                            class="mr-2"
                                                                            :error-messages="itemForm.errors[`items.${index}.quantity_loaded`]"
                                                                            required/>

                                                                      </div>
                                                                    </template>
                                                                </v-data-table>
                                                              <div class="flex items-center">
                                                                <v-btn
                                                                    v-if="!inventory.closed"
                                                                    color="primary"
                                                                    variant="text"
                                                                    size="small"
                                                                    @click="addMissingProduct(selectedCarLoad.missing_products)"
                                                                >
                                                                  <v-icon left>mdi-check</v-icon>
                                                                  Enregistrer
                                                                </v-btn>
                                                              </div>
                                                            </v-expansion-panel-text>
                                                        </v-expansion-panel>
                                                    </v-expansion-panels>
                                                </div>

                                                <v-divider class="my-4"></v-divider>

                                              <v-btn-group divided rounded>
                                                <v-btn
                                                    color="info"
                                                    variant="text"
                                                    @click="exportInventoryPdf(selectedCarLoad.id, inventory.id)"
                                                    class="px-6 mr-2"
                                                >
                                                  <v-icon left>mdi-file-pdf-box</v-icon>
                                                  PDF
                                                </v-btn>
                                                <v-btn
                                                    color="primary"
                                                    :disabled="inventory?.closed"
                                                    @click="() => closeInventory(inventory)"
                                                    class="px-6"
                                                >
                                                  <v-icon left>mdi-lock</v-icon>
                                                  Clôturer l'inventaire
                                                </v-btn>
                                                <v-btn
                                                    v-if="selectedCarLoad?.inventory?.closed"
                                                    color="primary"
                                                    stacked variant="text"
                                                    @click="createNewCarLoadFromInventory"
                                                    :loading="form.processing"
                                                >
                                                  <v-icon left>mdi-plus</v-icon>
                                                  Créer un nouveau chargement
                                                </v-btn>
                                              </v-btn-group>
                                            </v-window-item>
                                        </v-window>
                                    </template>
                                    <template v-else>
                                        <v-form @submit.prevent="createInventory" class="mt-4">
                                            <v-text-field
                                                v-model="inventoryForm.name"
                                                label="Nom de l'inventaire"
                                                :error-messages="inventoryForm.errors.name"
                                                variant="outlined"
                                                color="primary"
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