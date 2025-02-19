<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';

const drawer = ref(true);
const rail = ref(false);
const clientsDropdownOpen = ref(false);
const ordersDropdownOpen = ref(false);
const ventesDropdownOpen = ref(false);
const adminDropdownOpen = ref(false);

// Add mobile detection
const isMobile = ref(false);

// Watch for screen size changes
onMounted(() => {
    checkMobile();
    window.addEventListener('resize', checkMobile);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', checkMobile);
});

const checkMobile = () => {
    isMobile.value = window.innerWidth < 960; // Vuetify's md breakpoint
    drawer.value = !isMobile.value;
};

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
        name: 'Clients',
        icon: 'mdi-account-group',
        isDropdown: true,
        ref: clientsDropdownOpen,
        items: [
            { name: 'Clients', route: 'clients.index', icon: 'mdi-account-group' },
            { name: 'Visites Clients', route: 'visits.index', icon: 'mdi-map-marker-check' }, { name: 'Commandes', route: 'orders.index', icon: 'mdi-package' },
          { name: 'Lots de livraison', route: 'delivery-batches.index', icon: 'mdi-truck-delivery' },
            { name: 'Catégories client', route: 'customer-categories.index', icon: 'mdi-folder-account' },
            { name: 'Zones', route: 'zones.index', icon: 'mdi-map-marker-radius' },
        ]
    },
    { name: 'Commerciaux', route: 'commerciaux.index', icon: 'mdi-account-tie' },
    { name: 'Caisses', route: 'caisses.index', icon: 'mdi-cash-register' },
    // {
    //     name: 'Commandes & Livraisons',
    //     icon: 'mdi-truck-delivery',
    //     isDropdown: true,
    //     ref: ordersDropdownOpen,
    //     items: [
    //
    //     ]
    // },
    {
        name: 'Stock',
        icon: 'mdi-warehouse',
        isDropdown: true,
        ref: ordersDropdownOpen,
        items: [
          { name: 'Produits', route: 'produits.index', icon: 'mdi-package-variant-closed' },
          { name: 'Factures Achats', route: 'purchase-invoices.index', icon: 'mdi-file-document-outline' },
            { name: 'Chargements Véhicule', route: 'car-loads.index', icon: 'mdi-car' },
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
    <v-app>
        <!-- Navigation Drawer -->
        <v-navigation-drawer
            v-model="drawer"
            :rail="rail"
            :permanent="!isMobile"
            :temporary="isMobile"
            style="background: darkblue"
            theme="dark"
        >
            <!-- Logo -->
            <div class="px-4 py-3">
                <Link :href="route('dashboard')" class="d-flex align-center">
                    <img src="/logo.jpg" alt="Logo" height="40" />
                </Link>
            </div>

            <v-divider class="mb-2"></v-divider>

            <!-- Toggle Button -->
            <div class="">
                <v-btn
                    variant="text"
                    icon
                    @click.stop="rail = !rail"
                >
                    <v-icon>{{ rail ? 'mdi-chevron-right' : 'mdi-chevron-left' }}</v-icon>
                </v-btn>
            </div>

            <!-- Navigation List -->
            <v-list nav>
                <template v-for="item in menuItems" :key="item.name">
                    <!-- Regular menu item -->
                    <v-list-item
                        v-if="!item.isDropdown"
                        :href="route(item.route)"
                        :active="route().current(item.route)"
                        :prepend-icon="item.icon"
                        :title="item.name"
                        class=""
                    />

                    <!-- Dropdown menu item -->
                    <v-list-group
                        v-else
                        fluid
                        active-class="bg-red text-white"


                    >
                        <template v-slot:activator="{ props }">
                            <v-list-item
                                v-bind="props"
                                :prepend-icon="item.icon"
                                :title="item.name"
                            />
                        </template>

                        <v-list-item
                            v-for="subItem in item.items"
                            :key="subItem.route"
                            :href="route(subItem.route)"
                            :active="route().current(subItem.route)"
                            :prepend-icon="subItem.icon"
                            :title="subItem.name"
                            class=""
                        />
                    </v-list-group>
                </template>
            </v-list>
        </v-navigation-drawer>

        <!-- App Bar -->
        <v-app-bar color="surface">
            <template v-slot:prepend>
                <v-app-bar-nav-icon
                    @click.stop="drawer = !drawer"
                    class="d-md-none"
                ></v-app-bar-nav-icon>
            </template>

            <v-app-bar-title>
                <slot name="header"></slot>
            </v-app-bar-title>
        </v-app-bar>

        <!-- Main Content -->
        <v-main>
            <v-container fluid>
                <slot></slot>
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.v-list-item--active {
    background-color: rgba(255, 255, 255, 0.1) !important;
}

.v-list-group__items .v-list-item {
    padding-left: 16px;
}
</style>
