<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import CustomerHistoryDialog from '@/Pages/Clients/CustomerHistoryDialog.vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    clients: {
        type: Object,
        required: true
    },
    commerciaux: {
        type: Array,
        required: true
    },
    allTags: {
        type: Array,
        default: () => [],
    },
    categoryCounts: {
        type: Array,
        default: () => [],
    },
    uncategorizedCount: {
        type: Number,
        default: 0,
    },
    prospectsCount: {
        type: Number,
        default: 0,
    },
    confirmedCount: {
        type: Number,
        default: 0,
    },
    prospectionStatuses: {
        type: Array,
        default: () => [],
    },
    errors: Object,
    flash: Object,
    filters: {
        type: Object,
        default: () => ({})
    }
});

// ─── Create form ──────────────────────────────────────────────────────────────

const createDialogVisible = ref(false);

const createForm = useForm({
    name: '',
    phone_number: '',
    owner_number: '',
    gps_coordinates: '',
    commercial_id: '',
    address: '',
    tag_ids: [],
});

const submitCreate = () => {
    createForm.post(route('clients.store'), {
        onSuccess: () => {
            createDialogVisible.value = false;
            createForm.reset();
        },
    });
};

// ─── Edit form ────────────────────────────────────────────────────────────────

const editDialogVisible = ref(false);
const editingClient = ref(null);

const editForm = useForm({
    name: '',
    phone_number: '',
    owner_number: '',
    gps_coordinates: '',
    commercial_id: '',
    description: '',
    address: '',
    tag_ids: [],
    customer_category_id: null,
});

const openEditDialog = (client) => {
    editingClient.value = client;
    editForm.name = client.name;
    editForm.phone_number = client.phone_number;
    editForm.owner_number = client.owner_number;
    editForm.gps_coordinates = client.gps_coordinates;
    editForm.commercial_id = client.commercial_id;
    editForm.description = client.description || '';
    editForm.address = client.address || '';
    editForm.tag_ids = (client.tags || []).map(tag => tag.id);
    editForm.customer_category_id = client.category?.id ?? null;
    editDialogVisible.value = true;
};

const submitEdit = () => {
    editForm.put(route('clients.update', editingClient.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editDialogVisible.value = false;
            editingClient.value = null;
        },
    });
};

// ─── Delete ───────────────────────────────────────────────────────────────────

const deleteDialogVisible = ref(false);
const clientToDelete = ref(null);
const deleteForm = ref(null);

const confirmDelete = (client) => {
    clientToDelete.value = client;
    deleteDialogVisible.value = true;
};

const deleteClient = () => {
    deleteForm.value = useForm({});
    deleteForm.value.delete(route('clients.destroy', clientToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogVisible.value = false;
            clientToDelete.value = null;
            deleteForm.value = null;
            window.location.reload();
        },
        onError: (errors) => {
            showSnackbar(errors.message || 'Une erreur est survenue lors de la suppression du client', 'error');
        }
    });
};

// ─── Snackbar ─────────────────────────────────────────────────────────────────

const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('');

const showSnackbar = (text, color = 'success') => {
    snackbarText.value = text;
    snackbarColor.value = color;
    snackbar.value = true;
};

watch(() => props.flash, (newFlash) => {
    if (!newFlash) return;
    if (newFlash.success) showSnackbar(newFlash.success, 'success');
    if (newFlash.error) showSnackbar(newFlash.error, 'error');
}, { deep: true, immediate: true });

// ─── History dialog ───────────────────────────────────────────────────────────

const showHistory = ref(false);
const selectedClient = ref(null);

const openHistory = (client) => {
    selectedClient.value = client;
    showHistory.value = true;
};

// ─── Map ──────────────────────────────────────────────────────────────────────

const openGoogleMaps = (coordinates) => {
    window.open(`https://www.google.com/maps?q=${coordinates}`, '_blank');
};

// ─── Clear description ────────────────────────────────────────────────────────

const clearClientDescription = (client) => {
    const clearForm = useForm({
        name: client.name,
        phone_number: client.phone_number,
        owner_number: client.owner_number ?? '',
        gps_coordinates: client.gps_coordinates ?? '',
        description: '',
        address: client.address ?? '',
        customer_category_id: client.category?.id ?? null,
        tag_ids: (client.tags || []).map(t => t.id),
    });
    clearForm.put(route('clients.update', client.id), {
        preserveScroll: true,
        onSuccess: () => showSnackbar('Note effacée avec succès', 'success'),
    });
};

