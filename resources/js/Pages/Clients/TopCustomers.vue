<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    topCustomers: {
        type: Array,
        required: true,
    },
    sort: {
        type: String,
        default: 'volume',
    },
});

const sortOptions = [
    { label: 'Par volume', value: 'volume' },
    { label: 'Par fréquence', value: 'frequency' },
];

const changeSort = (sortValue) => {
    router.get(route('clients.top-customers'), { sort: sortValue }, {
        preserveState: true,
        preserveScroll: true,
    });
};

function formatPrice(amount) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0);
}

const tableHeaders = [
    { title: '#', key: 'rank', sortable: false, width: '60px' },
    { title: 'Client', key: 'name', sortable: false },
    { title: 'Téléphone', key: 'phone_number', sortable: false },
    { title: 'Factures', key: 'invoices_count', sortable: true, align: 'end' },
    { title: 'Volume', key: 'volume', sortable: true, align: 'end' },
    { title: 'Paiements', key: 'total_payment', sortable: true, align: 'end' },
    { title: 'Profit réalisé', key: 'total_realized_profit', sortable: true, align: 'end' },
];
</script>

<template>
    <Head title="Top Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Top 50 Clients</h2>

                <div class="d-flex align-center gap-3">
                    <v-btn-toggle
                        :model-value="sort"
                        mandatory
                        color="primary"
                        variant="outlined"
                        density="comfortable"
                        @update:model-value="changeSort"
                    >
                        <v-btn
                            v-for="option in sortOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </v-btn>
                    </v-btn-toggle>

                    <Link
                        :href="route('clients.top-customers.export-pdf', { sort })"
                        class="v-btn v-btn--variant-elevated v-theme--light v-btn--density-default v-btn--size-default bg-error text-white"
                    >
                        <v-icon icon="mdi-file-pdf-box" size="small" class="mr-2" />
                        Exporter PDF
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <v-card>
                    <v-data-table
                        :headers="tableHeaders"
                        :items="topCustomers"
                        :items-per-page="-1"
                        hide-default-footer
                    >
                        <!-- Rank column -->
                        <template v-slot:item.rank="{ index }">
                            <div class="d-flex align-center justify-center">
                                <v-icon
                                    v-if="index === 0"
                                    color="amber-darken-2"
                                    size="20"
                                >mdi-trophy</v-icon>
                                <v-icon
                                    v-else-if="index === 1"
                                    color="blue-grey-lighten-1"
                                    size="20"
                                >mdi-medal</v-icon>
                                <v-icon
                                    v-else-if="index === 2"
                                    color="deep-orange-lighten-1"
                                    size="20"
                                >mdi-medal</v-icon>
                                <span v-else class="text-medium-emphasis text-body-2">{{ index + 1 }}</span>
                            </div>
                        </template>

                        <!-- Name + address -->
                        <template v-slot:item.name="{ item }">
                            <div class="py-2">
                                <div class="font-weight-bold">{{ item.name }}</div>
                                <div v-if="item.address" class="text-caption text-medium-emphasis">
                                    {{ item.address }}
                                </div>
                            </div>
                        </template>

                        <!-- Invoices count -->
                        <template v-slot:item.invoices_count="{ item }">
                            <v-chip
                                size="small"
                                :color="sort === 'frequency' ? 'primary' : undefined"
                                :variant="sort === 'frequency' ? 'tonal' : 'text'"
                            >
                                {{ item.invoices_count }}
                            </v-chip>
                        </template>

                        <!-- Volume -->
                        <template v-slot:item.volume="{ item }">
                            <span
                                class="font-weight-medium"
                                :class="sort === 'volume' ? 'text-primary' : ''"
                            >
                                {{ formatPrice(item.volume) }}
                            </span>
                        </template>

                        <!-- Total payment -->
                        <template v-slot:item.total_payment="{ item }">
                            {{ formatPrice(item.total_payment) }}
                        </template>

                        <!-- Total realized profit -->
                        <template v-slot:item.total_realized_profit="{ item }">
                            <span class="text-success font-weight-medium">
                                {{ formatPrice(item.total_realized_profit) }}
                            </span>
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
