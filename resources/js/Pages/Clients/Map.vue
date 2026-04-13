<template>
    <Head title="Carte des Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Carte des Clients
                </h2>
                <Link
                    :href="route('clients.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700"
                >
                    <v-icon
                        icon="mdi-format-list-bulleted"
                        size="small"
                        class="mr-2"
                    />
                    Liste des Clients
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <!-- Legend -->
                        <div class="mb-4">
                            <div class="text-subtitle-1 font-weight-bold mb-2">Légende:</div>
                            <div class="d-flex flex-column gap-4">
                                <!-- Sector colors -->
                                <div class="d-flex gap-4 flex-wrap">
                                    <div class="text-subtitle-2">Secteurs:</div>
                                    <div v-for="(sector, id) in sectorsWithColors" :key="id" class="d-flex align-center">
                                        <div
                                            class="mr-2 rounded-circle"
                                            :style="{ width: '16px', height: '16px', backgroundColor: sector.hexColor, border: '2px solid #555' }"
                                        ></div>
                                        <span>{{ sector.name }}</span>
                                    </div>
                                    <div class="d-flex align-center">
                                        <div class="mr-2 rounded-circle" style="width: 16px; height: 16px; background-color: #FF0000; border: 2px solid #555;"></div>
                                        <span>Sans secteur</span>
                                    </div>
                                </div>

                                <!-- Client types -->
                                <div class="d-flex gap-4 flex-wrap">
                                    <div class="text-subtitle-2">Types:</div>
                                    <div class="d-flex align-center">
                                        <div class="mr-2 rounded-circle" style="width: 16px; height: 16px; background-color: #3388ff; border: 2px solid #555;"></div>
                                        <span>Client</span>
                                    </div>
                                    <div class="d-flex align-center">
                                        <div class="mr-2" style="width: 16px; height: 16px; background-color: transparent; border: 3px solid #555; border-radius: 50%;"></div>
                                        <span>Prospect (anneau)</span>
                                    </div>
                                    <div class="d-flex align-center">
                                        <div class="mr-2" style="width: 0; height: 0; border-left: 8px solid transparent; border-right: 8px solid transparent; border-bottom: 16px solid #e53e3e;"></div>
                                        <span>Client avec dette</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Map Container -->
                        <div id="map" style="height: 600px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { onMounted, onUnmounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    clients: {
        type: Array,
        required: true,
    },
});

let leafletMap = null;
let currentOpenPopup = null;

const sectorColorPalette = [
    { name: 'blue',    hex: '#3388ff' },
    { name: 'green',   hex: '#22c55e' },
    { name: 'yellow',  hex: '#eab308' },
    { name: 'purple',  hex: '#a855f7' },
    { name: 'orange',  hex: '#f97316' },
    { name: 'pink',    hex: '#ec4899' },
    { name: 'teal',    hex: '#14b8a6' },
    { name: 'brown',   hex: '#a52a2a' },
    { name: 'gray',    hex: '#6b7280' },
    { name: 'cyan',    hex: '#06b6d4' },
    { name: 'magenta', hex: '#d946ef' },
    { name: 'lime',    hex: '#84cc16' },
];

const sectorsWithColors = computed(() => {
    const sectors = {};
    let colorIndex = 0;

    props.clients.forEach(client => {
        if (client.sector?.id && !sectors[client.sector.id]) {
            const palette = sectorColorPalette[colorIndex % sectorColorPalette.length];
            sectors[client.sector.id] = {
                name: client.sector.name,
                hexColor: palette.hex,
            };
            colorIndex++;
        }
    });

    return sectors;
});

function buildMarkerIcon(hexColor, clientType) {
    let svgShape;

    if (clientType === 'prospect') {
        // Ring / circle outline for prospects
        svgShape = `<circle cx="12" cy="12" r="9" fill="none" stroke="${hexColor}" stroke-width="3"/>`;
    } else if (clientType === 'debt') {
        // Triangle pointing down for clients with debt
        svgShape = `<polygon points="12,3 22,21 2,21" fill="${hexColor}" stroke="#333" stroke-width="1"/>`;
    } else {
        // Filled circle for regular clients
        svgShape = `<circle cx="12" cy="12" r="9" fill="${hexColor}" stroke="#333" stroke-width="1"/>`;
    }

    const svgMarkup = `
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
            ${svgShape}
        </svg>`;

    return L.divIcon({
        html: svgMarkup,
        className: '',
        iconSize: [24, 24],
        iconAnchor: [12, 12],
        popupAnchor: [0, -14],
    });
}

function buildPopupContent(client) {
    const debtFormatted = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XOF' }).format(client.total_debt);
    return `
        <div style="min-width:180px">
            <strong style="font-size:14px">${client.name}</strong>
            <ul style="margin:6px 0 0; padding-left:16px; font-size:13px">
                <li>${client.address || 'Pas d\'adresse'}</li>
                <li>Tél: ${client.phone_number || '—'}</li>
                <li>Type: ${client.is_prospect ? 'Prospect' : 'Client'}</li>
                <li>Secteur: ${client.sector?.name || 'Non assigné'}</li>
                ${client.has_debt ? `<li style="color:#c53030;font-weight:bold">Dette: ${debtFormatted}</li>` : ''}
                ${client.description ? `<li>${client.description}</li>` : ''}
            </ul>
        </div>`;
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

function initMap() {
    leafletMap = L.map('map').setView([14.7167, -17.4677], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
    }).addTo(leafletMap);

    const markerBounds = [];

    props.clients.forEach(client => {
        if (!client.gps_coordinates) {
            return;
        }

        const coordinates = parseGpsCoordinates(client.gps_coordinates);
        if (!coordinates) {
            console.error(`Invalid GPS coordinates for client ${client.name}:`, client.gps_coordinates);
            return;
        }

        const sectorHexColor = client.sector?.id
            ? sectorsWithColors.value[client.sector.id]?.hexColor ?? '#FF0000'
            : '#FF0000';

        let clientType;
        if (client.is_prospect) {
            clientType = 'prospect';
        } else if (client.has_debt) {
            clientType = 'debt';
        } else {
            clientType = 'regular';
        }

        const markerIcon = buildMarkerIcon(sectorHexColor, clientType);

        const marker = L.marker([coordinates.lat, coordinates.lng], { icon: markerIcon })
            .addTo(leafletMap)
            .bindPopup(buildPopupContent(client));

        marker.on('click', () => {
            marker.openPopup();
        });

        markerBounds.push([coordinates.lat, coordinates.lng]);
    });

    if (markerBounds.length > 0) {
        leafletMap.fitBounds(markerBounds, { padding: [30, 30] });
    }
}

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