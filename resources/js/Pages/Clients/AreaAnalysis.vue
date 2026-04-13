<template>
    <Head title="Analyse de Zones" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Analyse de Zones — Où cibler les nouveaux clients ?
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Insight cards -->
                <v-row>
                    <v-col cols="12" sm="4">
                        <v-card color="success" variant="tonal" rounded="lg">
                            <v-card-text>
                                <div class="text-caption text-medium-emphasis font-weight-bold uppercase mb-1">
                                    Meilleure zone à cibler
                                </div>
                                <div class="text-h6 font-weight-bold">{{ topOpportunitySector?.name }}</div>
                                <div class="text-body-2 mt-1">
                                    Score opportunité: <strong>{{ formatAmount(topOpportunitySector?.opportunity_score) }}</strong>
                                </div>
                                <div class="text-body-2">
                                    Facture moy.: <strong>{{ formatAmount(topOpportunitySector?.avg_invoice_amount) }}</strong>
                                    · Pénétration: <strong>{{ topOpportunitySector?.penetration_rate }}%</strong>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                    <v-col cols="12" sm="4">
                        <v-card color="primary" variant="tonal" rounded="lg">
                            <v-card-text>
                                <div class="text-caption text-medium-emphasis font-weight-bold uppercase mb-1">
                                    Meilleur profit moyen
                                </div>
                                <div class="text-h6 font-weight-bold">{{ highestProfitSector?.name }}</div>
                                <div class="text-body-2 mt-1">
                                    Profit moy./facture: <strong>{{ formatAmount(highestProfitSector?.avg_profit) }}</strong>
                                </div>
                                <div class="text-body-2">
                                    Revenu total: <strong>{{ formatAmount(highestProfitSector?.total_revenue) }}</strong>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                    <v-col cols="12" sm="4">
                        <v-card color="warning" variant="tonal" rounded="lg">
                            <v-card-text>
                                <div class="text-caption text-medium-emphasis font-weight-bold uppercase mb-1">
                                    Zone à faible pénétration
                                </div>
                                <div class="text-h6 font-weight-bold">{{ lowestPenetrationSector?.name }}</div>
                                <div class="text-body-2 mt-1">
                                    Taux de pénétration: <strong>{{ lowestPenetrationSector?.penetration_rate }}%</strong>
                                </div>
                                <div class="text-body-2">
                                    {{ lowestPenetrationSector?.total_customers - lowestPenetrationSector?.customers_with_invoices }}
                                    clients non encore acheteurs
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Map -->
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="d-flex justify-space-between align-center mb-4">
                        <div>
                            <div class="text-subtitle-1 font-weight-bold">Carte des performances par client</div>
                            <div class="text-body-2 text-medium-emphasis">
                                Taille du cercle = facture moyenne · Couleur = niveau de profit
                            </div>
                        </div>
                        <!-- Legend -->
                        <div class="d-flex gap-4 flex-wrap">
                            <div v-for="tier in profitTiers" :key="tier.label" class="d-flex align-center gap-1">
                                <div
                                    class="rounded-circle"
                                    :style="{ width: '12px', height: '12px', background: tier.color, border: '1.5px solid #555' }"
                                ></div>
                                <span class="text-caption">{{ tier.label }}</span>
                            </div>
                        </div>
                    </div>
                    <div id="area-analysis-map" style="height: 550px; width: 100%;"></div>
                </div>

                <!-- Sector ranking table -->
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="text-subtitle-1 font-weight-bold mb-4">
                        Classement des secteurs par opportunité
                    </div>
                    <v-data-table
                        :headers="tableHeaders"
                        :items="sectorMetrics"
                        :items-per-page="10"
                        density="compact"
                    >
                        <template #item.name="{ item }">
                            <span class="font-weight-medium">{{ item.name }}</span>
                        </template>
                        <template #item.avg_invoice_amount="{ item }">
                            {{ formatAmount(item.avg_invoice_amount) }}
                        </template>
                        <template #item.avg_profit="{ item }">
                            {{ formatAmount(item.avg_profit) }}
                        </template>
                        <template #item.total_revenue="{ item }">
                            {{ formatAmount(item.total_revenue) }}
                        </template>
                        <template #item.penetration_rate="{ item }">
                            <v-progress-linear
                                :model-value="item.penetration_rate"
                                :color="item.penetration_rate >= 70 ? 'success' : item.penetration_rate >= 40 ? 'warning' : 'error'"
                                height="16"
                                rounded
                            >
                                <template #default>
                                    <span style="font-size:10px;font-weight:600">{{ item.penetration_rate }}%</span>
                                </template>
                            </v-progress-linear>
                        </template>
                        <template #item.opportunity_score="{ item }">
                            {{ formatAmount(item.opportunity_score) }}
                        </template>
                        <template #item.recommendation="{ item }">
                            <v-chip
                                :color="item.recommendation === 'Priorité haute' ? 'success' : item.recommendation === 'Priorité moyenne' ? 'warning' : 'default'"
                                size="small"
                                variant="tonal"
                            >
                                {{ item.recommendation }}
                            </v-chip>
                        </template>
                    </v-data-table>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { onMounted, onUnmounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    customers: {
        type: Array,
        required: true,
    },
    sectorMetrics: {
        type: Array,
        required: true,
    },
});

