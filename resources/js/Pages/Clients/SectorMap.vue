<template>
    <Head title="Carte des Clients du Secteur" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Carte des Clients - Secteur {{ sector?.name }}
                </h2>
                <div class="flex gap-2">
                    <v-btn
                        color="success"
                        :disabled="selectedCustomers.length === 0"
                        :loading="loading"
                        @click="addSelectedCustomers"
                    >
                        <v-icon start>mdi-account-multiple-plus</v-icon>
                        Ajouter les clients sélectionnés ({{ selectedCustomers.length }})
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="router.visit(route('clients.index'))"
                    >
                        <v-icon start>mdi-arrow-left</v-icon>
                        Retour
                    </v-btn>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="mb-4">
                            <div class="text-subtitle-1 font-weight-bold mb-2">Légende:</div>
                            <div class="d-flex gap-4 flex-wrap">
                                <div class="d-flex align-center">
                                    <div class="mr-2 rounded-circle" style="width:16px;height:16px;background-color:#22c55e;border:2px solid #555;"></div>
                                    <span>Clients du secteur</span>
                                </div>
                                <div class="d-flex align-center">
                                    <div class="mr-2 rounded-circle" style="width:16px;height:16px;background-color:#ef4444;border:2px solid #555;"></div>
                                    <span>Clients disponibles</span>
                                </div>
                                <div class="d-flex align-center">
                                    <div class="mr-2 rounded-circle" style="width:16px;height:16px;background-color:#eab308;border:2px solid #555;"></div>
                                    <span>Prospects disponibles</span>
                                </div>
                                <div class="d-flex align-center">
                                    <div class="mr-2 rounded-circle" style="width:16px;height:16px;background-color:#3b82f6;border:2px solid #555;"></div>
                                    <span>Clients sélectionnés</span>
                                </div>
                            </div>
                        </div>

                        <!-- Map Container -->
                        <div id="sector-map" style="height: 600px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <v-overlay
            :model-value="loading"
            class="align-center justify-center"
        >
            <v-progress-circular
                color="primary"
                indeterminate
                size="64"
            ></v-progress-circular>
        </v-overlay>
    </AuthenticatedLayout>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import 'sweetalert2/dist/sweetalert2.css';

const props = defineProps({
    sector: {
        type: Object,
        required: true,
    },
});

const loading = ref(false);
const selectedCustomers = ref([]);

let leafletMap = null;
// Tracks Leaflet marker instances keyed by customer id so we can update their icon on selection toggle
const markersByCustomerId = {};

const ICON_GREEN  = buildCircleIcon('#22c55e');
const ICON_RED    = buildCircleIcon('#ef4444');
const ICON_YELLOW = buildCircleIcon('#eab308');
const ICON_BLUE   = buildCircleIcon('#3b82f6');

function buildCircleIcon(hexColor) {
    const svgMarkup = `
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9" fill="${hexColor}" stroke="#333" stroke-width="1.5"/>
        </svg>`;

    return L.divIcon({
        html: svgMarkup,
        className: '',
        iconSize: [24, 24],
        iconAnchor: [12, 12],
        popupAnchor: [0, -14],
    });
}

function resolveMarkerIconForAvailableCustomer(customer) {
    const isSelected = selectedCustomers.value.some(selectedCustomer => selectedCustomer.id === customer.id);
    if (isSelected) {
        return ICON_BLUE;
    }
    return customer.is_prospect ? ICON_YELLOW : ICON_RED;
}

function buildPopupContent(customer) {
    const isSelected = selectedCustomers.value.some(selectedCustomer => selectedCustomer.id === customer.id);
    const selectionButtonLabel = isSelected ? 'Désélectionner' : 'Sélectionner';

    return `
        <div style="min-width:200px">
            <strong style="font-size:14px">${customer.name}</strong>
            <div style="margin:6px 0;font-size:13px">
                <div><strong>Adresse:</strong> ${customer.address || 'Non spécifiée'}</div>
                <div><strong>Téléphone:</strong> ${customer.phone_number || 'Non spécifié'}</div>
                <div><strong>Type:</strong> ${customer.is_prospect ? 'Prospect' : 'Client'}</div>
                ${customer.description ? `<div><strong>Description:</strong> ${customer.description}</div>` : ''}
            </div>
            ${customer.can_be_added
                ? `<button
                        onclick="window.__sectorMapToggleSelection(${customer.id})"
                        style="margin-top:6px;padding:4px 12px;background:#3b82f6;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px"
                   >
                       ${selectionButtonLabel}
                   </button>`
                : '<div style="color:#16a34a;font-weight:bold;margin-top:6px">Déjà dans le secteur</div>'
            }
        </div>`;
}

