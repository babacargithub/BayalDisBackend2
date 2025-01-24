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
              <td>{{ item.product.name }}</td>
              <td>{{ item.quantity }}</td>
              <td>{{ formatPrice(item.price) }}</td>
              <td>{{ formatPrice(item.subtotal) }}</td>
              <td v-if="!invoice.paid">
                <v-btn
                  icon
                  color="error"
                  size="small"
                  @click="deleteItem(item)"
                  :title="'Supprimer'"
                >
                  <v-icon>mdi-delete</v-icon>
                </v-btn>
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

const deleteItem = (item) => {
  if (confirm('Are you sure you want to delete this item?')) {
    router.delete(route('sales-invoices.items.destroy', [props.invoice.id, item.id]), {
      preserveScroll: true,
      onSuccess: () => {
        emit('updated');
      }
    })
  }
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
</script> 