let leafletMap = null;

// ─── Insight card computed values ────────────────────────────────────────────

const topOpportunitySector = computed(() => props.sectorMetrics[0] ?? null);

const highestProfitSector = computed(() =>
    [...props.sectorMetrics].sort((a, b) => b.avg_profit - a.avg_profit)[0] ?? null
);

const lowestPenetrationSector = computed(() =>
    [...props.sectorMetrics]
        .filter(s => s.total_customers > 5)
        .sort((a, b) => a.penetration_rate - b.penetration_rate)[0] ?? null
);

// ─── Profit tier configuration ───────────────────────────────────────────────

const profitTiers = [
    { label: 'Excellent (> 2 000)', color: '#16a34a', min: 2000 },
    { label: 'Bon (1 000–2 000)',   color: '#2563eb', min: 1000 },
    { label: 'Moyen (< 1 000)',     color: '#d97706', min: 0    },
];

function resolveMarkerColor(avgProfit) {
    if (avgProfit >= 2000) { return '#16a34a'; }
    if (avgProfit >= 1000) { return '#2563eb'; }
    return '#d97706';
}

// ─── Formatting helpers ───────────────────────────────────────────────────────

function formatAmount(amount) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0);
}

function parseGpsCoordinates(gpsCoordinatesString) {
    const parts = gpsCoordinatesString.split(',');
    if (parts.length !== 2) { return null; }
    const lat = parseFloat(parts[0].trim());
    const lng = parseFloat(parts[1].trim());
    if (isNaN(lat) || isNaN(lng)) { return null; }
    return { lat, lng };
}

// ─── Map radius normalisation ─────────────────────────────────────────────────

function computeNormalisedRadius(avgInvoiceAmount, minAvgInvoice, maxAvgInvoice) {
    const minRadius = 4;
    const maxRadius = 22;
    if (maxAvgInvoice === minAvgInvoice) { return (minRadius + maxRadius) / 2; }
    return minRadius + ((avgInvoiceAmount - minAvgInvoice) / (maxAvgInvoice - minAvgInvoice)) * (maxRadius - minRadius);
}

// ─── Map initialisation ───────────────────────────────────────────────────────

function buildCustomerTooltipContent(customer) {
    return `
        <div style="min-width:170px;font-size:13px">
            <strong>${customer.name}</strong>
            <div style="margin:4px 0">
                <div><strong>Factures:</strong> ${customer.total_invoices}</div>
                <div><strong>Facture moy.:</strong> ${formatAmount(customer.avg_invoice_amount)}</div>
                <div><strong>Profit moy.:</strong> ${formatAmount(customer.avg_profit)}</div>
            </div>
        </div>`;
}

function buildSectorCentroidLabel(sectorMetric) {
    const badgeColor = sectorMetric.recommendation === 'Priorité haute'
        ? '#16a34a'
        : sectorMetric.recommendation === 'Priorité moyenne'
            ? '#d97706'
            : '#6b7280';

    return L.divIcon({
        html: `
            <div style="
                background:${badgeColor};color:#fff;
                border-radius:6px;padding:4px 8px;
                font-size:11px;font-weight:700;
                white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,0.3);
                text-align:center;pointer-events:none;
            ">
                ${sectorMetric.name}<br>
                <span style="font-weight:400;font-size:10px">${sectorMetric.recommendation}</span>
            </div>`,
        className: '',
        iconSize: null,
        iconAnchor: [0, 0],
    });
}