// ─── Category icon palette ────────────────────────────────────────────────────

const categoryIconPalette = [
    { icon: 'mdi-tent',                  color: '#6D4C41' },
    { icon: 'mdi-silverware-fork-knife', color: '#1565C0' },
    { icon: 'mdi-chef-hat',             color: '#00695C' },
    { icon: 'mdi-food-variant',          color: '#2E7D32' },
    { icon: 'mdi-pot-steam',             color: '#E65100' },
    { icon: 'mdi-hamburger',             color: '#C62828' },
    { icon: 'mdi-grill',                 color: '#BF360C' },
    { icon: 'mdi-storefront',            color: '#6A1B9A' },
    { icon: 'mdi-help-circle',           color: '#546E7A' },
    { icon: 'mdi-home-heart',            color: '#AD1457' },
    { icon: 'mdi-car-wrench',            color: '#F57F17' },
    { icon: 'mdi-school',                color: '#0277BD' },
];

const getCategoryIconStyle = (categoryId) => {
    const index = (categoryId - 1) % categoryIconPalette.length;
    return categoryIconPalette[Math.max(0, index)];
};

// ─── Customer avatar helpers ──────────────────────────────────────────────────

const avatarColorPool = ['#1565C0', '#00695C', '#2E7D32', '#6A1B9A', '#E65100', '#AD1457', '#0277BD', '#BF360C'];

