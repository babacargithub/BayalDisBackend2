<script setup>
import { ref, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';

const showSidebar = ref(false);
const clientsDropdownOpen = ref(false);
const ordersDropdownOpen = ref(false);
const ventesDropdownOpen = ref(false);
const adminDropdownOpen = ref(false);

const menuItems = [
    { name: 'Tableau de bord', route: 'dashboard', icon: 'mdi-view-dashboard' },
    { 
        name: 'Ventes',
        icon: 'mdi-cash-register',
        isDropdown: true,
        ref: ventesDropdownOpen,
        items: [
            { name: 'Ventes', route: 'ventes.index', icon: 'mdi-cash-register' },
            { name: 'Factures', route: 'sales-invoices.index', icon: 'mdi-file-document-outline' },
            { name: 'Investissements', route: 'investments.index', icon: 'mdi-cash-multiple' },
            { name: 'Dépenses', route: 'depenses.index', icon: 'mdi-cash-minus' },
        ]
    },
    { 
        name: 'Clients & Zones',
        icon: 'mdi-account-group',
        isDropdown: true,
        ref: clientsDropdownOpen,
        items: [
            { name: 'Clients', route: 'clients.index', icon: 'mdi-account-group' },
            { name: 'Visites Clients', route: 'visits.index', icon: 'mdi-map-marker-check' },
            { name: 'Catégories client', route: 'customer-categories.index', icon: 'mdi-folder-account' },
            { name: 'Zones', route: 'zones.index', icon: 'mdi-map-marker-radius' },
        ]
    },
    { name: 'Produits', route: 'produits.index', icon: 'mdi-package-variant-closed' },
    { name: 'Commerciaux', route: 'commerciaux.index', icon: 'mdi-account-tie' },
    { name: 'Caisses', route: 'caisses.index', icon: 'mdi-cash-register' },
    {
        name: 'Commandes & Livraisons',
        icon: 'mdi-truck-delivery',
        isDropdown: true,
        ref: ordersDropdownOpen,
        items: [
            { name: 'Commandes', route: 'orders.index', icon: 'mdi-package' },
            { name: 'Lots de livraison', route: 'delivery-batches.index', icon: 'mdi-truck-delivery' },
        ]
    },{
        name: 'Stock',
        icon: 'mdi-truck-delivery',
        isDropdown: true,
        ref: ordersDropdownOpen,
        items: [
            { name: 'Factures Achats', route: 'purchase-invoices.index', icon: 'mdi-file-document-outline' },
            { name: 'Fournisseurs', route: 'suppliers.index', icon: 'mdi-handshake' },
        ]
    },
    {
        name: 'Admin',
        icon: 'mdi-shield-account',
        isDropdown: true,
        ref: adminDropdownOpen,
        items: [
            { name: 'Rapports', route: 'admin.rapport', icon: 'mdi-chart-box' },
            { name: 'Utilisateurs', route: 'users.index', icon: 'mdi-account-multiple' },
        ]
    },
];
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100 flex">
            <!-- Sidebar -->
            <div 
                class="fixed inset-y-0 left-0 z-30 transform transition-transform duration-300"
                :class="[
                    showSidebar ? 'translate-x-0' : '-translate-x-full',
                    'md:translate-x-0 md:static'
                ]"
            >
                <div class="w-64 bg-blue-600 text-white min-h-screen">
                    <!-- Logo -->
                    <div class="p-4">
                        <Link :href="route('dashboard')">
                            <img src="/logo.jpg" alt="Logo" class="h-12 w-auto" />
                        </Link>
                    </div>

                    <!-- Navigation -->
                    <nav class="mt-4">
                        <div v-for="item in menuItems" :key="item.name" class="px-2">
                            <!-- Regular menu item -->
                            <Link
                                v-if="!item.isDropdown"
                                :href="route(item.route)"
                                :class="[
                                    'flex items-center px-4 py-2 text-sm rounded-lg mb-1 transition-colors',
                                    route().current(item.route)
                                        ? 'bg-blue-700 text-white'
                                        : 'text-white hover:bg-blue-700'
                                ]"
                            >
                                <v-icon size="small" :icon="item.icon" class="mr-3" />
                                {{ item.name }}
                            </Link>

                            <!-- Dropdown menu item -->
                            <div v-else class="mb-1">
                                <button
                                    @click="item.ref = !item.ref"
                                    class="w-full flex items-center justify-between px-4 py-2 text-sm text-white hover:bg-blue-700 rounded-lg transition-colors"
                                >
                                    <div class="flex items-center">
                                        <v-icon size="small" :icon="item.icon" class="mr-3" />
                                        {{ item.name }}
                                    </div>
                                    <v-icon
                                        size="small"
                                        :icon="item.ref ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                                    />
                                </button>
                                <div v-show="item.ref" class="ml-4 mt-1">
                                    <Link
                                        v-for="subItem in item.items"
                                        :key="subItem.route"
                                        :href="route(subItem.route)"
                                        :class="[
                                            'flex items-center px-4 py-2 text-sm rounded-lg mb-1 transition-colors',
                                            route().current(subItem.route)
                                                ? 'bg-blue-700 text-white'
                                                : 'text-white hover:bg-blue-700'
                                        ]"
                                    >
                                        <v-icon size="small" :icon="subItem.icon" class="mr-3" />
                                        {{ subItem.name }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </nav>
                </div>
            </div>

            <!-- Overlay for mobile -->
            <div 
                v-show="showSidebar" 
                class="fixed inset-0 bg-black bg-opacity-50 z-20 md:hidden"
                @click="showSidebar = false"
            ></div>

            <!-- Main Content -->
            <div class="flex-1">
                <nav class="bg-white border-b border-gray-100">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                        <div class="flex justify-between h-16">
                            <!-- Menu button -->
                            <button
                                @click="showSidebar = !showSidebar"
                                class="px-4 py-2 text-gray-500 focus:outline-none"
                            >
                                <v-icon size="24">{{ showSidebar ? 'mdi-close' : 'mdi-menu' }}</v-icon>
                            </button>

                            <!-- Settings Dropdown -->
                            <div class="flex items-center">
                                <slot name="header" />
                            </div>
                        </div>
                    </div>
                </nav>

                <!-- Page Content -->
                <main class="py-12">
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Add any additional styles here */
</style>
