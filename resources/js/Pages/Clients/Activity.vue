<template>
    <Head title="Activités Clients" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Activités Clients
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

                <!-- Query section -->
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="text-subtitle-1 font-weight-bold mb-4">Filtrer les clients</div>
                    <v-row align="start">
                      <v-col cols="12" sm="3">
                        <v-select
                            v-model="filterForm.filter_type"
                            :items="filterTypeOptions"
                            item-title="label"
                            item-value="value"
                            label="Type de filtre"
                            variant="outlined"
                            density="compact"
                            hide-details
                        />
                      </v-col>
                        <v-col cols="12" sm="3">
                            <v-text-field
                                v-model="filterForm.start_date"
                                label="Date de début"
                                type="date"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="3">
                            <v-text-field
                                v-model="filterForm.end_date"
                                label="Date de fin"
                                type="date"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>

                        <!-- Sector selector (visible only when filter_type = by_sector) -->
                        <v-col v-if="filterForm.filter_type === 'by_sector'" cols="12" sm="3">
                            <v-select
                                v-model="filterForm.sector_id"
                                :items="sectors"
                                item-title="name"
                                item-value="id"
                                label="Secteur"
                                variant="outlined"
                                density="compact"
                                hide-details
                                clearable
                            />
                        </v-col>

                        <!-- Minimum amount input (visible only when filter_type = above_amount) -->
                        <v-col v-if="filterForm.filter_type === 'above_amount'" cols="12" sm="3">
                            <v-text-field
                                v-model.number="filterForm.minimum_amount"
                                label="Montant minimum (XOF)"
                                type="number"
                                min="0"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>

                        <!-- Minimum average amount input (visible only when filter_type = above_average_amount) -->
                        <v-col v-if="filterForm.filter_type === 'above_average_amount'" cols="12" sm="3">
                            <v-text-field
                                v-model.number="filterForm.minimum_average_amount"
                                label="Moyenne minimale (XOF)"
                                type="number"
                                min="0"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>

                        <!-- Inactive days input (visible only when filter_type = churning) -->
                        <v-col v-if="filterForm.filter_type === 'churning'" cols="12" sm="3">
                            <v-text-field
                                v-model.number="filterForm.inactive_days"
                                label="Inactif depuis (jours)"
                                type="number"
                                min="1"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>

                        <v-col cols="12" sm="3" class="d-flex align-start">
                            <v-btn
                                color="primary"
                                :loading="filterLoading"
                                @click="applyFilters"
                            >
                                <v-icon start>mdi-magnify</v-icon>
                                Rechercher
                            </v-btn>
                        </v-col>
                    </v-row>

                    <div class="mt-3 text-body-2 text-medium-emphasis">
                        {{ customers.length }} client(s) trouvé(s) entre
                        {{ formatDate(filters.start_date) }} et {{ formatDate(filters.end_date) }}
                        <span v-if="customers.length > 0" class="ml-2 text-caption text-grey">
                            — Cliquez sur un marqueur pour le sélectionner
                        </span>
                    </div>
                </div>

                <!-- Map section -->
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div v-if="customers.length === 0" class="text-center text-medium-emphasis py-16">
                        <v-icon size="64" color="grey-lighten-1">mdi-map-marker-off</v-icon>
                        <div class="mt-2">Aucun client avec des coordonnées GPS trouvé pour cette période.</div>
                    </div>
                    <div v-else id="activity-map-container" style="position:relative;">
                        <div id="activity-map" style="height: 600px; width: 100%;"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Floating selection bar -->
        <Teleport to="body">
            <div
                v-if="selectedCustomerIds.length > 0"
                class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl"
                style="background: #1a237e; color: white; min-width: 340px;"
            >
                <v-icon icon="mdi-account-check" color="white" />
                <span class="font-semibold">
                    {{ selectedCustomerIds.length }} client(s) sélectionné(s)
                </span>
                <v-spacer />
                <v-btn
                    variant="tonal"
                    color="white"
                    size="small"
                    @click="clearSelection"
                >
                    Effacer
                </v-btn>
                <v-btn
                    variant="flat"
                    color="amber"
                    size="small"
                    prepend-icon="mdi-map-marker-check"
                    @click="addToBeatDialogOpen = true"
                >
                    Ajouter à un beat
                </v-btn>
            </div>
        </Teleport>

        <!-- Add to beat dialog -->
        <v-dialog v-model="addToBeatDialogOpen" max-width="480px">
            <v-card>
                <v-card-title class="d-flex align-center pa-4 border-b">
                    <v-icon icon="mdi-map-marker-check" class="mr-2" color="primary" />
                    Ajouter à un beat
                </v-card-title>
                <v-card-text class="pa-4">
                    <p class="text-body-2 text-grey mb-4">
                        {{ selectedCustomerIds.length }} client(s) sélectionné(s) seront ajoutés au beat choisi.
                        Les clients déjà présents dans le beat seront ignorés.
                    </p>
                    <v-select
                        v-model="selectedBeatIdForAddition"
                        :items="beatsForSelect"
                        item-title="label"
                        item-value="id"
                        label="Choisir un beat"
                        variant="outlined"
                        density="comfortable"
                        hide-details
                        clearable
                    />
                </v-card-text>
                <v-card-actions class="pa-4 border-t">
                    <v-spacer />
                    <v-btn variant="text" @click="addToBeatDialogOpen = false">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        :disabled="!selectedBeatIdForAddition"
                        :loading="addToBeatLoading"
                        @click="submitAddTobeat"
                    >
                        Confirmer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch, nextTick, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    customers: {
        type: Array,
        required: true,
    },
    sectors: {
        type: Array,
        required: true,
    },
    beats: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const filterTypeOptions = [
    { label: 'Clients avec facture', value: 'with_invoice' },
    { label: 'Clients d\'un secteur', value: 'by_sector' },
    { label: 'Clients avec une facture de plus de...', value: 'above_amount' },
    { label: 'Clients avec meilleures moyennes de facture', value: 'above_average_amount' },
    { label: 'Clients en churn (inactifs)', value: 'churning' },
];

