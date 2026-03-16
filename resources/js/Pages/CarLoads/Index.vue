<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useForm } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const props = defineProps({
    carLoads: { type: Object, required: true },
    teams: { type: Array, required: true },
    vehicles: { type: Array, required: true },
});

const showCarLoadDialog = ref(false);
const editingCarLoad = ref(null);

const form = useForm({
    name: '',
    team_id: null,
    vehicle_id: null,
    comment: '',
    return_date: null,
});

const headers = [
    { title: 'Chargement', key: 'name', minWidth: '200px' },
    { title: 'Équipe', key: 'team.name' },
    { title: 'Véhicule', key: 'vehicle' },
    { title: 'Statut', key: 'status' },
    { title: 'Actions', key: 'actions', sortable: false, align: 'end' },
];

const statusColor = (status) => {
    if (status === 'LOADING') return 'warning';
    if (status === 'SELLING') return 'success';
    if (status === 'ONGOING_INVENTORY') return 'orange';
    if (status === 'FULL_INVENTORY') return 'purple';
    return 'default';
};

const statusLabel = (status) => {
    if (status === 'LOADING') return 'En chargement';
    if (status === 'SELLING') return 'En vente';
    if (status === 'ONGOING_INVENTORY') return 'Inventaire en cours';
    if (status === 'FULL_INVENTORY') return 'Inventaire terminé';
    return 'Terminé';
};

const formatDate = (dateStr) =>
    dateStr ? new Date(dateStr).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '-';

const openCreateDialog = () => {
    editingCarLoad.value = null;
    form.reset();
    form.clearErrors();
    showCarLoadDialog.value = true;
};

const openEditDialog = (carLoad) => {
    editingCarLoad.value = carLoad;
    form.name = carLoad.name;
    form.team_id = carLoad.team_id;
    form.vehicle_id = carLoad.vehicle_id ?? null;
    form.comment = carLoad.comment || '';
    form.return_date = carLoad.return_date ? new Date(carLoad.return_date).toISOString().split('T')[0] : null;
    showCarLoadDialog.value = true;
};

const closeDialog = () => {
    showCarLoadDialog.value = false;
    editingCarLoad.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingCarLoad.value) {
        form.put(route('car-loads.update', editingCarLoad.value.id), {
            onSuccess: () => closeDialog(),
        });
    } else {
        form.post(route('car-loads.store'), {
            onSuccess: () => closeDialog(),
        });
    }
};

const deleteCarLoad = async (id) => {
    const result = await Swal.fire({
        title: 'Êtes-vous sûr?',
        text: 'Cette action est irréversible!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Oui, supprimer!',
        cancelButtonText: 'Annuler',
    });
    if (result.isConfirmed) {
        form.delete(route('car-loads.destroy', id));
    }
};
</script>

