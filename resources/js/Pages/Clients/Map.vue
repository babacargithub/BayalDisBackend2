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
                                        <div class="d-flex align-center gap-2 mr-2">
                                            <img :src="`https://maps.google.com/mapfiles/ms/icons/${sector.color}-dot.png`" 
                                                 :alt="sector.name" style="width: 20px; height: 20px;">
                                        </div>
                                        <span>{{ sector.name }}</span>
                                    </div>
                                    <div class="d-flex align-center">
                                        <div class="d-flex align-center gap-2 mr-2">
                                            <img src="https://maps.google.com/mapfiles/ms/icons/red-dot.png" 
                                                 alt="Sans secteur" style="width: 20px; height: 20px;">
                                        </div>
                                        <span>Sans secteur</span>
                                    </div>
                                </div>
                                
                                <!-- Client types -->
                                <div class="d-flex gap-4 flex-wrap">
                                    <div class="text-subtitle-2">Types:</div>
                                    <div class="d-flex align-center">
                                        <img src="https://maps.google.com/mapfiles/ms/icons/red-dot.png" 
                                             alt="Client" class="mr-2" style="width: 20px; height: 20px;">
                                        <span>Client</span>
                                    </div>
                                    <div class="d-flex align-center">
                                        <div class="rounded-circle mr-2" style="width: 20px; height: 20px; background-color: red; border: 2px solid black;"></div>
                                        <span>Prospect</span>
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
import { onMounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    clients: {
        type: Array,
        required: true
    },
    googleMapsApiKey: {
        type: String,
        required: true
    }
});

let map;
let markers = [];
let currentInfoWindow = null;

// Define a list of distinct colors for sectors
const sectorColors = [
    'red', 'blue', 'green', 'yellow', 'purple', 'orange', 
    'pink', 'teal', 'brown', 'gray', 'cyan', 'magenta'
];

// Group clients by sector and assign colors
const sectorsWithColors = computed(() => {
    const sectors = {};
    let colorIndex = 0;

    props.clients.forEach(client => {
        if (client.sector?.id) {
            if (!sectors[client.sector.id]) {
                sectors[client.sector.id] = {
                    name: client.sector.name,
                    color: sectorColors[colorIndex % sectorColors.length],
                    clients: []
                };
                colorIndex++;
            }
            sectors[client.sector.id].clients.push(client);
        }
    });

    return sectors;
});

// Get marker options based on client type and sector
function getMarkerOptions(client) {
    let markerColor;
    if (client.sector?.id) {
        markerColor = sectorsWithColors.value[client.sector.id]?.color || 'red';
    } else {
        markerColor = 'red';
    }

    // Create SVG marker for prospects
    const createProspectSVG = (color) => {
        const colors = {
            'red': '#FF0000',
            'blue': '#0000FF',
            'green': '#008000',
            'yellow': '#FFD700',
            'purple': '#800080',
            'orange': '#FFA500',
            'pink': '#FFC0CB',
            'teal': '#008080',
            'brown': '#A52A2A',
            'gray': '#808080',
            'cyan': '#00FFFF',
            'magenta': '#FF00FF'
        };
        const fillColor = colors[color] || colors['red'];
        
        return {
            path: google.maps.SymbolPath.CIRCLE,
            fillColor: fillColor,
            fillOpacity: 1,
            strokeWeight: 1,
            strokeColor: '#000000',
            scale: 8
        };
    };

    // Create regular marker for clients
    const createClientMarker = (color) => {
        return {
            url: `https://maps.google.com/mapfiles/ms/icons/${color}-dot.png`,
            scaledSize: new google.maps.Size(30, 30)
        };
    };

    return {
        position: { 
            lat: parseFloat(client.gps_coordinates.split(',')[0].trim()),
            lng: parseFloat(client.gps_coordinates.split(',')[1].trim())
        },
        map: map,
        title: client.name,
        icon: client.is_prospect ? createProspectSVG(markerColor) : createClientMarker(markerColor)
    };
}

function initMap() {
    const center = { lat: 14.7167, lng: -17.4677 };
    
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 12,
        center: center,
    });

    props.clients.forEach(client => {
        if (client.gps_coordinates) {
            try {
                const marker = new google.maps.Marker(getMarkerOptions(client));

                const infoWindow = new google.maps.InfoWindow({
                    content: `
                        <div class="p-2">
                            <h3 class="font-bold">${client.name}</h3>
                            <ul>
                                <li>${client.address || 'Pas d\'adresse'}</li>
                                <li>Tél: ${client.phone_number}</li>
                                <li>Type: ${client.is_prospect ? 'Prospect' : 'Client'}</li>
                                <li>Secteur: ${client.sector?.name || 'Non assigné'}</li>
                                ${client.description ? `<li>${client.description}</li>` : ''}
                            </ul>
                        </div>
                    `
                });

                marker.addListener('click', () => {
                    if (currentInfoWindow) {
                        currentInfoWindow.close();
                    }
                    infoWindow.open(map, marker);
                    currentInfoWindow = infoWindow;
                });

                markers.push(marker);
            } catch (error) {
                console.error(`Error processing coordinates for client ${client.name}:`, error);
            }
        }
    });

    // Add click listener to close info window
    map.addListener('click', () => {
        if (currentInfoWindow) {
            currentInfoWindow.close();
            currentInfoWindow = null;
        }
    });

    // Fit bounds to markers
    if (markers.length > 0) {
        const bounds = new google.maps.LatLngBounds();
        markers.forEach(marker => bounds.extend(marker.getPosition()));
        map.fitBounds(bounds);
    }
}

onMounted(() => {
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${props.googleMapsApiKey}`;
    script.async = true;
    script.defer = true;
    script.onload = initMap;
    document.head.appendChild(script);
});
</script> 