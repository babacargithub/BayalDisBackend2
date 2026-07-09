<template>
    <Head title="Factures radiées" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                        Factures radiées
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">
                        Créances définitivement perdues, exclues du calcul des dettes clients.
                    </p>
                </div>
                <Link
                    :href="route('sales-invoices.index')"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Retour aux dettes clients
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

                <!-- Summary cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <div class="rounded-2xl bg-gradient-to-br from-rose-500 to-rose-700 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="rounded-xl bg-white/20 p-2">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75 14.25 14.25M14.25 9.75 9.75 14.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <span class="text-sm font-semibold text-rose-100 uppercase tracking-wide">Total radié</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ formatPrice(totalWrittenOffAmount) }}
                        </div>
                        <div class="mt-2 text-xs text-rose-200">Sur cette page ({{ invoices.data.length }} facture{{ invoices.data.length > 1 ? 's' : '' }})</div>
                    </div>

                    <div class="rounded-2xl bg-gradient-to-br from-slate-600 to-slate-800 p-5 text-white shadow-lg">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="rounded-xl bg-white/20 p-2">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3-14.15V6a2.25 2.25 0 0 1-2.25 2.25H15M9 6.75V3m0 3.75h3M9 3h6l4.5 4.5v13.125A1.125 1.125 0 0 1 18.375 21.75H5.625A1.125 1.125 0 0 1 4.5 20.625V4.125A1.125 1.125 0 0 1 5.625 3H9Z" />
                                </svg>
                            </div>
                            <span class="text-sm font-semibold text-slate-200 uppercase tracking-wide">Factures affichées</span>
                        </div>
                        <div class="text-2xl font-bold tracking-tight leading-none">
                            {{ invoices.total ?? invoices.data.length }}
                        </div>
                        <div class="mt-2 text-xs text-slate-300">Total sur l'ensemble des pages</div>
                    </div>
                </div>

                <!-- Search -->
                <div class="mb-4">
                    <div class="relative max-w-md">
                        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Rechercher par nom de client"
                            class="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-700 shadow-sm focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                        >
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Client</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Téléphone</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Commercial</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Montant</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Facturée le</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Radiée le</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Commentaire</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="invoice in filteredInvoices"
                                :key="invoice.id"
                                class="hover:bg-gray-50 transition"
                            >
                                <td class="px-4 py-3 text-sm font-medium text-gray-800">{{ invoice.customer.name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ invoice.customer.phone_number || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ invoice.commercial?.name || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-right font-semibold text-rose-600">{{ formatPrice(invoice.total_amount) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ formatDate(invoice.created_at) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ formatDate(invoice.written_off_at) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500 max-w-xs truncate" :title="invoice.comment || ''">
                                    {{ invoice.comment || '—' }}
                                </td>
                            </tr>

                            <tr v-if="filteredInvoices.length === 0">
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">
                                    Aucune facture radiée trouvée.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="invoices.links && invoices.links.length > 3" class="mt-4 flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in invoices.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            v-html="link.label"
                            class="rounded-md px-3 py-1.5 text-sm transition"
                            :class="link.active
                                ? 'bg-rose-600 text-white font-semibold'
                                : 'bg-white text-gray-600 border border-gray-300 hover:bg-gray-50'"
                        />
                        <span
                            v-else
                            v-html="link.label"
                            class="rounded-md px-3 py-1.5 text-sm text-gray-300"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    invoices: Object,
});

const searchQuery = ref('');

const filteredInvoices = computed(() => {
    if (!searchQuery.value.trim()) {
        return props.invoices.data;
    }
    const normalizedQuery = searchQuery.value.trim().toLowerCase();
    return props.invoices.data.filter((invoice) =>
        invoice.customer.name.toLowerCase().includes(normalizedQuery)
    );
});

const totalWrittenOffAmount = computed(() =>
    props.invoices.data.reduce((sum, invoice) => sum + invoice.total_amount, 0)
);

const formatPrice = (price) =>
    new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
    }).format(price);

const formatDate = (date) => {
    if (!date) {
        return '—';
    }
    return new Date(date).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
};
</script>
