<template>
  <v-dialog v-model="dialog" max-width="600px">
    <v-card>
      <v-progress-linear v-if="loading" indeterminate color="primary" />
      <v-card-title class="d-flex justify-space-between align-center">
        <span>Paiements</span>
        <v-btn
          color="primary"
          @click="showAddPaymentForm = true"
          v-if="!invoice.paid && !loading"
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
              <v-alert
                v-if="errors.length > 0"
                type="error"
                class="mb-4"
                variant="tonal"
                closable
              >
                <ul class="ml-4">
                  <li v-for="(error, index) in errors" :key="index">{{ error }}</li>
                </ul>
              </v-alert>

              <v-alert
                v-if="page.props.flash?.success"
                type="success"
                class="mb-4"
                variant="tonal"
                closable
              >
                {{ page.props.flash.success }}
              </v-alert>

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
                  <v-radio-group
                    v-model="form.payment_method"
                    label="Méthode de paiement"
                    :error-messages="form.errors.payment_method"
                    required
                    class="mt-0"
                  >
                    <v-radio value="Cash" label="Cash" />
                    <v-radio value="Wave" label="Wave" />
                    <v-radio value="Om" label="Om" />
                  </v-radio-group>
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
              <th>Commission</th>
              <th>Payé par</th>
              <th>Commentaire</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="payment in invoice.payments"
              :key="payment.id"
              :class="{ 'cancelled-payment-row': payment.cancelled_at }"
            >
              <td>{{ formatDate(payment.created_at) }}</td>
              <td>
                <template v-if="editingPayment?.id === payment.id">
                  <v-text-field
                    v-model.number="editForm.amount"
                    type="number"
                    min="0"
                    :max="invoice.total"
                    density="compact"
                    hide-details
                    :error-messages="editForm.errors.amount"
                  />
                </template>
                <template v-else>
                  {{ formatPrice(payment.amount) }}
                  <v-tooltip v-if="payment.cancelled_at" location="top">
                    <template #activator="{ props: tooltipProps }">
                      <v-chip
                        v-bind="tooltipProps"
                        color="error"
                        size="x-small"
                        class="ml-1"
                      >
                        Annulé
                      </v-chip>
                    </template>
                    <span>
                      Annulé le {{ formatDate(payment.cancelled_at) }}
                      <template v-if="payment.cancelled_by_name"> par {{ payment.cancelled_by_name }}</template>
                      <template v-if="payment.cancellation_reason"> — {{ payment.cancellation_reason }}</template>
                    </span>
                  </v-tooltip>
                </template>
              </td>
              <td class="text-deep-purple">{{ formatPrice(payment.commercial_commission ?? 0) }}</td>
              <td>
                <template v-if="editingPayment?.id === payment.id">
                  <v-select
                    v-model="editForm.payment_method"
                    :items="['Cash', 'Wave', 'Om']"
                    density="compact"
                    hide-details
                    :error-messages="editForm.errors.payment_method"
                  />
                </template>
                <template v-else>
                  {{ payment.payment_method }}
                </template>
              </td>
              <td>
                <template v-if="editingPayment?.id === payment.id">
                  <v-text-field
                    v-model="editForm.comment"
                    density="compact"
                    hide-details
                    :error-messages="editForm.errors.comment"
                  />
                </template>
                <template v-else>
                  {{ payment.comment }}
                </template>
              </td>
              <td>
                <div class="d-flex gap-2">
                  <template v-if="editingPayment?.id === payment.id">
                    <v-btn
                      icon
                      size="small"
                      color="success"
                      @click="updatePayment"
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
                  <template v-else-if="!payment.cancelled_at">
                    <v-btn
                      icon
                      size="small"
                      color="primary"
                      @click="startEditing(payment)"
                      :title="'Modifier'"
                    >
                      <v-icon>mdi-pencil</v-icon>
                    </v-btn>
                    <v-btn
                      icon
                      size="small"
                      color="warning"
                      @click="openCancelDialog(payment)"
                      :title="'Annuler le paiement'"
                    >
                      <v-icon>mdi-cancel</v-icon>
                    </v-btn>
                    <v-btn
                      icon
                      size="small"
                      color="error"
                      @click="deletePayment(payment)"
                      :title="'Supprimer'"
                    >
                      <v-icon>mdi-delete</v-icon>
                    </v-btn>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </v-table>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn color="primary" @click="dialog = false">Fermer</v-btn>
      </v-card-actions>

      <!-- Cancel Payment Dialog -->
      <v-dialog v-model="showCancelPaymentDialog" max-width="500px">
        <v-card>
          <v-card-title>Annuler le paiement</v-card-title>
          <v-card-text>
            <v-alert type="warning" variant="tonal" class="mb-4">
              L'annulation de ce paiement de
              <strong>{{ formatPrice(paymentBeingCancelled?.amount ?? 0) }}</strong>
              va recalculer les totaux de la facture, la commission du commercial
              et retirer le montant de la caisse concernée.
            </v-alert>
            <v-alert
              v-if="cancelForm.errors.error"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              {{ cancelForm.errors.error }}
            </v-alert>
            <v-textarea
              v-model="cancelForm.cancellation_reason"
              label="Motif de l'annulation"
              rows="2"
              required
              :error-messages="cancelForm.errors.cancellation_reason"
            />
          </v-card-text>
          <v-card-actions>
            <v-spacer />
            <v-btn @click="showCancelPaymentDialog = false">Retour</v-btn>
            <v-btn
              color="warning"
              :loading="cancelForm.processing"
              @click="confirmCancelPayment"
            >
              Confirmer l'annulation
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'

