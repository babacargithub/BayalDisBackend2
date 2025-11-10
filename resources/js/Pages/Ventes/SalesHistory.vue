<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
  history: {
    type: Object,
    default: () => {
      return {
        items: [],
        averages:{
          sales_average: 0,
          profits_average: 0,
        }, totals:{
          sales: 0,
          profits: 0,
        }
      }
    }
  }
});

// Format currency
const formatCurrency = (value) => {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'XOF',
    minimumFractionDigits: 0,
  }).format(value || 0);
};
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('fr-FR', {
    weekday: 'short',
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  }).format(date);
};
const tableHeaders = [
  {
    title: 'Date',
    key: 'date',
    align: 'start',
    sortable: true
  },
  {
    title: 'Ventes Totales',
    key: 'total_sales',
    align: 'end',
    sortable: true
  },
  {
    title: 'Bénéfice Total',
    key: 'total_profits',
    align: 'end',
    sortable: true
  },
];


const pageTitle = "Historique des ventes";
</script>

<template>
  <Head :title="pageTitle" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          {{ pageTitle }}
        </h2>
      </div>
    </template>

    <div class="py-8">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
          <!-- Sales Average Card -->
          <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 transform transition hover:scale-105">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-blue-100 text-sm font-medium mb-1">
                  Moyenne vente quotidienne
                </p>
                <p class="text-3xl font-bold">
                  {{ formatCurrency(history.averages?.sales_average) }}
                </p>
              </div>
              <div class="bg-white bg-opacity-20 rounded-full p-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
              </div>
            </div>
          </div>

          <!-- Profit Average Card -->
          <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6  transform transition hover:scale-105">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-green-100 text-sm font-medium mb-1">
                  Bénéfice quotidien moyen
                </p>
                <p class="text-3xl font-bold">
                  {{ formatCurrency(history.averages?.profits_average) }}
                </p>
              </div>
              <div class="bg-white bg-opacity-20 rounded-full p-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
          </div>
          <!-- Sales Average Card -->
          <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 transform transition hover:scale-105">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-blue-100 text-sm font-medium mb-1">
                 Total Ventes
                </p>
                <p class="text-3xl font-bold">
                  {{ formatCurrency(history.totals?.sales) }}
                </p>
              </div>
              <div class="bg-white bg-opacity-20 rounded-full p-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
              </div>
            </div>
          </div>

          <!-- Profit Average Card -->
          <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6  transform transition hover:scale-105">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-green-100 text-sm font-medium mb-1">
                  Total Bénéfice
                </p>
                <p class="text-3xl font-bold">
                  {{ formatCurrency(history.totals?.profits) }}
                </p>
              </div>
              <div class="bg-white bg-opacity-20 rounded-full p-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <!-- Data Table Card -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
              <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
              Détails des ventes
            </h3>
          </div>

          <v-data-table
              :items="history.items"
              :headers="tableHeaders"
              :items-per-page="history?.items.length"
              class="elevation-0"
          >
            <!-- Format currency columns -->
            <template v-slot:item.total_sales="{ item }">
        <span class="font-semibold text-blue-600">
            {{ formatCurrency(item.total_sales) }}
        </span>
            </template>

            <template v-slot:item.total_profits="{ item }">
        <span class="font-semibold text-green-600">
            {{ formatCurrency(item.total_profits) }}
        </span>
            </template>

            <!-- Format date column (optional) -->
            <template v-slot:item.date="{ item }">
        <span class="text-gray-700">
            {{ formatDate(item.date) }}
        </span>
            </template>

            <template v-slot:no-data>
              <div class="text-center py-8">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                </svg>
                <p class="text-gray-500 text-lg">Aucune donnée disponible</p>
              </div>
            </template>
          </v-data-table>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
/* Add smooth transitions */
.transform {
  transition: transform 0.2s ease-in-out;
}

/* Custom data table styling */
:deep(.v-data-table) {
  font-family: inherit;
}

:deep(.v-data-table-header) {
  background-color: #f9fafb;
}

:deep(.v-data-table__td) {
  padding: 16px !important;
}

:deep(.v-data-table__th) {
  font-weight: 600 !important;
  color: #374151 !important;
}
</style>