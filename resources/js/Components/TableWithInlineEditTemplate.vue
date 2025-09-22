<!--
This is a template component that implements common table features:
1. Inline editing of fields
2. Item deletion with confirmation
3. Adding single or multiple items
4. Automatic list updates after operations
5. Success notifications

Usage Example:
<table-with-inline-edit
    :items="yourItems"
    :headers="yourHeaders"
    :routes="{
        update: 'your.update.route',
        delete: 'your.delete.route',
        store: 'your.store.route'
    }"
    :parent-id="optionalParentId"
    @item-updated="handleUpdate"
    @item-deleted="handleDelete"
    @items-added="handleAdd"
>
  Optional slot for custom add form fields 
    <template #add-form-fields="{ item, index, errors }">
        Your form fields here
    </template>
</table-with-inline-edit>-->


<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    // The items to display in the table
    items: {
        type: Array,
        required: true
    },
    // The headers configuration for the table
    headers: {
        type: Array,
        required: true
    },
    // Route names for different operations
    routes: {
        type: Object,
        required: true,
        validator: (value) => {
            return ['update', 'delete', 'store'].every(key => key in value);
        }
    },
    // Optional parent ID for nested resources
    parentId: {
        type: [Number, String],
        default: null
    },
    // Field that can be edited inline (default to 'quantity')
    editableField: {
        type: String,
        default: 'quantity'
    },
    // Additional route params for API calls
    additionalRouteParams: {
        type: Object,
        default: () => ({})
    },
    // Whether adding new items is allowed
    allowAdd: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['item-updated', 'item-deleted', 'items-added']);

// State management
const showAddItemsForm = ref(false);
const showConfirmDialog = ref(false);
const showSuccessSnackbar = ref(false);
const successMessage = ref('');
const formError = ref('');
const itemToDelete = ref(null);
const editingItemId = ref(null);
const editingValue = ref(null);

// Forms
const editItemForm = useForm({
    [props.editableField]: null
});

const itemForm = useForm({
    items: [
        {
            // This will be populated by slot content
        }
    ]
});

// Item Actions
const startEditing = (item) => {
    editingItemId.value = item.id;
    editingValue.value = item[props.editableField];
    editItemForm[props.editableField] = item[props.editableField];
};

const cancelEditing = () => {
    editingItemId.value = null;
    editingValue.value = null;
    editItemForm.reset();
};

const saveEditing = (item) => {
    editItemForm[props.editableField] = editingValue.value;
    
    const routeParams = {
        ...props.additionalRouteParams,
        id: item.id
    };
    if (props.parentId) {
        routeParams.parentId = props.parentId;
    }

    editItemForm.put(route(props.routes.update, routeParams), {
        preserveScroll: true,
        onSuccess: (page) => {
            editingItemId.value = null;
            editingValue.value = null;
            successMessage.value = 'Mise à jour effectuée avec succès';
            showSuccessSnackbar.value = true;
            emit('item-updated', page);
        }
    });
};

const deleteItem = (id) => {
    itemToDelete.value = id;
    showConfirmDialog.value = true;
};

const confirmDelete = () => {
    const routeParams = {
        ...props.additionalRouteParams,
        id: itemToDelete.value
    };
    if (props.parentId) {
        routeParams.parentId = props.parentId;
    }

    editItemForm.delete(route(props.routes.delete, routeParams), {
        preserveScroll: true,
        onSuccess: (page) => {
            showConfirmDialog.value = false;
            itemToDelete.value = null;
            successMessage.value = 'Suppression effectuée avec succès';
            showSuccessSnackbar.value = true;
            emit('item-deleted', page);
        }
    });
};

// Add Items Form Actions
const addItemRow = () => {
    itemForm.items.push({});
};

const removeItemRow = (index) => {
    itemForm.items.splice(index, 1);
};

const submitItems = () => {
    formError.value = '';
    const routeParams = props.parentId ? { parentId: props.parentId } : {};
    // Add additional route params
    Object.assign(routeParams, props.additionalRouteParams);
    
    itemForm.post(route(props.routes.store, routeParams), {
        onSuccess: (page) => {
            showAddItemsForm.value = false;
            itemForm.reset();
            emit('items-added', page);
        },
        onError: (errors) => {
            formError.value = Object.values(errors).flat().join(', ');
        }
    });
};

