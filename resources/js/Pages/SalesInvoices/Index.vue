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
                <th>Facture N°</th>
                <th>Client</th>
                <th>Total</th>
                <th>Échéance</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="invoice in filteredInvoices" :key="invoice.id">
                <td>{{ invoice.id }}</td>
                <td>{{ invoice.customer.name }}</td>
                <td>{{ formatPrice(invoice.total) }}</td>
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
                  <div class="flex gap-2">
                    <v-btn
                      icon
                      size="small"
                      @click="openItemsDialog(invoice)"
                      :title="'Voir les articles'"
                    >
                      <v-icon>mdi-eye</v-icon>
                    </v-btn>
                    <v-btn
                      icon
                      size="small"
                      @click="openPaymentsDialog(invoice)"
                      :title="'Voir les paiements'"
                    >
                      <v-icon>mdi-cash</v-icon>
                    </v-btn>
                    <v-btn
                      icon
                      size="small"
                      color="error"
                      @click="deleteInvoice(invoice)"
                      :title="'Supprimer'"
                    >
                      <v-icon>mdi-delete</v-icon>
                    </v-btn>
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
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
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
const showCreateDialog = ref(false)
const showItemsDialog = ref(false)
const showPaymentsDialog = ref(false)
const selectedInvoice = ref(null)

const filteredInvoices = computed(() => {
  if (filter.value === 'all') return props.invoices.data
  return props.invoices.data.filter(invoice => 
    filter.value === 'paid' ? invoice.paid : !invoice.paid
  )
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

const openItemsDialog = (invoice) => {
  selectedInvoice.value = invoice
  showItemsDialog.value = true
}

const openPaymentsDialog = (invoice) => {
  selectedInvoice.value = invoice
  showPaymentsDialog.value = true
}

const deleteInvoice = async (invoice) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer cette facture ?')) {
    await router.delete(route('sales-invoices.destroy', invoice.id))
    refreshData()
  }
}

const refreshData = () => {
  router.reload()
}
</script> 