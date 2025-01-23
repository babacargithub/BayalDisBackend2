<template>
  <v-dialog v-model="dialog" max-width="600px">
    <v-card>
      <v-card-title class="d-flex justify-space-between align-center">
        <span>Paiements</span>
        <v-btn
          color="primary"
          @click="showAddPaymentForm = true"
          v-if="!invoice.paid"
        >
          Ajouter un Paiement
        </v-btn>
      </v-card-title>

      <v-card-text>
        <!-- Payment Summary -->
        <v-card class="mb-4 pa-4" variant="outlined">
          <div class="d-flex justify-space-between mb-2">
            <span>Montant Total:</span>
            <span class="font-weight-bold">{{ formatPrice(invoice.total) }}</span>
          </div>
          <div class="d-flex justify-space-between mb-2">
            <span>Montant Payé:</span>
            <span class="font-weight-bold">{{ formatPrice(totalPaid) }}</span>
          </div>
          <div class="d-flex justify-space-between">
            <span>Reste à Payer:</span>
            <span class="font-weight-bold">{{ formatPrice(invoice.total - totalPaid) }}</span>
          </div>
        </v-card>

        <!-- Add Payment Form -->
        <v-expand-transition>
          <div v-if="showAddPaymentForm">
            <v-form @submit.prevent="addPayment" class="mb-6">
              <v-row>
                <v-col cols="12" md="6">
                  <v-text-field
                    v-model.number="form.amount"
                    type="number"
                    label="Montant"
                    min="0"
                    :max="invoice.total - totalPaid"
                    :error-messages="form.errors.amount"
                    required
                  />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field
                    v-model="form.payment_date"
                    type="date"
                    label="Date de Paiement"
                    :error-messages="form.errors.payment_date"
                    required
                  />
                </v-col>
                <v-col cols="12">
                  <v-text-field
                    v-model="form.comment"
                    label="Commentaire"
                    :error-messages="form.errors.comment"
                  />
                </v-col>
                <v-col cols="12" class="d-flex justify-end">
                  <v-btn
                    color="primary"
                    type="submit"
                    :loading="form.processing"
                  >
                    Ajouter le Paiement
                  </v-btn>
                </v-col>
              </v-row>
            </v-form>
          </div>
        </v-expand-transition>

        <!-- Payments List -->
        <v-table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Montant</th>
              <th>Commentaire</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in invoice.payments" :key="payment.id">
              <td>{{ formatDate(payment.payment_date) }}</td>
              <td>{{ formatPrice(payment.amount) }}</td>
              <td>{{ payment.comment }}</td>
              <td>
                <v-btn
                  icon
                  color="error"
                  size="small"
                  @click="deletePayment(payment)"
                  :title="'Supprimer'"
                >
                  <v-icon>mdi-delete</v-icon>
                </v-btn>
              </td>
            </tr>
          </tbody>
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
import { ref, watch, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  modelValue: Boolean,
  invoice: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['update:modelValue', 'updated'])

const dialog = ref(props.modelValue)
const showAddPaymentForm = ref(false)

watch(() => props.modelValue, (val) => {
  dialog.value = val
})

watch(dialog, (val) => {
  emit('update:modelValue', val)
  if (!val) {
    showAddPaymentForm.value = false
  }
})

const totalPaid = computed(() => {
  return props.invoice.payments?.reduce((sum, payment) => sum + payment.amount, 0) || 0
})

const form = useForm({
  amount: 0,
  payment_date: new Date().toISOString().split('T')[0],
  comment: ''
})

const formatPrice = (price) => {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'XOF',
    minimumFractionDigits: 0,
  }).format(price)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const addPayment = () => {
  form.post(route('sales-invoices.payments.store', props.invoice.id), {
    onSuccess: () => {
      showAddPaymentForm.value = false
      emit('updated')
      form.reset()
    }
  })
}

const deletePayment = (payment) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce paiement?')) {
    router.delete(route('sales-invoices.payments.destroy', [props.invoice.id, payment.id]), {
      onSuccess: () => {
        emit('updated')
      }
    })
  }
}
</script> 