// Add this function to handle nested object properties
const getNestedValue = (obj, path) => {
    return path.split('.').reduce((current, key) => 
        current ? current[key] : undefined, obj
    );
};
</script>

<template>
    <div>
        <!-- Main Table -->
        <v-data-table
            :headers="headers"
            :items="items"
            :items-per-page="20000"
            hide-default-footer
            class="elevation-1 mb-4"
        >
            <template v-for="header in headers" :key="header.key" #[`item.${header.key}`]="slotProps">
                <!-- If there's a custom slot for this column, use it -->
                <template v-if="$slots[`item.${header.key}`]">
                    <slot 
                        :name="`item.${header.key}`" 
                        v-bind="slotProps"
                    ></slot>
                </template>
                <!-- Otherwise, use default rendering -->
                <template v-else>
                    <template v-if="header.key === editableField && editingItemId === slotProps.item.id">
                        <v-text-field
                            v-model="editingValue"
                            :type="header.type || 'text'"
                            dense
                            hide-details
                            class="mt-0 pt-0"
                            @keyup.enter="saveEditing(slotProps.item)"
                            @keyup.esc="cancelEditing"
                        ></v-text-field>
                    </template>
                    <template v-else>
                        {{ getNestedValue(slotProps.item, header.key) }}
                    </template>
                </template>
            </template>

            <template #item.actions="slotProps">
                <slot name="item.actions" v-bind="slotProps">
                    <template v-if="editingItemId === slotProps.item.id">
                        <v-btn 
                            icon 
                            small 
                            density="comfortable"
                            variant="text"
                            color="success"
                            class="mr-2"
                            @click="saveEditing(slotProps.item)"
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
                            @click="startEditing(slotProps.item)"
                        >
                            <v-icon>mdi-pencil</v-icon>
                        </v-btn>
                        <v-btn 
                            icon 
                            small 
                            density="comfortable"
                            variant="text"
                            color="error" 
                            @click="deleteItem(slotProps.item.id)"
                        >
                            <v-icon>mdi-delete</v-icon>
                        </v-btn>
                    </template>
                </slot>
            </template>
        </v-data-table>

        <!-- Add Items Section -->
        <div v-if="allowAdd" class="d-flex justify-center mb-4">
            <v-btn
                color="primary"
                variant="text"
                @click="showAddItemsForm = !showAddItemsForm"
            >
                <v-icon>{{ showAddItemsForm ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                {{ showAddItemsForm ? 'Masquer le formulaire' : 'Ajouter des éléments' }}
            </v-btn>
        </div>

        <div v-if="showAddItemsForm">
            <v-form @submit.prevent="submitItems">
                <div v-for="(item, index) in itemForm.items" :key="index" class="d-flex align-center mb-4">
                    <!-- Slot for custom form fields -->
                    <slot 
                        name="add-form-fields" 
                        :item="item"
                        :index="index"
                        :errors="itemForm.errors"
                    ></slot>

                    <v-btn
                        icon
                        small
                        density="comfortable"
                        variant="text"
                        color="error"
                        @click="removeItemRow(index)"
                        
                    >
                        <v-icon>mdi-delete</v-icon>
                    </v-btn>
                </div>

                <div class="d-flex justify-space-between align-center">
                    <v-btn
                        color="primary"
                        text
                        @click="addItemRow"
                    >
                        <v-icon left>mdi-plus</v-icon>
                        
                    </v-btn>

                    <v-btn
                        color="primary"
                        @click="submitItems"
                        :loading="itemForm.processing"
                    >
                        Enregistrer
                    </v-btn>
                </div>
            </v-form>
        </div>

        <!-- Error Display -->
        <div v-if="formError" class="text-center py-2">
            <span class="text-error">{{ formError }}</span>
        </div>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="showConfirmDialog" max-width="400px">
            <v-card>
                <v-card-title class="text-h5">
                    Confirmation
                </v-card-title>

                <v-card-text>
                    Êtes-vous sûr de vouloir supprimer cet élément? Cette action est irréversible!
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
                        :loading="editItemForm.processing"
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
    </div>
</template> 