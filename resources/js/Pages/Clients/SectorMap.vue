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
                            <div class="d-flex gap-4">
                                <div class="d-flex align-center">
                                    <v-icon color="green" class="mr-2">mdi-map-marker</v-icon>
                                    <span>Clients du secteur</span>
                                </div>
                                <div class="d-flex align-center">
                                    <v-icon color="red" class="mr-2">mdi-map-marker</v-icon>
                                    <span>Clients disponibles</span>
                                </div>
                                <div class="d-flex align-center">
                                    <v-icon color="yellow" class="mr-2">mdi-map-marker</v-icon>
                                    <span>Prospects disponibles</span>
                                </div>
                                <div class="d-flex align-center">
                                    <v-icon color="blue" class="mr-2">mdi-map-marker</v-icon>
                                    <span>Clients sélectionnés</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Map Container -->
                        <div id="map" style="height: 600px; width: 100%;"></div>
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
import { onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import 'sweetalert2/dist/sweetalert2.css';

const props = defineProps({
    sector: {
        type: Object,
        required: true
    },
    googleMapsApiKey: {
        type: String,
        required: true
    }
});

const loading = ref(false);
let map;
let markers = [];
let currentInfoWindow = null;
const selectedCustomers = ref([]);
const isGoogleMapsLoaded = ref(false);

const loadGoogleMapsScript = () => {
    return new Promise((resolve, reject) => {
        if (window.google) {
            isGoogleMapsLoaded.value = true;
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${props.googleMapsApiKey}`;
        script.async = true;
        script.defer = true;

        script.addEventListener('load', () => {
            isGoogleMapsLoaded.value = true;
            resolve();
        });

        script.addEventListener('error', (error) => {
            reject(new Error('Failed to load Google Maps script'));
        });

        document.head.appendChild(script);
    });
};

const addSelectedCustomers = async () => {
    if (selectedCustomers.value.length === 0) return;

    try {
        loading.value = true;
        await axios.post(route('sectors.add-customers', props.sector.id), {
            customer_ids: selectedCustomers.value.map(c => c.id)
        });
        
        Swal.fire({
            title: 'Succès',
            text: `${selectedCustomers.value.length} client(s) ajouté(s) au secteur avec succès`,
            icon: 'success'
        });
        
        selectedCustomers.value = [];
        await loadMapData();
    } catch (error) {
        console.error('Error adding customers to sector:', error);
        Swal.fire({
            title: 'Erreur',
            text: 'Une erreur est survenue lors de l\'ajout des clients au secteur',
            icon: 'error'
        });
    } finally {
        loading.value = false;
    }
};

const toggleCustomerSelection = (customer, marker) => {
    const index = selectedCustomers.value.findIndex(c => c.id === customer.id);
    if (index === -1) {
        selectedCustomers.value.push(customer);
        marker.setIcon({ url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png' });
    } else {
        selectedCustomers.value.splice(index, 1);
        marker.setIcon({ 
            url: customer.is_prospect ? 
                'https://maps.google.com/mapfiles/ms/icons/yellow-dot.png' : 
                'https://maps.google.com/mapfiles/ms/icons/red-dot.png' 
        });
    }

    // Close the current info window if it's open
    if (currentInfoWindow) {
        currentInfoWindow.close();
        currentInfoWindow = null;
    }
};

const createInfoWindowContent = (customer) => {
    return `
        <div class="p-3">
            <h3 class="text-lg font-bold mb-2">${customer.name}</h3>
            <div class="mb-2">
                <div><strong>Adresse:</strong> ${customer.address || 'Non spécifiée'}</div>
                <div><strong>Téléphone:</strong> ${customer.phone_number || 'Non spécifié'}</div>
                <div><strong>Type:</strong> ${customer.is_prospect ? 'Prospect' : 'Client'}</div>
                ${customer.description ? `<div><strong>Description:</strong> ${customer.description}</div>` : ''}
            </div>
            ${customer.can_be_added ? 
                `<button 
                    onclick="window.toggleCustomerSelection_${customer.id}()"
                    class="px-4 py-2 bg-primary text-white rounded hover:bg-primary-dark"
                >
                    ${selectedCustomers.value.find(c => c.id === customer.id) ? 'Désélectionner' : 'Sélectionner'}
                </button>` 
                : '<div class="text-success">Déjà dans le secteur</div>'
            }
        </div>
    `;
};

const loadMapData = async () => {
    try {
        loading.value = true;
        const response = await axios.get(route('sectors.map-customers', props.sector.id));
        const { customers, sector_customers } = response.data;
        
        console.log('Loaded customers:', customers);
        console.log('Loaded sector customers:', sector_customers);

        // Clear existing markers
        markers.forEach(marker => marker.setMap(null));
        markers = [];

        // Add markers for all customers
        const allCustomers = [...customers, ...sector_customers];
        console.log('Total customers to map:', allCustomers.length);

        allCustomers.forEach(customer => {
            if (customer.gps_coordinates) {
                try {
                    console.log('Processing customer:', customer.name, 'GPS:', customer.gps_coordinates);
                    const coordinates = customer.gps_coordinates.split(',');
                    if (coordinates.length !== 2) {
                        console.error('Invalid coordinates format for customer:', customer.name);
                        return;
                    }

                    const lat = parseFloat(coordinates[0].trim());
                    const lng = parseFloat(coordinates[1].trim());

                    if (isNaN(lat) || isNaN(lng)) {
                        console.error('Invalid coordinates values for customer:', customer.name);
                        return;
                    }

                    console.log('Creating marker for:', customer.name, 'at:', lat, lng);

                    // Determine marker color based on customer type and sector membership
                    let iconUrl;
                    if (!customer.can_be_added) {
                        iconUrl = 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'; // In sector
                    } else if (customer.is_prospect) {
                        iconUrl = 'https://maps.google.com/mapfiles/ms/icons/yellow-dot.png'; // Available prospect
                    } else {
                        iconUrl = 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'; // Available customer
                    }

                    const marker = new google.maps.Marker({
                        position: { lat, lng },
                        map: map,
                        title: customer.name,
                        icon: { url: iconUrl }
                    });

                    // Add info window with customer details
                    const infoWindow = new google.maps.InfoWindow({
                        content: createInfoWindowContent(customer)
                    });

                    // Add click handler for the selection button
                    if (customer.can_be_added) {
                        window[`toggleCustomerSelection_${customer.id}`] = () => {
                            toggleCustomerSelection(customer, marker);
                            infoWindow.setContent(createInfoWindowContent(customer));
                        };
                    }

                    marker.addListener('click', () => {
                        if (currentInfoWindow) {
                            currentInfoWindow.close();
                        }
                        infoWindow.open(map, marker);
                        currentInfoWindow = infoWindow;
                    });

                    markers.push(marker);
                    console.log('Marker created successfully for:', customer.name);
                } catch (error) {
                    console.error(`Error creating marker for customer ${customer.name}:`, error);
                }
            }
        });

        // If no markers were created, center the map on a default location
        if (markers.length === 0) {
            console.log('No markers created, using default center');
            map.setCenter({ lat: 14.6937, lng: -17.4441 }); // Default to Dakar
            map.setZoom(12);
        } else {
            // Fit the map bounds to include all markers
            const bounds = new google.maps.LatLngBounds();
            markers.forEach(marker => bounds.extend(marker.getPosition()));
            map.fitBounds(bounds);
        }
    } catch (error) {
        console.error('Error loading map data:', error);
        Swal.fire({
            title: 'Erreur',
            text: 'Une erreur est survenue lors du chargement des données de la carte',
            icon: 'error'
        });
    } finally {
        loading.value = false;
    }
};

onMounted(async () => {
    try {
        loading.value = true;
        await loadGoogleMapsScript();
        
        // Initialize the map
        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 12,
            center: { lat: 14.6937, lng: -17.4441 }, // Default center (Dakar)
        });

        // Load the initial data
        await loadMapData();
    } catch (error) {
        console.error('Error initializing map:', error);
        Swal.fire({
            title: 'Erreur',
            text: 'Une erreur est survenue lors du chargement de Google Maps',
            icon: 'error'
        });
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
/* Add your styles here */
</style>