const filterForm = ref({
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    filter_type: props.filters.filter_type ?? 'with_invoice',
    sector_id: props.filters.sector_id ?? null,
    minimum_amount: props.filters.minimum_amount ?? null,
    minimum_average_amount: props.filters.minimum_average_amount ?? null,
    inactive_days: props.filters.inactive_days ?? 30,
});

const filterLoading = ref(false);

// Selection state
const selectedCustomerIds = ref([]);
const addToBeatDialogOpen = ref(false);
const selectedBeatIdForAddition = ref(null);
const addToBeatLoading = ref(false);

// Non-reactive map of customerId -> Leaflet marker (for icon refresh)
const markersByCustomerId = new Map();

let leafletMap = null;

const beatsForSelect = computed(() =>
    props.beats.map(beat => ({
        id: beat.id,
        label: beat.name + (beat.day_of_week_label ? ` — ${beat.day_of_week_label}` : '') + (beat.commercial_name ? ` (${beat.commercial_name})` : ''),
    }))
);

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('fr-FR');
}

function formatAmount(amount) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0);
}

function applyFilters() {
    filterLoading.value = true;

    const params = {
        start_date: filterForm.value.start_date,
        end_date: filterForm.value.end_date,
        filter_type: filterForm.value.filter_type,
    };

    if (filterForm.value.filter_type === 'by_sector' && filterForm.value.sector_id) {
        params.sector_id = filterForm.value.sector_id;
    }

    if (filterForm.value.filter_type === 'above_amount' && filterForm.value.minimum_amount) {
        params.minimum_amount = filterForm.value.minimum_amount;
    }

    if (filterForm.value.filter_type === 'above_average_amount' && filterForm.value.minimum_average_amount) {
        params.minimum_average_amount = filterForm.value.minimum_average_amount;
    }

    if (filterForm.value.filter_type === 'churning' && filterForm.value.inactive_days) {
        params.inactive_days = filterForm.value.inactive_days;
    }

    router.get(route('clients.activity-map'), params, {
        preserveScroll: true,
        onFinish: () => {
            filterLoading.value = false;
        },
    });
}

