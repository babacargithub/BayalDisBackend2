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
                    <div class="p-0">
                        <!-- Map Container -->
                        <div id="map" style="height: 600px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { onMounted } from 'vue';
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
let currentInfoWindow = null; // Track the currently open info window

function initMap() {
    // Default center (Dakar coordinates)
    const center = { lat: 14.7167, lng: -17.4677 };
    
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 12,
        center: center,
    });

    // Add markers for each client with GPS coordinates
    props.clients.forEach(client => {
        if (client.gps_coordinates) {
            try {
                const [lat, lng] = client.gps_coordinates.split(',').map(coord => parseFloat(coord.trim()));
                if (!isNaN(lat) && !isNaN(lng)) {
                    const marker = new google.maps.Marker({
                        position: { lat, lng },
                        map: map,
                        title: client.name,
                        icon: {
                            url: client.is_prospect ? 
                                'https://maps.google.com/mapfiles/ms/icons/yellow-dot.png' : 
                                'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
                        }
                    });

                    // Add info window
                    const infoWindow = new google.maps.InfoWindow({
                        content: `
                            <div class="p-2">
                                <h3 class="font-bold">${client.name}</h3>
                                <ul>
                                    <li>${client.address || 'Pas d\'adresse'}</li>
                                    <li>Tél: ${client.phone_number}</li>
                                    <li>${client.is_prospect ? 'Prospect' : 'Client'}</li>
                                    <li>${client.description || ''}</li>
                                </ul>
                            </div>
                        `
                    });

                    marker.addListener('click', () => {
                        // Close the currently open info window if there is one
                        if (currentInfoWindow) {
                            currentInfoWindow.close();
                        }
                        // Open the new info window and update the reference
                        infoWindow.open(map, marker);
                        currentInfoWindow = infoWindow;
                    });

                    // Add click listener to the map to close info window when clicking elsewhere
                    map.addListener('click', () => {
                        if (currentInfoWindow) {
                            currentInfoWindow.close();
                            currentInfoWindow = null;
                        }
                    });

                    markers.push(marker);
                }
            } catch (error) {
                console.error(`Error processing coordinates for client ${client.name}:`, error);
            }
        }
    });

    // Fit bounds to markers if any exist
    if (markers.length > 0) {
        const bounds = new google.maps.LatLngBounds();
        markers.forEach(marker => bounds.extend(marker.getPosition()));
        map.fitBounds(bounds);
    }
}

onMounted(() => {
    // Load Google Maps API
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${props.googleMapsApiKey}`;
    script.async = true;
    script.defer = true;
    script.onload = initMap;
    document.head.appendChild(script);
});
</script> 