function initMap() {
    leafletMap = L.map('area-analysis-map').setView([14.7167, -17.4677], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
    }).addTo(leafletMap);

    const customersWithCoordinates = props.customers
        .map(customer => ({ customer, coordinates: parseGpsCoordinates(customer.gps_coordinates) }))
        .filter(({ coordinates }) => coordinates !== null);

    if (customersWithCoordinates.length === 0) { return; }

    const allAvgInvoices = customersWithCoordinates.map(({ customer }) => customer.avg_invoice_amount);
    const minAvgInvoice = Math.min(...allAvgInvoices);
    const maxAvgInvoice = Math.max(...allAvgInvoices);

    const markerBounds = [];

    // Plot individual customer circles
    customersWithCoordinates.forEach(({ customer, coordinates }) => {
        const radius = computeNormalisedRadius(customer.avg_invoice_amount, minAvgInvoice, maxAvgInvoice);
        const color = resolveMarkerColor(customer.avg_profit);

        L.circleMarker([coordinates.lat, coordinates.lng], {
            radius,
            fillColor: color,
            color: '#fff',
            weight: 1.5,
            fillOpacity: 0.75,
        })
            .addTo(leafletMap)
            .bindTooltip(buildCustomerTooltipContent(customer), {
                sticky: true,
                direction: 'top',
                offset: [0, -radius],
            });

        markerBounds.push([coordinates.lat, coordinates.lng]);
    });

    // Plot sector centroid recommendation badges
    const customersBySectorId = {};
    customersWithCoordinates.forEach(({ customer, coordinates }) => {
        if (!customer.sector_id) { return; }
        if (!customersBySectorId[customer.sector_id]) {
            customersBySectorId[customer.sector_id] = [];
        }
        customersBySectorId[customer.sector_id].push(coordinates);
    });

    props.sectorMetrics.forEach(sectorMetric => {
        const sectorCoordinates = customersBySectorId[sectorMetric.id];
        if (!sectorCoordinates || sectorCoordinates.length === 0) { return; }

        // Compute centroid of all customer positions in the sector
        const centroidLat = sectorCoordinates.reduce((sum, c) => sum + c.lat, 0) / sectorCoordinates.length;
        const centroidLng = sectorCoordinates.reduce((sum, c) => sum + c.lng, 0) / sectorCoordinates.length;

        L.marker([centroidLat, centroidLng], { icon: buildSectorCentroidLabel(sectorMetric) })
            .addTo(leafletMap)
            .bindPopup(`
                <div style="min-width:200px;font-size:13px">
                    <strong style="font-size:14px">${sectorMetric.name}</strong>
                    <div style="margin:6px 0">
                        <div><strong>Clients totaux:</strong> ${sectorMetric.total_customers}</div>
                        <div><strong>Clients acheteurs:</strong> ${sectorMetric.customers_with_invoices}</div>
                        <div><strong>Taux de pénétration:</strong> ${sectorMetric.penetration_rate}%</div>
                        <div><strong>Facture moy.:</strong> ${formatAmount(sectorMetric.avg_invoice_amount)}</div>
                        <div><strong>Profit moy.:</strong> ${formatAmount(sectorMetric.avg_profit)}</div>
                        <div><strong>Score opportunité:</strong> ${formatAmount(sectorMetric.opportunity_score)}</div>
                    </div>
                </div>`);
    });

    leafletMap.fitBounds(markerBounds, { padding: [40, 40] });
}

// ─── Table headers ────────────────────────────────────────────────────────────

const tableHeaders = [
    { title: 'Secteur', key: 'name', sortable: true },
    { title: 'Clients total', key: 'total_customers', sortable: true, align: 'end' },
    { title: 'Acheteurs', key: 'customers_with_invoices', sortable: true, align: 'end' },
    { title: 'Pénétration', key: 'penetration_rate', sortable: true, width: '160px' },
    { title: 'Facture moy.', key: 'avg_invoice_amount', sortable: true, align: 'end' },
    { title: 'Profit moy.', key: 'avg_profit', sortable: true, align: 'end' },
    { title: 'Revenu total', key: 'total_revenue', sortable: true, align: 'end' },
    { title: 'Score opportunité', key: 'opportunity_score', sortable: true, align: 'end' },
    { title: 'Recommandation', key: 'recommendation', sortable: false },
];

// ─── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(() => {
    initMap();
});

onUnmounted(() => {
    if (leafletMap) {
        leafletMap.remove();
        leafletMap = null;
    }
});
</script>
