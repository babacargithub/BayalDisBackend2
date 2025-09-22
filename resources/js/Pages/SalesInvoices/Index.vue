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
          <v-btn 
            color="success" 
            @click="exportFilteredInvoices"
            :disabled="filteredInvoices.length === 0"
          >
            <v-icon>mdi-download</v-icon>
            Exporter PDF ({{ filteredInvoices.length }})
          </v-btn>
          <v-btn 
            color="error" 
            :href="route('sales-invoices.unpaid-pdf')"
            target="_blank"
          >
            <v-icon>mdi-file-pdf-box</v-icon>
            Factures impayées
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
        <div class="mb-4 flex gap-4 flex-wrap">
          <!-- Payment Status Filters -->
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

          <!-- Week Filter Button -->
          <v-btn
            color="secondary"
            @click="showWeekFilterDialog = true"
            :append-icon="selectedWeeks.length > 0 ? 'mdi-filter' : 'mdi-calendar-week'"
          >
            Filtrer par semaines
            <v-chip 
              v-if="selectedWeeks.length > 0" 
              size="small" 
              color="primary" 
              class="ml-2"
            >
              {{ selectedWeeks.length }}
            </v-chip>
          </v-btn>

          <!-- Clear Week Filter -->
          <v-btn
            v-if="selectedWeeks.length > 0"
            color="error"
            variant="outlined"
            size="small"
            @click="clearWeekFilter"
          >
            Effacer filtre semaines
          </v-btn>
        </div>

        <!-- Invoices List -->
        <v-card>
          <v-table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Client</th>
                <th>Téléphone</th>
                <th>Total</th>
                <th>Total payé</th>
                <th>Reste à payer</th>
                <th>Bénéfice</th>
                <th>Benefi Recu</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="invoice in filteredInvoices" :key="invoice.id">
                <td>{{ formatDate(invoice.created_at) }}</td>
                <td>
                  <div>{{ invoice.customer.name }}</div>
                  <div class="text-caption text-grey">{{ invoice.customer.address }}</div>
                </td>
                <td>{{ invoice.customer.phone_number }}</td>
                <td>{{ formatPrice(invoice.total) }}</td>
                <td>{{ formatPrice(invoice.total - getRemainingAmount(invoice)) }}</td>
                <td>
                  <span :class="getRemainingAmount(invoice) > 0 ? 'text-error' : ''">
                    {{ formatPrice(getRemainingAmount(invoice)) }}
                  </span>
                </td>
                <td>
                  <span>
                    {{ formatPrice(invoice.total_profit) }}
                  </span>
                </td> <td>
                  <span>
                    {{ formatPrice(invoice.total_profit_paid) }}
                  </span>
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

    <!-- Week Filter Dialog -->
    <v-dialog v-model="showWeekFilterDialog" max-width="600px">
      <v-card>
        <v-card-title class="text-h5">
          <v-icon class="mr-2">mdi-calendar-week</v-icon>
          Filtrer par semaines
        </v-card-title>
        <v-card-text>
          <div class="mb-4">
            <v-btn 
              color="primary" 
              variant="outlined" 
              size="small"
              @click="selectAllWeeks"
              class="mr-2"
            >
              Tout sélectionner
            </v-btn>
            <v-btn 
              color="secondary" 
              variant="outlined" 
              size="small"
              @click="deselectAllWeeks"
            >
              Tout désélectionner
            </v-btn>
          </div>
          
          <div v-if="availableWeeks.length === 0" class="text-center py-4">
            <v-icon size="48" color="grey">mdi-calendar-remove</v-icon>
            <div class="text-grey mt-2">Aucune facture trouvée</div>
          </div>
          
          <div v-else class="max-h-400 overflow-y-auto">
            <v-checkbox
              v-for="week in availableWeeks"
              :key="week.key"
              v-model="selectedWeeks"
              :value="week.key"
              :label="week.label"
              hide-details
              density="compact"
              class="mb-1"
            />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="primary" variant="text" @click="showWeekFilterDialog = false">
            Fermer
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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

// Week filtering
const showWeekFilterDialog = ref(false)
const selectedWeeks = ref([])

