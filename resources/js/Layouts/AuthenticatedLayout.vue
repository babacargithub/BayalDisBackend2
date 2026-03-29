<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, router } from '@inertiajs/vue3';

const drawer = ref(true);
const rail = ref(false);
const isMobile = ref(false);

onMounted(() => {
    checkMobile();
    window.addEventListener('resize', checkMobile);
    routerCleanup = router.on('navigate', ensureActiveGroupIsOpen);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', checkMobile);
    routerCleanup?.();
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
        items: [
            { name: 'Ventes du jour', route: 'ventes.index', icon: 'mdi-cash-register' },
            { name: 'Factures clients', route: 'sales-invoices.index', icon: 'mdi-file-document-outline' },
        ]
    },
    {
        name: 'Clients',
        icon: 'mdi-account-group',
        isDropdown: true,
        items: [
            { name: 'Clients', route: 'clients.index', icon: 'mdi-account-group' },
            { name: 'Secteurs', route: 'sectors.index', icon: 'mdi-map-marker-multiple' },
            { name: 'Top Clients', route: 'clients.top-customers', icon: 'mdi-trophy' },
            { name: 'Visites Clients', route: 'visits.index', icon: 'mdi-map-marker-check' },
            { name: 'Commandes', route: 'orders.index', icon: 'mdi-package' },
            { name: 'Lots de livraison', route: 'delivery-batches.index', icon: 'mdi-truck-delivery' },
            { name: 'Catégories client', route: 'customer-categories.index', icon: 'mdi-folder-account' },
            { name: 'Zones', route: 'zones.index', icon: 'mdi-map-marker-radius' },
        ]
    },
    {
        name: 'Commerciaux',
        icon: 'mdi-account-tie',
        isDropdown: true,
        items: [
            { name: 'Commerciaux', route: 'commerciaux.index', icon: 'mdi-account-tie' },
            { name: 'Équipes', route: 'teams.index', icon: 'mdi-account-group' },
            { name: 'Commissions', route: 'commissions.index', icon: 'mdi-cash-check' },
        ]
    },
    {
        name: 'Caisses',
        icon: 'mdi-cash-register',
        isDropdown: true,
        items: [
            { name: 'Caisses', route: 'caisses.index', icon: 'mdi-cash-register' },
            { name: 'Comptes', route: 'accounts.index', icon: 'mdi-bank-outline' },
            { name: 'Dépenses', route: 'depenses.index', icon: 'mdi-cash-minus' },
        ]
    },
    {
        name: 'Stock',
        icon: 'mdi-warehouse',
        isDropdown: true,
        items: [
            { name: 'Produits', route: 'produits.index', icon: 'mdi-package-variant-closed' },
            { name: 'Catégories', route: 'product-categories.index', icon: 'mdi-tag-multiple' },
            { name: 'Factures Achats', route: 'purchase-invoices.index', icon: 'mdi-file-document-outline' },
            { name: 'Chargements Véhicule', route: 'car-loads.index', icon: 'mdi-car' },
            { name: 'Fournisseurs', route: 'suppliers.index', icon: 'mdi-handshake' },
            { name: 'Véhicules', route: 'vehicles.index', icon: 'mdi-truck' },
        ]
    },
    {
        name: 'Admin',
        icon: 'mdi-shield-account',
        isDropdown: true,
        items: [
            { name: 'Rapports', route: 'admin.rapport', icon: 'mdi-chart-box' },
            { name: 'Statistiques', route: 'admin.statistiques', icon: 'mdi-chart-line' },
            { name: 'Zones & Lignes', route: 'admin.geo-stats', icon: 'mdi-map-marker-path' },
            { name: 'Utilisateurs', route: 'users.index', icon: 'mdi-account-multiple' },
            { name: 'Investissements', route: 'investments.index', icon: 'mdi-cash-multiple' },
            { name: 'Coûts d\'exploitation', route: 'monthly-fixed-costs.index', icon: 'mdi-office-building-cog' },
            { name: 'Politique de prix', route: 'pricing-policies.index', icon: 'mdi-tag-text' },
        ]
    },
];

const getGroupsContainingActiveRoute = () =>
    menuItems
        .filter(item => item.isDropdown && item.items?.some(sub => route().current(sub.route)))
        .map(item => item.name);

const openedGroups = ref(getGroupsContainingActiveRoute());

let routerCleanup;

const ensureActiveGroupIsOpen = () => {
    const activeGroups = getGroupsContainingActiveRoute();
    activeGroups.forEach(groupName => {
        if (!openedGroups.value.includes(groupName)) {
            openedGroups.value = [...openedGroups.value, groupName];
        }
    });
};
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
            <v-list nav v-model:opened="openedGroups">
                <template v-for="item in menuItems" :key="item.name">
                    <!-- Regular menu item -->
                    <v-list-item
                        v-if="!item.isDropdown"
                        :href="route(item.route)"
                        :active="route().current(item.route)"
                        :prepend-icon="item.icon"
                        :title="item.name"
                    />

                    <!-- Dropdown menu item -->
                    <v-list-group
                        v-else
                        fluid
                        :value="item.name"
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
                            class="sub-menu-item"
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
:deep(.v-list-group__items .v-list-item--active) {
    background-color: red !important;
    color: white !important;
}

:deep(.v-list-group__items .v-list-item) {
    padding-left: 28px !important;
}
</style>
