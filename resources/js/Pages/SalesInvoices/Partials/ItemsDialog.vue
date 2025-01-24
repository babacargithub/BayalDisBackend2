<template>
  <v-dialog v-model="dialog" max-width="800px">
    <v-card>
      <v-card-title class="d-flex justify-space-between align-center">
        <span>Articles de la Facture</span>
        <v-btn
          color="primary"
          @click="showAddItemForm = true"
          v-if="!invoice.paid"
        >
          Ajouter un Article
        </v-btn>
      </v-card-title>

      <v-card-text>
        <!-- Add Item Form -->
        <v-expand-transition>
          <div v-if="showAddItemForm">
            <v-form @submit.prevent="addItem" class="mb-6">
              <v-row>
                <v-col cols="12" md="4">
                  <v-autocomplete
                    v-model="newItem.product_id"
                    :items="products"
                    item-title="name"
                    item-value="id"
                    label="Produit"
                    :error-messages="form.errors.product_id"
                    required
                    clearable
                    :loading="!products.length"
                    :filter="filterProduct"
                    :no-data-text="'Aucun produit trouvé'"
                    @update:model-value="updatePrice"
                  />
                </v-col>
                <v-col cols="12" md="3">
                  <v-text-field
                    v-model.number="newItem.quantity"
                    type="number"
                    label="Quantité"
                    min="1"
                    :error-messages="form.errors.quantity"
                    required
                  />
                </v-col>
                <v-col cols="12" md="3">
                  <v-text-field
                    v-model.number="newItem.price"
                    type="number"
                    label="Prix"
                    min="0"
                    :error-messages="form.errors.price"
                    required
                  />
                </v-col>
                <v-col cols="12" md="2" class="d-flex align-center">
                  <v-btn
                    color="primary"
                    type="submit"
                    :loading="form.processing"
                  >
                    Ajouter
                  </v-btn>
                </v-col>
              </v-row>
            </v-form>
          </div>
        </v-expand-transition>

        <!-- Items List -->
        <v-table>
          <thead>
            <tr>
              <th>Produit</th>
              <th>Quantité</th>
              <th>Prix</th>
              <th>Sous-total</th>
              <th v-if="!invoice.paid">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in invoice.items" :key="item.id">
              <td>
                <template v-if="editingItem?.id === item.id">
                  <v-select
                    v-model="editForm.product_id"
                    :items="products"
                    item-title="name"
                    item-value="id"
                    density="compact"
                    hide-details
                    :error-messages="editForm.errors.product_id"
                  />
                </template>
                <template v-else>
                  {{ item.product.name }}
                </template>
              </td>
              <td>
                <template v-if="editingItem?.id === item.id">
                  <v-text-field
                    v-model.number="editForm.quantity"
                    type="number"
                    min="1"
                    density="compact"
                    hide-details
                    :error-messages="editForm.errors.quantity"
                  />
                </template>
                <template v-else>
                  {{ item.quantity }}
                </template>
              </td>
              <td>
                <template v-if="editingItem?.id === item.id">
                  <v-text-field
                    v-model.number="editForm.price"
                    type="number"
                    min="0"
                    density="compact"
                    hide-details
                    :error-messages="editForm.errors.price"
                  />
                </template>
                <template v-else>
                  {{ formatPrice(item.price) }}
                </template>
              </td>
              <td>{{ formatPrice(item.subtotal) }}</td>
              <td>
                <div class="d-flex gap-2">
                  <template v-if="editingItem?.id === item.id">
                    <v-btn
                      icon
                      size="small"
                      color="success"
                      @click="updateItem"
                      :loading="editForm.processing"
                      :title="'Sauvegarder'"
                    >
                      <v-icon>mdi-check</v-icon>
                    </v-btn>
                    <v-btn
                      icon
                      size="small"
                      color="error"
                      @click="cancelEditing"
                      :title="'Annuler'"
                    >
                      <v-icon>mdi-close</v-icon>
                    </v-btn>
                  </template>
                  <template v-else>
                    <v-btn
                      icon
                      size="small"
                      color="primary"
                      @click="startEditing(item)"
                      :title="'Modifier'"
                    >
                      <v-icon>mdi-pencil</v-icon>
                    </v-btn>
                    <v-btn
                      icon
                      size="small"
                      color="error"
                      @click="openDeleteDialog(item)"
                      :title="'Supprimer'"
                    >
                      <v-icon>mdi-delete</v-icon>
                    </v-btn>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="text-right font-weight-bold">Total:</td>
              <td colspan="2" class="font-weight-bold">
                {{ formatPrice(invoice.total) }}
              </td>
            </tr>
          </tfoot>
        </v-table>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn color="primary" @click="dialog = false">Fermer</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Delete Confirmation Dialog -->
  <v-dialog v-model="showDeleteDialog" max-width="400px">
    <v-card>
      <v-card-title class="text-h6">
        Confirmer la suppression
      </v-card-title>
      <v-card-text>
        <v-alert
          v-if="deleteForm.errors.error"
          type="error"
          class="mb-4"
          variant="tonal"
          closable
        >
          {{ deleteForm.errors.error }}
        </v-alert>
        <div v-else>
          Êtes-vous sûr de vouloir supprimer cet article ?
        </div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn
          color="grey-darken-1"
          variant="text"
          @click="showDeleteDialog = false"
        >
          Annuler
        </v-btn>
        <v-btn
          color="error"
          variant="text"
          @click="confirmDelete"
          :loading="deleteForm.processing"
        >
          Supprimer
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'