// Get the start of week (Monday) for a given date
const getWeekStart = (date) => {
  const d = new Date(date)
  const day = d.getDay()
  const diff = d.getDate() - day + (day === 0 ? -6 : 1) // adjust when day is Sunday
  return new Date(d.setDate(diff))
}

// Get the end of week (Sunday) for a given date
const getWeekEnd = (date) => {
  const weekStart = getWeekStart(date)
  const weekEnd = new Date(weekStart)
  weekEnd.setDate(weekStart.getDate() + 6)
  return weekEnd
}

// Format week label in French
const formatWeekLabel = (startDate, endDate) => {
  const months = [
    'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'
  ]
  
  const startDay = startDate.getDate()
  const startMonth = months[startDate.getMonth()]
  const startYear = startDate.getFullYear()
  
  const endDay = endDate.getDate()
  const endMonth = months[endDate.getMonth()]
  const endYear = endDate.getFullYear()
  
  if (startMonth === endMonth && startYear === endYear) {
    return `Factures du lundi ${startDay} au dimanche ${endDay} ${startMonth} ${startYear}`
  } else if (startYear === endYear) {
    return `Factures du lundi ${startDay} ${startMonth} au dimanche ${endDay} ${endMonth} ${startYear}`
  } else {
    return `Factures du lundi ${startDay} ${startMonth} ${startYear} au dimanche ${endDay} ${endMonth} ${endYear}`
  }
}

// Generate week key for comparison
const getWeekKey = (date) => {
  const weekStart = getWeekStart(date)
  return weekStart.toISOString().split('T')[0]
}

// Available weeks computed property
const availableWeeks = computed(() => {
  if (!props.invoices.data || props.invoices.data.length === 0) {
    return []
  }

  // Get all invoice dates
  const dates = props.invoices.data
    .map(invoice => new Date(invoice.created_at))
    .sort((a, b) => a - b)

  if (dates.length === 0) return []

  const weeks = new Map()
  
  dates.forEach(date => {
    const weekStart = getWeekStart(date)
    const weekEnd = getWeekEnd(date)
    const weekKey = getWeekKey(date)
    
    if (!weeks.has(weekKey)) {
      weeks.set(weekKey, {
        key: weekKey,
        label: formatWeekLabel(weekStart, weekEnd),
        start: weekStart,
        end: weekEnd
      })
    }
  })

  return Array.from(weeks.values()).sort((a, b) => b.start - a.start)
})

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

  // Apply week filter
  if (selectedWeeks.value.length > 0) {
    filtered = filtered.filter(invoice => {
      const invoiceWeekKey = getWeekKey(new Date(invoice.created_at))
      return selectedWeeks.value.includes(invoiceWeekKey)
    })
  }

  return filtered
})

const totalRemainingAmount = computed(() => {
  return filteredInvoices.value.reduce((total, invoice) => {
    return total + getRemainingAmount(invoice)
  }, 0)
})

// Week filter methods
const selectAllWeeks = () => {
  selectedWeeks.value = availableWeeks.value.map(week => week.key)
}

const deselectAllWeeks = () => {
  selectedWeeks.value = []
}

const clearWeekFilter = () => {
  selectedWeeks.value = []
}

const formatPrice = (price) => {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'XOF',
    minimumFractionDigits: 0,
  }).format(price)
}

const formatDate = (date) => {
  if (date==null){
    return "";
  }
  // return new Date(date).toLocaleDateString('fr-FR')
  return new Date(date).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',

  })
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

const exportFilteredInvoices = () => {
  // Prepare filter parameters
  const params = new URLSearchParams()
  
  // Add payment status filter
  if (filter.value !== 'all') {
    params.append('filter', filter.value)
  }
  
  // Add search query
  if (searchQuery.value) {
    params.append('search', searchQuery.value)
  }
  
  // Add selected weeks
  if (selectedWeeks.value.length > 0) {
    selectedWeeks.value.forEach(week => {
      params.append('weeks[]', week)
    })
  }
  
  // Create export URL
  const exportUrl = route('sales-invoices.export-pdf') + '?' + params.toString()
  
  // Open PDF in new window
  window.open(exportUrl, '_blank')
}

const refreshData = () => {
  router.reload({ preserveScroll: true })
}
</script> 