function clearSelection() {
    const previouslySelected = [...selectedCustomerIds.value];
    selectedCustomerIds.value = [];
    previouslySelected.forEach(customerId => {
        const customer = props.customers.find(c => c.id === customerId);
        if (customer) {
            refreshMarkerIcon(customerId, customer, false);
        }
    });
}

function toggleCustomerSelection(customerId, customer) {
    const indexInSelection = selectedCustomerIds.value.indexOf(customerId);
    if (indexInSelection === -1) {
        selectedCustomerIds.value = [...selectedCustomerIds.value, customerId];
        refreshMarkerIcon(customerId, customer, true);
    } else {
        selectedCustomerIds.value = selectedCustomerIds.value.filter(id => id !== customerId);
        refreshMarkerIcon(customerId, customer, false);
    }
}

function submitAddTobeat() {
    if (!selectedBeatIdForAddition.value) {
        return;
    }

    addToBeatLoading.value = true;

    router.post(
        route('beats.add-customers', selectedBeatIdForAddition.value),
        { customer_ids: selectedCustomerIds.value },
        {
            onSuccess: () => {
                addToBeatDialogOpen.value = false;
                selectedBeatIdForAddition.value = null;
                clearSelection();
            },
            onFinish: () => {
                addToBeatLoading.value = false;
            },
        }
    );
}

function parseGpsCoordinates(gpsCoordinatesString) {
    const parts = gpsCoordinatesString.split(',');
    if (parts.length !== 2) {
        return null;
    }
    const lat = parseFloat(parts[0].trim());
    const lng = parseFloat(parts[1].trim());
    if (isNaN(lat) || isNaN(lng)) {
        return null;
    }
    return { lat, lng };
}

function buildTooltipContent(customer) {
    const isChurningCustomer = customer.last_invoice_date !== undefined;

    return `
        <div style="min-width:160px;font-size:13px">
            <strong style="font-size:14px">${customer.name}</strong>
            <div style="margin:4px 0">
                <div><strong>Tél:</strong> ${customer.phone_number || '—'}</div>
                <div><strong>Adresse:</strong> ${customer.address || 'Non spécifiée'}</div>
                ${isChurningCustomer
                    ? `<div><strong>Factures totales:</strong> ${customer.total_invoices_count}</div>
                       <div style="color:#dc2626"><strong>Dernière facture:</strong> ${new Date(customer.last_invoice_date).toLocaleDateString('fr-FR')}</div>`
                    : `<div><strong>Factures:</strong> ${customer.invoices_count}</div>
                       <div><strong>Total facturé:</strong> ${formatAmount(customer.total_invoice_amount)}</div>`
                }
                <div style="margin-top:6px;font-size:11px;color:#555;font-style:italic">Cliquez pour sélectionner</div>
            </div>
        </div>`;
}

function buildMarkerIcon(customer, isSelected) {
    const isChurningCustomer = customer.last_invoice_date !== undefined;
    const dotColor = isSelected ? '#16a34a' : (isChurningCustomer ? '#dc2626' : '#1d4ed8');
    const labelColor = isSelected ? '#15803d' : (isChurningCustomer ? '#dc2626' : '#111');
    const ringStyle = isSelected
        ? 'outline: 2px solid #16a34a; outline-offset: 2px;'
        : '';

    return L.divIcon({
        html: `
            <div style="display:flex;flex-direction:column;align-items:center;pointer-events:none">
                <div style="
                    width:${isSelected ? '13px' : '10px'};height:${isSelected ? '13px' : '10px'};border-radius:50%;
                    background:${dotColor};border:2px solid #fff;
                    box-shadow:0 1px 4px rgba(0,0,0,0.4);
                    flex-shrink:0;
                    ${ringStyle}
                ">${isSelected ? '<div style="width:5px;height:5px;border-radius:50%;background:#fff;margin:auto;margin-top:2px"></div>' : ''}</div>
                <div style="
                    margin-top:2px;
                    font-size:11px;font-weight:${isSelected ? '700' : '600'};
                    color:${labelColor};white-space:normal;
                    max-width:90px;text-align:center;line-height:1.3;
                    text-shadow:1px 1px 0 #fff,-1px -1px 0 #fff,1px -1px 0 #fff,-1px 1px 0 #fff,0 1px 0 #fff,0 -1px 0 #fff,1px 0 0 #fff,-1px 0 0 #fff;
                ">${customer.name}</div>
            </div>`,
        className: '',
        iconSize: null,
        iconAnchor: [5, 5],
        tooltipAnchor: [5, 5],
    });
}