function toggleCustomerSelection(customerId) {
    const markerEntry = markersByCustomerId[customerId];
    if (!markerEntry) {
        return;
    }

    const { customer, marker } = markerEntry;
    const existingIndex = selectedCustomers.value.findIndex(selectedCustomer => selectedCustomer.id === customerId);

    if (existingIndex === -1) {
        selectedCustomers.value.push(customer);
    } else {
        selectedCustomers.value.splice(existingIndex, 1);
    }

    marker.setIcon(resolveMarkerIconForAvailableCustomer(customer));
    marker.setPopupContent(buildPopupContent(customer));
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

async function loadMapData() {
    try {
        loading.value = true;

        const response = await axios.get(route('sectors.map-customers', props.sector.id));
        const { customers, sector_customers } = response.data;

        // Remove existing markers
        Object.values(markersByCustomerId).forEach(({ marker }) => marker.remove());
        Object.keys(markersByCustomerId).forEach(key => delete markersByCustomerId[key]);
        selectedCustomers.value = [];

        const allCustomers = [...customers, ...sector_customers];
        const markerBounds = [];

        allCustomers.forEach(customer => {
            if (!customer.gps_coordinates) {
                return;
            }

            const coordinates = parseGpsCoordinates(customer.gps_coordinates);
            if (!coordinates) {
                console.error(`Invalid GPS coordinates for customer ${customer.name}:`, customer.gps_coordinates);
                return;
            }

            const markerIcon = customer.can_be_added
                ? resolveMarkerIconForAvailableCustomer(customer)
                : ICON_GREEN;

            const marker = L.marker([coordinates.lat, coordinates.lng], { icon: markerIcon })
                .addTo(leafletMap)
                .bindPopup(buildPopupContent(customer));

            if (customer.can_be_added) {
                markersByCustomerId[customer.id] = { customer, marker };
            }

            markerBounds.push([coordinates.lat, coordinates.lng]);
        });

        if (markerBounds.length > 0) {
            leafletMap.fitBounds(markerBounds, { padding: [30, 30] });
        } else {
            leafletMap.setView([14.6937, -17.4441], 12);
        }
    } catch (error) {
        console.error('Error loading map data:', error);
        Swal.fire({
            title: 'Erreur',
            text: 'Une erreur est survenue lors du chargement des données de la carte',
            icon: 'error',
        });
    } finally {
        loading.value = false;
    }
}

async function addSelectedCustomers() {
    if (selectedCustomers.value.length === 0) {
        return;
    }

    try {
        loading.value = true;
        await axios.post(route('sectors.add-customers', props.sector.id), {
            customer_ids: selectedCustomers.value.map(selectedCustomer => selectedCustomer.id),
        });

        Swal.fire({
            title: 'Succès',
            text: `${selectedCustomers.value.length} client(s) ajouté(s) au secteur avec succès`,
            icon: 'success',
        });

        await loadMapData();
    } catch (error) {
        console.error('Error adding customers to sector:', error);
        Swal.fire({
            title: 'Erreur',
            text: 'Une erreur est survenue lors de l\'ajout des clients au secteur',
            icon: 'error',
        });
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    // Expose toggle function globally so popup button onclick can call it
    window.__sectorMapToggleSelection = toggleCustomerSelection;

    leafletMap = L.map('sector-map').setView([14.6937, -17.4441], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
    }).addTo(leafletMap);

    await loadMapData();
});

onUnmounted(() => {
    delete window.__sectorMapToggleSelection;

    if (leafletMap) {
        leafletMap.remove();
        leafletMap = null;
    }
});
</script>