const props = defineProps({
  modelValue: Boolean,
  loading: {
    type: Boolean,
    default: false,
  },
  invoice: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['update:modelValue', 'updated'])

const dialog = ref(props.modelValue)
const showAddPaymentForm = ref(false)

const page = usePage()

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
  return props.invoice.payments
    ?.filter((payment) => !payment.cancelled_at)
    .reduce((sum, payment) => sum + payment.amount, 0) || 0
})

const form = useForm({
  amount: null,
  payment_method: null,
  comment: null
})

const editingPayment = ref(null)
const editForm = useForm({
  amount: null,
  payment_method: null,
  comment: null
})

const errors = computed(() => {
  const formErrors = Object.values(form.errors)
  const flashError = page.props.flash?.error
  return [...formErrors, flashError].filter(Boolean)
})

const formatPrice = (price) => {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'XOF',
    minimumFractionDigits: 0
  }).format(price)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const addPayment = () => {
  form.post(route('sales-invoices.payments.store', props.invoice.id), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      showAddPaymentForm.value = false
      emit('updated')
    }
  })
}

const startEditing = (payment) => {
  editingPayment.value = payment
  editForm.amount = payment.amount
  editForm.payment_method = payment.payment_method
  editForm.comment = payment.comment
}

const cancelEditing = () => {
  editingPayment.value = null
  editForm.reset()
}

const updatePayment = () => {
  editForm.put(route('sales-invoices.payments.update', [props.invoice.id, editingPayment.value.id]), {
    preserveScroll: true,
    onSuccess: (response) => {
      editingPayment.value = null
      editForm.reset()
      if (response?.props?.invoice) {
        Object.assign(props.invoice, response.props.invoice)
      }
      emit('updated')
    }
  })
}

const deletePayment = (payment) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce paiement ?')) {
    router.delete(route('sales-invoices.payments.destroy', [props.invoice.id, payment.id]), {
      preserveScroll: true,
      onSuccess: () => {
        emit('updated')
      }
    })
  }
}

const showCancelPaymentDialog = ref(false)
const paymentBeingCancelled = ref(null)
const cancelForm = useForm({
  cancellation_reason: ''
})

const openCancelDialog = (payment) => {
  paymentBeingCancelled.value = payment
  cancelForm.reset()
  cancelForm.clearErrors()
  showCancelPaymentDialog.value = true
}

const confirmCancelPayment = () => {
  cancelForm.post(
    route('sales-invoices.payments.cancel', [props.invoice.id, paymentBeingCancelled.value.id]),
    {
      preserveScroll: true,
      onSuccess: () => {
        showCancelPaymentDialog.value = false
        paymentBeingCancelled.value = null
        cancelForm.reset()
        emit('updated')
      }
    }
  )
}
</script>

<style scoped>
.cancelled-payment-row {
  opacity: 0.55;
  text-decoration: line-through;
}
</style> 