<template>
    <Head title="Chargements Véhicule" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Chargements Véhicule</h2>
        </template>

        <div class="py-4 sm:py-8">
            <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8">
                <v-card>
                    <v-card-text class="pa-2 sm:pa-4">
                        <div class="flex justify-between items-center mb-4">
                            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog">
                                <span class="hidden sm:inline">Nouveau Chargement</span>
                                <span class="sm:hidden">Nouveau</span>
                            </v-btn>
                        </div>

                        <v-data-table
                            :headers="headers"
                            :items="carLoads.data"
                            :items-per-page="100"
                            class="elevation-0"
                            density="comfortable"
                        >
                            <!-- Name cell: name + dates below -->
                            <template #item.name="{ item }">
                                <div class="py-1">
                                    <div class="font-medium">{{ item.name }}</div>
                                    <div class="text-xs text-gray-400 mt-0.5">
                                        {{ formatDate(item.load_date) }} → {{ formatDate(item.return_date) }}
                                    </div>
                                </div>
                            </template>

                            <!-- Vehicle cell -->
                            <template #item.vehicle="{ item }">
                                <span v-if="item.vehicle" class="text-body-2">
                                    {{ item.vehicle.name }}
                                    <span v-if="item.vehicle.plate_number" class="text-grey text-caption ml-1">
                                        ({{ item.vehicle.plate_number }})
                                    </span>
                                </span>
                                <span v-else class="text-grey text-caption">—</span>
                            </template>

                            <!-- Status chip -->
                            <template #item.status="{ item }">
                                <v-chip :color="statusColor(item.status)" size="small" variant="flat">
                                    {{ statusLabel(item.status) }}
                                </v-chip>
                            </template>

                            <!-- Actions -->
                            <template #item.actions="{ item }">
                                <div class="flex gap-1 justify-end">
                                    <!-- Eye: navigate to show page -->
                                    <v-tooltip text="Voir le détail">
                                        <template #activator="{ props: tooltipProps }">
                                            <v-btn
                                                v-bind="tooltipProps"
                                                :href="route('car-loads.show', item.id)"
                                                icon
                                                density="compact"
                                                variant="text"
                                                color="primary"
                                                tag="a"
                                            >
                                                <v-icon>mdi-eye</v-icon>
                                            </v-btn>
                                        </template>
                                    </v-tooltip>

                                    <template v-if="!item.inventory?.closed">
                                        <v-tooltip text="Modifier">
                                            <template #activator="{ props: tooltipProps }">
                                                <v-btn
                                                    v-bind="tooltipProps"
                                                    icon
                                                    density="compact"
                                                    variant="text"
                                                    @click="openEditDialog(item)"
                                                >
                                                    <v-icon>mdi-pencil</v-icon>
                                                </v-btn>
                                            </template>
                                        </v-tooltip>

                                        <v-tooltip text="Supprimer">
                                            <template #activator="{ props: tooltipProps }">
                                                <v-btn
                                                    v-bind="tooltipProps"
                                                    icon
                                                    density="compact"
                                                    variant="text"
                                                    color="error"
                                                    @click="deleteCarLoad(item.id)"
                                                >
                                                    <v-icon>mdi-delete</v-icon>
                                                </v-btn>
                                            </template>
                                        </v-tooltip>
                                    </template>
                                </div>
                            </template>
                        </v-data-table>
                    </v-card-text>
                </v-card>
            </div>
        </div>

        <!-- Create / Edit Dialog -->
        <v-dialog v-model="showCarLoadDialog" max-width="500" :fullscreen="$vuetify.display.xs">
            <v-card>
                <v-card-title class="pa-4">
                    {{ editingCarLoad ? 'Modifier le chargement' : 'Nouveau chargement' }}
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <v-text-field
                        v-model="form.name"
                        label="Nom"
                        :error-messages="form.errors.name"
                        class="mb-2"
                    />
                    <v-select
                        v-model="form.team_id"
                        :items="teams"
                        item-title="name"
                        item-value="id"
                        label="Équipe"
                        :error-messages="form.errors.team_id"
                        class="mb-2"
                    />
                    <v-select
                        v-model="form.vehicle_id"
                        :items="vehicles"
                        :item-title="(v) => v.plate_number ? `${v.name} (${v.plate_number})` : v.name"
                        item-value="id"
                        label="Véhicule (optionnel)"
                        clearable
                        :error-messages="form.errors.vehicle_id"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model="form.return_date"
                        type="date"
                        label="Date de retour prévue"
                        :error-messages="form.errors.return_date"
                        class="mb-2"
                    />
                    <v-textarea
                        v-model="form.comment"
                        label="Commentaire"
                        rows="2"
                        :error-messages="form.errors.comment"
                    />
                </v-card-text>
                <v-divider />
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="closeDialog">Annuler</v-btn>
                    <v-btn color="primary" variant="flat" :loading="form.processing" @click="submit">
                        {{ editingCarLoad ? 'Enregistrer' : 'Créer' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>