const getCustomerInitials = (name) => {
    const words = name.trim().split(/\s+/);
    if (words.length >= 2) {
        return (words[0][0] + words[words.length - 1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
};

const getAvatarColor = (client) => {
    if (client.category) {
        return getCategoryIconStyle(client.category.id).color;
    }
    let hash = 0;
    for (let i = 0; i < client.name.length; i++) {
        hash = (hash << 5) - hash + client.name.charCodeAt(i);
        hash |= 0;
    }
    return avatarColorPool[Math.abs(hash) % avatarColorPool.length];
};

// ─── Filters ──────────────────────────────────────────────────────────────────

const showOnlyProspects = ref(props.filters?.prospect_status === 'prospects');
const showOnlyConfirmed = ref(props.filters?.prospect_status === 'customers');
const selectedTagFilter = ref(props.filters?.tag_id ? Number(props.filters.tag_id) : null);
const selectedCategoryFilter = ref(
    props.filters?.category_id
        ? (props.filters.category_id === 'none' ? 'none' : Number(props.filters.category_id))
        : null
);
const selectedProspectionStatusFilter = ref(props.filters?.current_prospect_status ?? null);

const totalCustomerCount = computed(() =>
    props.categoryCounts.reduce((sum, category) => sum + category.count, 0) + props.uncategorizedCount
);

const applyFilters = () => {
    let prospectStatus = '';
    if (showOnlyProspects.value && !showOnlyConfirmed.value) {
        prospectStatus = 'prospects';
    } else if (showOnlyConfirmed.value && !showOnlyProspects.value) {
        prospectStatus = 'customers';
    }

    router.get(
        route('clients.index'),
        {
            prospect_status: prospectStatus,
            tag_id: selectedTagFilter.value || '',
            category_id: selectedCategoryFilter.value ?? '',
            current_prospect_status: selectedProspectionStatusFilter.value ?? '',
        },
        { preserveState: true, preserveScroll: true, only: ['clients', 'filters'] }
    );
};

watch(showOnlyProspects, applyFilters);
watch(showOnlyConfirmed, applyFilters);
watch(selectedTagFilter, applyFilters);
watch(selectedCategoryFilter, applyFilters);
watch(selectedProspectionStatusFilter, applyFilters);

// ─── Record prospection interaction ──────────────────────────────────────────

const prospectionDialogVisible = ref(false);
const prospectionTargetClient = ref(null);

const prospectionForm = useForm({
    status: '',
    notes: '',
    scheduled_revisit_date: '',
});

const openProspectionDialog = (client) => {
    prospectionTargetClient.value = client;
    prospectionForm.reset();
    prospectionDialogVisible.value = true;
};

const statusesRequiringRevisitDate = ['owner_absent', 'has_current_stock'];

const submitProspectionEvent = () => {
    prospectionForm.post(route('clients.prospection-events.store', prospectionTargetClient.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            prospectionDialogVisible.value = false;
            prospectionTargetClient.value = null;
            prospectionForm.reset();
        },
    });
};

// ─── Backend autocomplete search ──────────────────────────────────────────────

const searchQuery = ref('');
const searchResults = ref([]);
const isSearching = ref(false);
const searchMode = ref(false);

let searchDebounceTimer = null;

const onSearchInput = (value) => {
    clearTimeout(searchDebounceTimer);

    if (!value || value.length < 2) {
        searchResults.value = [];
        searchMode.value = false;
        isSearching.value = false;
        return;
    }

    isSearching.value = true;
    searchMode.value = true;

    searchDebounceTimer = setTimeout(async () => {
        try {
            const response = await axios.get(route('clients.search'), { params: { q: value } });
            searchResults.value = response.data;
        } finally {
            isSearching.value = false;
        }
    }, 300);
};

const clearSearch = () => {
    searchQuery.value = '';
    searchResults.value = [];
    searchMode.value = false;
};

// The displayed rows: search results when in search mode, paginated clients otherwise
const displayedClients = () => searchMode.value ? searchResults.value : props.clients.data;

// ─── Pagination ───────────────────────────────────────────────────────────────

const handlePageChange = (page) => {
    router.get(
        route('clients.index', {
            page,
            prospect_status: showOnlyProspects.value ? 'prospects' : showOnlyConfirmed.value ? 'customers' : '',
            tag_id: selectedTagFilter.value || '',
            category_id: selectedCategoryFilter.value ?? '',
            current_prospect_status: selectedProspectionStatusFilter.value ?? '',
        }),
        {},
        { preserveState: true, preserveScroll: true }
    );
};
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Clients</h2>
                    <p class="text-sm text-gray-500 mt-0.5">Gérez votre portefeuille clients</p>
                </div>
                <v-btn color="primary" prepend-icon="mdi-plus" rounded="lg" elevation="0" @click="createDialogVisible = true">
                    Nouveau Client
                </v-btn>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

                <!-- KPI stats row -->
                <div class="grid grid-cols-3 gap-4">
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-5 py-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center shrink-0">
                            <v-icon color="primary" size="20">mdi-account-group</v-icon>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900">{{ totalCustomerCount }}</div>
                            <div class="text-xs text-gray-500 font-medium uppercase tracking-wide mt-0.5">Total clients</div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-5 py-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-green-50 flex items-center justify-center shrink-0">
                            <v-icon color="success" size="20">mdi-check-circle</v-icon>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900">{{ confirmedCount }}</div>
                            <div class="text-xs text-gray-500 font-medium uppercase tracking-wide mt-0.5">Confirmés</div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-5 py-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-amber-50 flex items-center justify-center shrink-0">
                            <v-icon color="warning" size="20">mdi-account-question</v-icon>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900">{{ prospectsCount }}</div>
                            <div class="text-xs text-gray-500 font-medium uppercase tracking-wide mt-0.5">Prospects</div>
                        </div>
                    </div>
                </div>

                <!-- Main card -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

                    <!-- Category filter — single scrollable row -->
                    <div class="px-4 py-3 border-b border-gray-100">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider shrink-0">Catégories</span>
                            <div class="flex gap-2 overflow-x-auto" style="scrollbar-width: none; -ms-overflow-style: none;">
                                <v-chip
                                    :color="selectedCategoryFilter === null ? 'primary' : undefined"
                                    :variant="selectedCategoryFilter === null ? 'flat' : 'tonal'"
                                    size="small"
                                    class="cursor-pointer"
                                    style="flex-shrink: 0;"
                                    @click="selectedCategoryFilter = null"
                                >
                                    <v-icon start size="13">mdi-account-group</v-icon>
                                    Tous ({{ totalCustomerCount }})
                                </v-chip>
                                <v-chip
                                    v-for="category in categoryCounts"
                                    :key="category.id"
                                    size="small"
                                    :variant="selectedCategoryFilter === category.id ? 'flat' : 'tonal'"
                                    :style="selectedCategoryFilter === category.id
                                        ? { backgroundColor: getCategoryIconStyle(category.id).color, color: 'white', flexShrink: 0 }
                                        : { flexShrink: 0 }"
                                    class="cursor-pointer"
                                    @click="selectedCategoryFilter = category.id"
                                >
                                    <v-icon
                                        start
                                        size="13"
                                        :color="selectedCategoryFilter === category.id ? 'white' : getCategoryIconStyle(category.id).color"
                                    >
                                        {{ getCategoryIconStyle(category.id).icon }}
                                    </v-icon>
                                    {{ category.name }} ({{ category.count }})
                                </v-chip>
                                <v-chip
                                    v-if="uncategorizedCount > 0"
                                    size="small"
                                    :color="selectedCategoryFilter === 'none' ? 'grey-darken-2' : undefined"
                                    :variant="selectedCategoryFilter === 'none' ? 'flat' : 'tonal'"
                                    class="cursor-pointer"
                                    style="flex-shrink: 0;"
                                    @click="selectedCategoryFilter = 'none'"
                                >
                                    <v-icon start size="13">mdi-tag-off</v-icon>
                                    Sans catégorie ({{ uncategorizedCount }})
                                </v-chip>
                            </div>
                        </div>
                    </div>

                    <!-- Prospection status filter — single scrollable row -->
                    <div v-if="prospectionStatuses.length" class="px-4 py-3 border-b border-gray-100 bg-gray-50/40">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider shrink-0">Prospection</span>
                            <div class="flex gap-2 overflow-x-auto" style="scrollbar-width: none; -ms-overflow-style: none;">
                                <v-chip
                                    :color="selectedProspectionStatusFilter === null ? 'primary' : undefined"
                                    :variant="selectedProspectionStatusFilter === null ? 'flat' : 'tonal'"
                                    size="small"
                                    class="cursor-pointer"
                                    style="flex-shrink: 0;"
                                    @click="selectedProspectionStatusFilter = null"
                                >
                                    Tous
                                </v-chip>
                                <v-chip
                                    v-for="statusItem in prospectionStatuses"
                                    :key="statusItem.value"
                                    :color="selectedProspectionStatusFilter === statusItem.value ? statusItem.color : undefined"
                                    :variant="selectedProspectionStatusFilter === statusItem.value ? 'flat' : 'tonal'"
                                    size="small"
                                    class="cursor-pointer"
                                    style="flex-shrink: 0;"
                                    @click="selectedProspectionStatusFilter = statusItem.value"
                                >
                                    {{ statusItem.label }}
                                </v-chip>
                            </div>
                        </div>
                    </div>

                    <!-- Toolbar -->
                    <div class="px-4 py-3 border-b border-gray-100">
                        <div class="flex flex-wrap items-center gap-3">
                            <v-text-field
                                v-model="searchQuery"
                                prepend-inner-icon="mdi-magnify"
                                placeholder="Nom ou téléphone..."
                                single-line
                                hide-details
                                density="compact"
                                variant="outlined"
                                rounded="lg"
                                style="max-width: 260px;"
                                :loading="isSearching"
                                clearable
                                @update:model-value="onSearchInput"
                                @click:clear="clearSearch"
                            />

                            <div class="flex gap-1.5">
                                <v-btn
                                    :variant="showOnlyConfirmed ? 'flat' : 'tonal'"
                                    :color="showOnlyConfirmed ? 'primary' : 'default'"
                                    size="small"
                                    rounded="lg"
                                    prepend-icon="mdi-check-circle-outline"
                                    @click="showOnlyConfirmed = !showOnlyConfirmed"
                                >
                                    Confirmés
                                </v-btn>
                                <v-btn
                                    :variant="showOnlyProspects ? 'flat' : 'tonal'"
                                    :color="showOnlyProspects ? 'warning' : 'default'"
                                    size="small"
                                    rounded="lg"
                                    prepend-icon="mdi-account-question-outline"
                                    @click="showOnlyProspects = !showOnlyProspects"
                                >
                                    Prospects
                                </v-btn>
                            </div>

                            <v-select
                                v-model="selectedTagFilter"
                                :items="allTags"
                                item-title="name"
                                item-value="id"
                                placeholder="Étiquette..."
                                clearable
                                hide-details
                                density="compact"
                                variant="outlined"
                                rounded="lg"
                                style="max-width: 180px;"
                            >
                                <template v-slot:item="{ props: itemProps, item }">
                                    <v-list-item v-bind="itemProps">
                                        <template v-slot:prepend>
                                            <v-chip :color="item.raw.color" variant="flat" size="x-small" class="text-white mr-2">&nbsp;</v-chip>
                                        </template>
                                    </v-list-item>
                                </template>
                                <template v-slot:selection="{ item }">
                                    <v-chip :color="item.raw.color" variant="flat" size="x-small" class="text-white">
                                        {{ item.raw.name }}
                                    </v-chip>
                                </template>
                            </v-select>

                            <v-spacer />

                            <Link :href="route('clients.map')">
                                <v-btn
                                    variant="tonal"
                                    color="success"
                                    size="small"
                                    rounded="lg"
                                    prepend-icon="mdi-map-marker-multiple"
                                >
                                    Cartographie
                                </v-btn>
                            </Link>
                        </div>
                    </div>

                    <!-- Search mode banner -->
                    <div v-if="searchMode" class="px-4 py-2 bg-blue-50 border-b border-blue-100">
                        <v-chip color="primary" variant="tonal" size="small" closable @click:close="clearSearch">
                            Résultats pour « {{ searchQuery }} » — {{ searchResults.length }} trouvé(s)
                        </v-chip>
                    </div>

                    <!-- Table -->
                    <v-table density="comfortable" hover>
                        <thead>
                            <tr>
                                <th class="text-left py-3" style="font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #9ca3af;">
                                    Client
                                </th>
                                <th class="text-left py-3" style="font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #9ca3af;">
                                    Contact
                                </th>
                                <th class="text-left py-3" style="font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #9ca3af;">
                                    Commercial
                                </th>
                                <th class="text-left py-3" style="font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #9ca3af;">
                                    Étiquettes
                                </th>
                                <th class="text-right py-3 pr-4" style="font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #9ca3af;">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="client in displayedClients()"
                                :key="client.id"
                                class="border-b border-gray-50 transition-colors"
                            >
                                <!-- Client name + category + status -->
                                <td class="py-3 pr-4" style="min-width: 280px;">
                                    <div class="flex items-start gap-3">
                                        <div
                                            class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0 mt-0.5"
                                            :style="{ backgroundColor: getAvatarColor(client) }"
                                        >
                                            {{ getCustomerInitials(client.name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="font-semibold text-gray-900 text-sm leading-tight">{{ client.name }}</span>
                                                <v-icon
                                                    v-if="client.is_prospect"
                                                    size="13"
                                                    color="warning"
                                                    title="Prospect"
                                                >
                                                    mdi-account-question
                                                </v-icon>
                                                <v-menu v-if="client.description" location="top" :close-on-content-click="false">
                                                    <template v-slot:activator="{ props: menuProps }">
                                                        <v-icon size="13" color="blue-grey-lighten-2" class="cursor-pointer" v-bind="menuProps">
                                                            mdi-information
                                                        </v-icon>
                                                    </template>
                                                    <v-card rounded="lg" elevation="4" max-width="280">
                                                        <v-card-text class="pb-1 pt-3 px-3">
                                                            <p class="text-sm text-gray-700 leading-relaxed">{{ client.description }}</p>
                                                        </v-card-text>
                                                        <v-card-actions class="px-2 pb-2 pt-0">
                                                            <v-btn
                                                                size="x-small"
                                                                variant="text"
                                                                color="error"
                                                                prepend-icon="mdi-delete-outline"
                                                                @click="clearClientDescription(client)"
                                                            >
                                                                Effacer
                                                            </v-btn>
                                                            <v-spacer />
                                                            <v-btn
                                                                size="x-small"
                                                                variant="tonal"
                                                                color="primary"
                                                                prepend-icon="mdi-pencil-outline"
                                                                @click="openEditDialog(client)"
                                                            >
                                                                Modifier
                                                            </v-btn>
                                                        </v-card-actions>
                                                    </v-card>
                                                </v-menu>
                                            </div>
                                            <div v-if="client.category" class="flex items-center gap-1 mt-0.5">
                                                <v-icon
                                                    size="11"
                                                    :style="{ color: getCategoryIconStyle(client.category.id).color }"
                                                >
                                                    {{ getCategoryIconStyle(client.category.id).icon }}
                                                </v-icon>
                                                <span
                                                    class="text-xs"
                                                    :style="{ color: getCategoryIconStyle(client.category.id).color, fontWeight: 500 }"
                                                >
                                                    {{ client.category.name }}
                                                </span>
                                            </div>
                                            <div v-if="client.current_prospect_status" class="mt-1">
                                                <v-chip
                                                    :color="prospectionStatuses.find(s => s.value === client.current_prospect_status)?.color"
                                                    size="x-small"
                                                    variant="tonal"
                                                >
                                                    {{ prospectionStatuses.find(s => s.value === client.current_prospect_status)?.label }}
                                                </v-chip>
                                            </div>
                                            <div v-if="client.address" class="text-xs text-gray-400 mt-0.5 truncate" style="max-width: 230px;">
                                                {{ client.address }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Contact: phone + owner if different -->
                                <td class="py-3 pr-6" style="white-space: nowrap;">
                                    <div class="text-sm text-gray-800 font-medium">{{ client.phone_number }}</div>
                                    <div
                                        v-if="client.owner_number && client.owner_number !== client.phone_number"
                                        class="text-xs text-gray-400 mt-0.5"
                                    >
                                        {{ client.owner_number }}
                                    </div>
                                </td>

                                <!-- Commercial -->
                                <td class="py-3 pr-6">
                                    <span class="text-sm text-gray-600">{{ client.commercial?.name ?? '—' }}</span>
                                </td>

                                <!-- Tags — cap at 2 + overflow chip -->
                                <td class="py-3 pr-4">
                                    <div class="flex flex-wrap gap-1">
                                        <v-chip
                                            v-for="tag in (client.tags || []).slice(0, 2)"
                                            :key="tag.id"
                                            :color="tag.color"
                                            variant="flat"
                                            size="x-small"
                                            class="text-white"
                                        >
                                            {{ tag.name }}
                                        </v-chip>
                                        <v-chip
                                            v-if="(client.tags || []).length > 2"
                                            variant="tonal"
                                            size="x-small"
                                            color="grey"
                                        >
                                            +{{ client.tags.length - 2 }}
                                        </v-chip>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td class="py-3 pr-2" style="white-space: nowrap; width: 140px;">
                                    <div class="flex items-center justify-end gap-0.5">
                                        <v-btn
                                            v-if="client.is_prospect"
                                            icon="mdi-account-clock"
                                            variant="text"
                                            size="small"
                                            color="purple"
                                            title="Enregistrer une interaction"
                                            @click="openProspectionDialog(client)"
                                        />
                                        <v-btn
                                            icon="mdi-history"
                                            variant="text"
                                            size="small"
                                            color="primary"
                                            title="Historique"
                                            @click="openHistory(client)"
                                        />
                                        <v-btn
                                            icon="mdi-pencil-outline"
                                            variant="text"
                                            size="small"
                                            color="default"
                                            title="Modifier"
                                            @click="openEditDialog(client)"
                                        />
                                        <v-menu location="bottom end">
                                            <template v-slot:activator="{ props: menuProps }">
                                                <v-btn
                                                    icon="mdi-dots-vertical"
                                                    variant="text"
                                                    size="small"
                                                    color="default"
                                                    v-bind="menuProps"
                                                />
                                            </template>
                                            <v-list density="compact" min-width="180" rounded="lg" elevation="3">
                                                <v-list-item
                                                    prepend-icon="mdi-map-marker-outline"
                                                    title="Voir sur la carte"
                                                    base-color="success"
                                                    rounded="lg"
                                                    @click="openGoogleMaps(client.gps_coordinates)"
                                                />
                                                <v-divider class="my-1" />
                                                <v-list-item
                                                    prepend-icon="mdi-delete-outline"
                                                    title="Supprimer"
                                                    base-color="error"
                                                    rounded="lg"
                                                    @click="confirmDelete(client)"
                                                />
                                            </v-list>
                                        </v-menu>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="displayedClients().length === 0">
                                <td colspan="5" class="py-16 text-center">
                                    <v-icon size="48" color="grey-lighten-2" class="mb-3 block mx-auto">mdi-account-search</v-icon>
                                    <p class="text-sm text-gray-400">Aucun client trouvé</p>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>

                    <!-- Pagination -->
                    <div v-if="!searchMode && clients.last_page > 1" class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                        <span class="text-xs text-gray-400">
                            Page {{ clients.current_page }} / {{ clients.last_page }}
                        </span>
                        <v-pagination
                            v-model="clients.current_page"
                            :length="clients.last_page"
                            :total-visible="7"
                            size="small"
                            rounded="lg"
                            @update:model-value="handlePageChange"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Create Dialog ───────────────────────────────────────────────────── -->
        <v-dialog v-model="createDialogVisible" max-width="500px">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Nouveau Client</v-card-title>
                <v-card-text class="px-5">
                    <v-form @submit.prevent="submitCreate">
                        <v-text-field v-model="createForm.name" label="Nom" :error-messages="createForm.errors.name" />
                        <v-text-field v-model="createForm.phone_number" label="Téléphone" :error-messages="createForm.errors.phone_number" />
                        <v-text-field v-model="createForm.owner_number" label="Numéro Propriétaire" :error-messages="createForm.errors.owner_number" />
                        <v-text-field v-model="createForm.gps_coordinates" label="Coordonnées GPS" :error-messages="createForm.errors.gps_coordinates" />
                        <v-text-field v-model="createForm.address" label="Adresse" :error-messages="createForm.errors.address" class="mb-1" />
                        <v-select
                            v-model="createForm.commercial_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            :error-messages="createForm.errors.commercial_id"
                        />
                        <v-select
                            v-model="createForm.tag_ids"
                            :items="allTags"
                            item-title="name"
                            item-value="id"
                            label="Étiquettes"
                            multiple
                            chips
                            closable-chips
                            :error-messages="createForm.errors.tag_ids"
                        >
                            <template v-slot:chip="{ props: chipProps, item }">
                                <v-chip v-bind="chipProps" :color="item.raw.color" variant="flat" class="text-white">
                                    {{ item.raw.name }}
                                </v-chip>
                            </template>
                        </v-select>
                        <v-card-actions class="px-0 pb-0">
                            <v-spacer />
                            <v-btn variant="text" @click="createDialogVisible = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="createForm.processing" rounded="lg">Créer</v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- ─── Edit Dialog ─────────────────────────────────────────────────────── -->
        <v-dialog v-model="editDialogVisible" max-width="500px">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Modifier le Client</v-card-title>
                <v-card-text class="px-5">
                    <v-form @submit.prevent="submitEdit">
                        <v-text-field v-model="editForm.name" label="Nom" :error-messages="editForm.errors.name" />
                        <v-text-field v-model="editForm.phone_number" label="Téléphone" :error-messages="editForm.errors.phone_number" />
                        <v-text-field v-model="editForm.owner_number" label="Numéro Propriétaire" :error-messages="editForm.errors.owner_number" />
                        <v-text-field v-model="editForm.gps_coordinates" label="Coordonnées GPS" :error-messages="editForm.errors.gps_coordinates" />
                        <v-select
                            v-model="editForm.commercial_id"
                            :items="commerciaux"
                            item-title="name"
                            item-value="id"
                            label="Commercial"
                            :error-messages="editForm.errors.commercial_id"
                        />
                        <v-textarea
                            v-model="editForm.description"
                            label="Description"
                            :error-messages="editForm.errors.description"
                            rows="2"
                            auto-grow
                            placeholder="Description du client..."
                        />
                        <v-text-field v-model="editForm.address" label="Adresse" :error-messages="editForm.errors.address" class="mb-1" />
                        <v-select
                            v-model="editForm.customer_category_id"
                            :items="categoryCounts"
                            item-title="name"
                            item-value="id"
                            label="Catégorie"
                            clearable
                            :error-messages="editForm.errors.customer_category_id"
                        >
                            <template v-slot:item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps">
                                    <template v-slot:prepend>
                                        <v-icon size="18" class="mr-2" :style="{ color: getCategoryIconStyle(item.raw.id).color }">
                                            {{ getCategoryIconStyle(item.raw.id).icon }}
                                        </v-icon>
                                    </template>
                                </v-list-item>
                            </template>
                            <template v-slot:selection="{ item }">
                                <div class="flex items-center gap-1">
                                    <v-icon size="15" :style="{ color: getCategoryIconStyle(item.raw.id).color }">
                                        {{ getCategoryIconStyle(item.raw.id).icon }}
                                    </v-icon>
                                    <span :style="{ color: getCategoryIconStyle(item.raw.id).color, fontWeight: 500, fontSize: '13px' }">
                                        {{ item.raw.name }}
                                    </span>
                                </div>
                            </template>
                        </v-select>
                        <v-select
                            v-model="editForm.tag_ids"
                            :items="allTags"
                            item-title="name"
                            item-value="id"
                            label="Étiquettes"
                            multiple
                            chips
                            closable-chips
                            :error-messages="editForm.errors.tag_ids"
                        >
                            <template v-slot:chip="{ props: chipProps, item }">
                                <v-chip v-bind="chipProps" :color="item.raw.color" variant="flat" class="text-white">
                                    {{ item.raw.name }}
                                </v-chip>
                            </template>
                        </v-select>
                        <v-card-actions class="px-0 pb-0">
                            <v-spacer />
                            <v-btn variant="text" @click="editDialogVisible = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="editForm.processing" rounded="lg">Mettre à jour</v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- ─── Delete Dialog ───────────────────────────────────────────────────── -->
        <v-dialog v-model="deleteDialogVisible" max-width="420px">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5 text-base">Supprimer ce client ?</v-card-title>
                <v-card-text class="px-5">
                    <p class="text-sm text-gray-600 mb-3">Cette action est irréversible.</p>
                    <div v-if="clientToDelete" class="bg-gray-50 rounded-lg px-3 py-2.5 text-sm">
                        <div class="font-semibold text-gray-800">{{ clientToDelete.name }}</div>
                        <div class="text-gray-500 mt-0.5">{{ clientToDelete.phone_number }}</div>
                    </div>
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" :disabled="deleteForm?.processing" @click="deleteDialogVisible = false">Annuler</v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        rounded="lg"
                        :loading="deleteForm?.processing"
                        :disabled="deleteForm?.processing"
                        @click="deleteClient"
                    >
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ─── Snackbar ────────────────────────────────────────────────────────── -->
        <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="3000" rounded="lg">
            {{ snackbarText }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <CustomerHistoryDialog
            v-model="showHistory"
            :customer="selectedClient"
            :prospection-events="selectedClient?.prospection_events || []"
        />

        <!-- ─── Prospection interaction dialog ──────────────────────────────────── -->
        <v-dialog v-model="prospectionDialogVisible" max-width="480px">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5 d-flex align-center gap-2">
                    <v-icon color="purple" size="20">mdi-account-clock</v-icon>
                    Enregistrer une interaction
                </v-card-title>
                <v-card-subtitle v-if="prospectionTargetClient" class="px-5 pb-0 text-gray-500">
                    {{ prospectionTargetClient.name }}
                </v-card-subtitle>
                <v-card-text class="px-5 pt-3">
                    <v-form @submit.prevent="submitProspectionEvent">
                        <v-select
                            v-model="prospectionForm.status"
                            :items="prospectionStatuses"
                            item-title="label"
                            item-value="value"
                            label="Résultat de l'interaction"
                            :error-messages="prospectionForm.errors.status"
                            class="mb-2"
                        >
                            <template v-slot:item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps">
                                    <template v-slot:prepend>
                                        <v-icon :color="item.raw.color" size="16" class="mr-2">mdi-circle</v-icon>
                                    </template>
                                </v-list-item>
                            </template>
                            <template v-slot:selection="{ item }">
                                <v-chip :color="item.raw.color" size="small" variant="tonal">{{ item.raw.label }}</v-chip>
                            </template>
                        </v-select>
                        <v-text-field
                            v-if="statusesRequiringRevisitDate.includes(prospectionForm.status)"
                            v-model="prospectionForm.scheduled_revisit_date"
                            label="Date de revisit"
                            type="date"
                            :error-messages="prospectionForm.errors.scheduled_revisit_date"
                            class="mb-2"
                        />
                        <v-textarea
                            v-model="prospectionForm.notes"
                            label="Notes (optionnel)"
                            rows="3"
                            auto-grow
                            :error-messages="prospectionForm.errors.notes"
                        />
                    </v-form>
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="prospectionDialogVisible = false">Annuler</v-btn>
                    <v-btn
                        color="purple"
                        variant="flat"
                        rounded="lg"
                        :loading="prospectionForm.processing"
                        :disabled="!prospectionForm.status"
                        @click="submitProspectionEvent"
                    >
                        Enregistrer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>