function refreshMarkerIcon(customerId, customer, isSelected) {
    const marker = markersByCustomerId.get(customerId);
    if (marker) {
        marker.setIcon(buildMarkerIcon(customer, isSelected));
    }
}

function addFullscreenControl() {
    const FullscreenControl = L.Control.extend({
        options: { position: 'topleft' },

        onAdd() {
            const button = L.DomUtil.create('button', 'leaflet-bar leaflet-control leaflet-fullscreen-btn');
            button.title = 'Plein écran';
            button.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/>
                <line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
            </svg>`;

            L.DomEvent.on(button, 'click', L.DomEvent.stopPropagation);
            L.DomEvent.on(button, 'click', L.DomEvent.preventDefault);
            L.DomEvent.on(button, 'click', toggleFullscreen);

            document.addEventListener('fullscreenchange', () => {
                const isFullscreen = !!document.fullscreenElement;
                button.innerHTML = isFullscreen
                    ? `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/>
                        <line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/>
                       </svg>`
                    : `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/>
                        <line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
                       </svg>`;

                // Let Leaflet recalculate its size after the transition
                setTimeout(() => leafletMap?.invalidateSize(), 200);
            });

            return button;
        },
    });

    new FullscreenControl().addTo(leafletMap);
}

function toggleFullscreen() {
    const container = document.getElementById('activity-map-container');
    if (!document.fullscreenElement) {
        container.requestFullscreen();
    } else {
        document.exitFullscreen();
    }
}

function initOrRefreshMap() {
    if (props.customers.length === 0) {
        return;
    }

    markersByCustomerId.clear();

    if (!leafletMap) {
        leafletMap = L.map('activity-map').setView([14.7167, -17.4677], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(leafletMap);

        addFullscreenControl();
    } else {
        // Clear existing layers except the tile layer
        leafletMap.eachLayer(layer => {
            if (!(layer instanceof L.TileLayer)) {
                leafletMap.removeLayer(layer);
            }
        });
    }

    const markerBounds = [];

    props.customers.forEach(customer => {
        if (!customer.gps_coordinates) {
            return;
        }

        const coordinates = parseGpsCoordinates(customer.gps_coordinates);
        if (!coordinates) {
            return;
        }

        const isSelected = selectedCustomerIds.value.includes(customer.id);
        const icon = buildMarkerIcon(customer, isSelected);

        const marker = L.marker([coordinates.lat, coordinates.lng], { icon })
            .addTo(leafletMap)
            .bindTooltip(buildTooltipContent(customer), {
                sticky: true,
                direction: 'top',
                offset: [0, -10],
            });

        marker.on('click', () => {
            toggleCustomerSelection(customer.id, customer);
        });

        markersByCustomerId.set(customer.id, marker);
        markerBounds.push([coordinates.lat, coordinates.lng]);
    });

    if (markerBounds.length > 0) {
        leafletMap.fitBounds(markerBounds, { padding: [50, 50] });
    }
}

onMounted(async () => {
    if (props.customers.length > 0) {
        await nextTick();
        initOrRefreshMap();
    }
});

// Re-render map markers when Inertia reloads the page with new data
watch(
    () => props.customers,
    async () => {
        selectedCustomerIds.value = [];
        await nextTick();
        initOrRefreshMap();
    }
);

onUnmounted(() => {
    if (leafletMap) {
        leafletMap.remove();
        leafletMap = null;
    }
});
</script>

<style>
#activity-map-container:fullscreen {
    padding: 0;
    background: #fff;
}

#activity-map-container:fullscreen #activity-map {
    height: 100vh !important;
}

.leaflet-fullscreen-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px !important;
    height: 30px !important;
    cursor: pointer;
    background: #fff;
    border: none;
    color: #333;
    padding: 0;
}

.leaflet-fullscreen-btn:hover {
    background: #f4f4f4;
    color: #000;
}
</style>