const props = defineProps({
  modelValue: Boolean,
  invoice: {
    type: Object,
    required: true
  },
  products: {
    type: Array,
    required: true
  }
})

const emit = defineEmits(['update:modelValue', 'updated'])

const dialog = ref(props.modelValue)
const showAddItemForm = ref(false)

watch(() => props.modelValue, (val) => {
  dialog.value = val
})

watch(dialog, (val) => {
  emit('update:modelValue', val)
  if (!val) {
    showAddItemForm.value = false
  }
})

const newItem = ref({
  product_id: '',
  quantity: 1,
  price: 0
})

const form = useForm({
  product_id: '',
  quantity: 1,
  price: 0
})

const editingItem = ref(null)
const editForm = useForm({
  product_id: null,
  quantity: null,
  price: null
})

const showDeleteDialog = ref(false)
const itemToDelete = ref(null)
const deleteForm = useForm({})

const formatPrice = (price) => {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'XOF',
    minimumFractionDigits: 0,
  }).format(price)
}

const addItem = () => {
  // Sync form data with newItem before submission
  form.product_id = newItem.value.product_id;
  form.quantity = newItem.value.quantity;
  form.price = newItem.value.price;

  form.post(route('sales-invoices.items.store', props.invoice.id), {
    preserveScroll: true,
    onSuccess: () => {
      showAddItemForm.value = false;
      form.reset();
      newItem.value = {
        product_id: '',
        quantity: 1,
        price: 0
      };
      emit('updated');
    }
  });
}

const openDeleteDialog = (item) => {
  deleteForm.clearErrors()
  itemToDelete.value = item
  showDeleteDialog.value = true
}

const confirmDelete = () => {
  deleteForm.delete(route('sales-invoices.items.destroy', [props.invoice.id, itemToDelete.value.id]), {
    preserveScroll: true,
    onSuccess: () => {
      showDeleteDialog.value = false
      itemToDelete.value = null
      emit('updated')
    },
    onError: () => {
      // Keep the dialog open if there are errors
    }
  })
}

const filterProduct = (item, queryText) => {
  const productName = item.name.toLowerCase()
  const searchText = queryText.toLowerCase()
  return productName.includes(searchText)
}

const updatePrice = (productId) => {
  if (productId) {
    const product = props.products.find(p => p.id === productId)
    if (product) {
      newItem.value.price = product.price
    }
  }
}

const startEditing = (item) => {
  editingItem.value = item
  editForm.product_id = item.product_id
  editForm.quantity = item.quantity
  editForm.price = item.price
}

const cancelEditing = () => {
  editingItem.value = null
  editForm.reset()
}

const updateItem = () => {
  editForm.put(route('sales-invoices.items.update', [props.invoice.id, editingItem.value.id]), {
    preserveScroll: true,
    onSuccess: (response) => {
      editingItem.value = null
      editForm.reset()
      if (response?.props?.invoice) {
        Object.assign(props.invoice, response.props.invoice)
      }
      emit('updated')
    }
  })
}

// Add watch for showDeleteDialog
watch(showDeleteDialog, (val) => {
  if (!val) {
    deleteForm.clearErrors()
    itemToDelete.value = null
  }
})
</script> 