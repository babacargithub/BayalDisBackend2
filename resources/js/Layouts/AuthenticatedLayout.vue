<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

const menuItems = [
    { name: 'Dashboard', route: 'dashboard', icon: 'mdi-view-dashboard' },
    { name: 'Ventes', route: 'ventes.index', icon: 'mdi-cart' },
    { name: 'Produits', route: 'produits.index', icon: 'mdi-package-variant-closed' },
    { name: 'Clients', route: 'clients.index', icon: 'mdi-account-multiple' },
    { name: 'Commerciaux', route: 'commerciaux.index', icon: 'mdi-account-tie' },
    { name: 'Zones', route: 'zones.index', icon: 'mdi-map-marker-radius' },
    { name: 'Commandes', route: 'orders.index', icon: 'mdi-package' },
    { name: 'Lots de livraison', route: 'delivery-batches.index', icon: 'mdi-truck-delivery' },
];
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav class="bg-white border-b border-gray-100">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="shrink-0 flex items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                                <Link
                                    v-for="item in menuItems"
                                    :key="item.route"
                                    :href="route(item.route)"
                                    :class="[
                                        'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out',
                                        route().current(item.route)
                                            ? 'border-indigo-400 text-gray-900'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                    ]"
                                >
                                    <v-icon size="small" :icon="item.icon" class="mr-2" />
                                    {{ item.name }}
                                </Link>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-mr-2 flex items-center sm:hidden">
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out"
                            >
                                <v-icon>{{ showingNavigationDropdown ? 'mdi-close' : 'mdi-menu' }}</v-icon>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
                    class="sm:hidden"
                >
                    <div class="pt-2 pb-3 space-y-1">
                        <Link
                            v-for="item in menuItems"
                            :key="item.route"
                            :href="route(item.route)"
                            :class="[
                                'block pl-3 pr-4 py-2 border-l-4 text-base font-medium transition duration-150 ease-in-out',
                                route().current(item.route)
                                    ? 'border-indigo-400 text-indigo-700 bg-indigo-50'
                                    : 'border-transparent text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300'
                            ]"
                        >
                            <div class="flex items-center">
                                <v-icon size="small" :icon="item.icon" class="mr-2" />
                                {{ item.name }}
                            </div>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header class="bg-white shadow" v-if="$slots.header">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <div class="py-12">
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <slot></slot>
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
