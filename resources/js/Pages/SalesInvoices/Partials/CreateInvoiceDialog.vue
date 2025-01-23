<template>
  <v-dialog v-model="dialog" max-width="800px">
    <v-card>
      <v-card-title>Nouvelle Facture</v-card-title>
      <v-card-text>
        <v-form @submit.prevent="submit">
          <v-container>
            <v-row>
              <v-col cols="12">
                <v-autocomplete
                  v-model="form.customer_id"
                  :items="customers"
                  item-title="name"
                  item-value="id"
                  label="Client"
                  :error-messages="form.errors.customer_id"
                  required
                  clearable
                  :loading="!customers.length"
                  :filter="filterCustomer"
                  :no-data-text="'Aucun client trouvé'"
                />
              </v-col>

              <v-col cols="12">
                <v-text-field
                  v-model="form.comment"
                  label="Commentaire"
                  :error-messages="form.errors.comment"
                />
              </v-col>

              <v-col cols="12">
                <v-text-field
                  v-model="form.should_be_paid_at"
                  label="Date d'échéance"
                  type="date"
                  :error-messages="form.errors.should_be_paid_at"
                />
              </v-col>

              <!-- Items Section -->
              <v-col cols="12">
                <div class="d-flex justify-space-between align-center mb-4">
                  <h3>Articles</h3>
                  <v-btn color="primary" @click="addItem">Ajouter un Article</v-btn>
                </div>

                <v-table>
                  <thead>
                    <tr>
                      <th>Produit</th>
                      <th>Quantité</th>
                      <th>Prix</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, index) in form.items" :key="index">
                      <td>
                        <v-autocomplete
                          v-model="item.product_id"
                          :items="products"
                          item-title="name"
                          item-value="id"
                          :error-messages="form.errors[`items.${index}.product_id`]"
                          required
                          clearable
                          :loading="!products.length"
                          :filter="filterProduct"
                          :no-data-text="'Aucun produit trouvé'"
                          @update:model-value="updatePrice(index, $event)"
                        />
                      </td>
                      <td>
                        <v-text-field
                          v-model.number="item.quantity"
                          type="number"
                          min="1"
                          :error-messages="form.errors[`items.${index}.quantity`]"
                          required
                        />
                      </td>
                      <td>
                        <v-text-field
                          v-model.number="item.price"
                          type="number"
                          min="0"
                          :error-messages="form.errors[`items.${index}.price`]"
                          required
                        />
                      </td>
                      <td>
                        <v-btn
                          icon
                          color="error"
                          size="small"
                          @click="removeItem(index)"
                          :title="'Supprimer'"
                        >
                          <v-icon>mdi-delete</v-icon>
                        </v-btn>
                      </td>
                    </tr>
                  </tbody>
                </v-table>
              </v-col>
            </v-row>
          </v-container>
        </v-form>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn color="error" @click="dialog = false">Annuler</v-btn>
        <v-btn
          color="primary"
          :loading="form.processing"
          @click="submit"
        >
          Créer la Facture
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  modelValue: Boolean,
  customers: {
    type: Array,
    required: true
  },
  products: {
    type: Array,
    required: true
  }
})

const emit = defineEmits(['update:modelValue', 'created'])

const dialog = ref(props.modelValue)

watch(() => props.modelValue, (val) => {
  dialog.value = val
})

watch(dialog, (val) => {
  emit('update:modelValue', val)
})

const form = useForm({
  customer_id: '',
  comment: '',
  should_be_paid_at: '',
  items: [
    {
      product_id: '',
      quantity: 1,
      price: 0
    }
  ]
})

const filterCustomer = (item, queryText) => {
  const customerName = item.name.toLowerCase()
  const searchText = queryText.toLowerCase()
  return customerName.includes(searchText)
}

const filterProduct = (item, queryText) => {
  const productName = item.name.toLowerCase()
  const searchText = queryText.toLowerCase()
  return productName.includes(searchText)
}

const updatePrice = (index, productId) => {
  if (productId) {
    const product = props.products.find(p => p.id === productId)
    if (product) {
      form.items[index].price = product.price
    }
  }
}

const addItem = () => {
  form.items.push({
    product_id: '',
    quantity: 1,
    price: 0
  })
}

const removeItem = (index) => {
  form.items.splice(index, 1)
}

const submit = () => {
  form.post(route('sales-invoices.store'), {
    onSuccess: () => {
      dialog.value = false
      emit('created')
      form.reset()
    }
  })
}
</script> 