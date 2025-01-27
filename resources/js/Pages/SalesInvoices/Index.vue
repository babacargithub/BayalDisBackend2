<template>
  <AuthenticatedLayout title="Factures">
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Factures
        </h2>
        <div class="flex gap-4">
          <v-btn color="primary" @click="showCreateDialog = true">
            Nouvelle Facture
          </v-btn>
        </div>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Search and Summary -->
        <div class="mb-6">
          <div class="flex justify-between items-center mb-4">
            <v-text-field
              v-model="searchQuery"
              label="Rechercher par nom de client"
              prepend-icon="mdi-magnify"
              hide-details
              class="max-w-md"
              density="compact"
            />
            <div class="text-right">
              <div class="text-h6">Total restant à payer</div>
              <div class="text-h5" :class="totalRemainingAmount > 0 ? 'text-error' : ''">
                {{ formatPrice(totalRemainingAmount) }}
              </div>
            </div>
          </div>
        </div>

        <!-- Filter Buttons -->
        <div class="mb-4 flex gap-4">
          <v-btn-group>
            <v-btn
              :color="filter === 'all' ? 'primary' : ''"
              @click="filter = 'all'"
            >
              Tout
            </v-btn>
            <v-btn
              :color="filter === 'paid' ? 'primary' : ''"
              @click="filter = 'paid'"
            >
              Payées
            </v-btn>
            <v-btn
              :color="filter === 'unpaid' ? 'primary' : ''"
              @click="filter = 'unpaid'"
            >
              Non Payées
            </v-btn>
          </v-btn-group>
        </div>

        <!-- Invoices List -->
        <v-card>
          <v-table>
            <thead>
              <tr>
                <th>Client</th>
                <th>Articles</th>
                <th>Total</th>
                <th>Payé</th>
                <th>Reste à payer</th>
                <th>Échéance</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="invoice in filteredInvoices" :key="invoice.id">
                <td>{{ invoice.customer.name }}</td>
                <td>{{ invoice.items?.length || 0 }} article(s)</td>
                <td>{{ formatPrice(invoice.total) }}</td>
                <td>{{ formatPrice(invoice.total - getRemainingAmount(invoice)) }}</td>
                <td>
                  <span :class="getRemainingAmount(invoice) > 0 ? 'text-error' : ''">
                    {{ formatPrice(getRemainingAmount(invoice)) }}
                  </span>
                </td>
                <td>
                  {{ invoice.should_be_paid_at ? formatDate(invoice.should_be_paid_at) : 'N/A' }}
                </td>
                <td>
                  <v-chip
                    :color="invoice.paid ? 'success' : 'warning'"
                    :text="invoice.paid ? 'Payée' : 'Non Payée'"
                  />
                </td>
                <td>
                  <div class="flex gap-1">
                    <v-btn
                      icon="mdi-eye"
                      size="small"
                      variant="text"
                      density="compact"
                      @click="openItemsDialog(invoice)"
                      :title="'Voir les articles'"
                    />
                    <v-btn
                      icon="mdi-cash"
                      size="small"
                      variant="text"
                      density="compact"
                      @click="openPaymentsDialog(invoice)"
                      :title="'Voir les paiements'"
                    />
                    <v-btn
                      icon="mdi-file-pdf-box"
                      size="small"
                      variant="text"
                      density="compact"
                      color="primary"
                      :href="route('sales-invoices.pdf', invoice.id)"
                      target="_blank"
                      :title="'Télécharger PDF'"
                    />
                    <v-btn
                      icon="mdi-delete"
                      size="small"
                      variant="text"
                      density="compact"
                      color="error"
                      @click="openDeleteDialog(invoice)"
                      :title="'Supprimer'"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card>
      </div>
    </div>

    <!-- Create Invoice Dialog -->
    <CreateInvoiceDialog
      v-model="showCreateDialog"
      :customers="customers"
      :products="products"
      @created="refreshData"
    />

    <!-- View Items Dialog -->
    <ItemsDialog
      v-if="selectedInvoice"
      v-model="showItemsDialog"
      :invoice="selectedInvoice"
      :products="products"
      @updated="refreshData"
    />

    <!-- Payments Dialog -->
    <PaymentsDialog
      v-if="selectedInvoice"
      v-model="showPaymentsDialog"
      :invoice="selectedInvoice"
      @updated="refreshData"
    />

    <!-- Delete Confirmation Dialog -->
    <v-dialog v-model="showDeleteDialog" max-width="500px">
      <v-card>
        <v-card-title class="text-h5">Confirmation de suppression</v-card-title>
        <v-card-text>
          Êtes-vous sûr de vouloir supprimer cette facture ? Cette action est irréversible.
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="primary" variant="text" @click="showDeleteDialog = false">Annuler</v-btn>
          <v-btn 
            color="error" 
            variant="text" 
            @click="confirmDelete"
          >
            Supprimer
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Error Dialog -->
    <v-dialog v-model="showErrorDialog" max-width="500px">
      <v-card>
        <v-card-title class="text-h5 text-error">
          Erreur
        </v-card-title>
        <v-card-text class="pt-4">
          {{ errorMessage }}
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="primary" variant="text" @click="showErrorDialog = false">
            Fermer
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import CreateInvoiceDialog from './Partials/CreateInvoiceDialog.vue'
import ItemsDialog from './Partials/ItemsDialog.vue'
import PaymentsDialog from './Partials/PaymentsDialog.vue'

const props = defineProps({
  invoices: Object,
  customers: Array,
  products: Array,
})

const filter = ref('all')
const searchQuery = ref('')
const showCreateDialog = ref(false)
const showItemsDialog = ref(false)
const showPaymentsDialog = ref(false)
const selectedInvoice = ref(null)
const showDeleteDialog = ref(false)
const invoiceToDelete = ref(null)
const showErrorDialog = ref(false)
const errorMessage = ref('')

const filteredInvoices = computed(() => {
  let filtered = props.invoices.data

  // Apply payment status filter
  if (filter.value !== 'all') {
    filtered = filtered.filter(invoice => 
      filter.value === 'paid' ? invoice.paid : !invoice.paid
    )
  }

  // Apply search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(invoice => 
      invoice.customer.name.toLowerCase().includes(query)
    )
  }

  return filtered
})

const totalRemainingAmount = computed(() => {
  return filteredInvoices.value.reduce((total, invoice) => {
    return total + getRemainingAmount(invoice)
  }, 0)
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

const getRemainingAmount = (invoice) => {
  const totalPaid = invoice.payments?.reduce((sum, payment) => sum + payment.amount, 0) || 0
  return invoice.total - totalPaid
}

const openItemsDialog = (invoice) => {
  selectedInvoice.value = invoice
  showItemsDialog.value = true
}

const openPaymentsDialog = (invoice) => {
  selectedInvoice.value = invoice
  showPaymentsDialog.value = true
}

const openDeleteDialog = (invoice) => {
  if (invoice.payments && invoice.payments.length > 0) {
    errorMessage.value = 'Impossible de supprimer une facture avec des paiements'
    showErrorDialog.value = true
    return
  }
  invoiceToDelete.value = invoice
  showDeleteDialog.value = true
}

const confirmDelete = () => {
  router.delete(route('sales-invoices.destroy', invoiceToDelete.value.id), {
    onSuccess: () => {
      showDeleteDialog.value = false
      invoiceToDelete.value = null
      refreshData()
    }
  })
}

const refreshData = () => {
  router.reload({ preserveScroll: true })
}
</script> 