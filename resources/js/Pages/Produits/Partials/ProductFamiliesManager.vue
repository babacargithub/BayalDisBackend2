<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    families: { type: Array, required: true },
    products: { type: Array, required: true },
    productCategories: { type: Array, required: true },
});

const managerDialog = ref(false);
const expandedFamilyIds = ref([]);

const familyFormDialog = ref(false);
const familyBeingEdited = ref(null);
const familyForm = useForm({
    name: '',
    product_category_id: null,
});

const deleteFamilyDialog = ref(false);
const familyToDelete = ref(null);
const isDeletingFamily = ref(false);

const addProductsDialog = ref(false);
const familyReceivingProducts = ref(null);
const productSearchQuery = ref('');
const addProductsForm = useForm({
    product_ids: [],
});

const normalizeTextForSearch = (text) => {
    return String(text ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
};

const familyNameById = computed(() => {
    return Object.fromEntries(props.families.map(family => [family.id, family.name]));
});

const productsOfFamily = (family) => {
    return props.products.filter(product => product.product_family_id === family.id);
};

const toggleFamilyExpansion = (family) => {
    if (expandedFamilyIds.value.includes(family.id)) {
        expandedFamilyIds.value = expandedFamilyIds.value.filter(id => id !== family.id);
    } else {
        expandedFamilyIds.value = [...expandedFamilyIds.value, family.id];
    }
};

const openFamilyFormDialog = (family = null) => {
    familyBeingEdited.value = family;
    familyForm.clearErrors();
    familyForm.name = family?.name ?? '';
    familyForm.product_category_id = family?.product_category_id ?? null;
    familyFormDialog.value = true;
};

const submitFamilyForm = () => {
    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => {
            familyFormDialog.value = false;
            familyBeingEdited.value = null;
            familyForm.reset();
        },
    };

    if (familyBeingEdited.value) {
        familyForm.put(route('product-families.update', familyBeingEdited.value.id), requestOptions);
    } else {
        familyForm.post(route('product-families.store'), requestOptions);
    }
};

const openDeleteFamilyDialog = (family) => {
    familyToDelete.value = family;
    deleteFamilyDialog.value = true;
};

const confirmDeleteFamily = () => {
    if (!familyToDelete.value) return;
    router.delete(route('product-families.destroy', familyToDelete.value.id), {
        preserveScroll: true,
        onStart: () => { isDeletingFamily.value = true; },
        onFinish: () => { isDeletingFamily.value = false; },
        onSuccess: () => {
            deleteFamilyDialog.value = false;
            familyToDelete.value = null;
        },
    });
};

const removeProductFromFamily = (family, product) => {
    router.delete(route('product-families.remove-product', [family.id, product.id]), {
        preserveScroll: true,
    });
};

const candidateProductsForFamily = computed(() => {
    if (!familyReceivingProducts.value) return [];
    const normalizedSearchQuery = normalizeTextForSearch(productSearchQuery.value);

    return props.products.filter(product => {
        if (product.product_family_id === familyReceivingProducts.value.id) return false;
        if (!normalizedSearchQuery) return true;
        return normalizeTextForSearch(`${product.name} ${product.public_display_name ?? ''}`).includes(normalizedSearchQuery);
    });
});

const areAllCandidateProductsSelected = computed(() => {
    return candidateProductsForFamily.value.length > 0
        && candidateProductsForFamily.value.every(product => addProductsForm.product_ids.includes(product.id));
});

const toggleSelectionOfAllCandidateProducts = () => {
    const candidateProductIds = candidateProductsForFamily.value.map(product => product.id);
    if (areAllCandidateProductsSelected.value) {
        addProductsForm.product_ids = addProductsForm.product_ids.filter(id => !candidateProductIds.includes(id));
    } else {
        addProductsForm.product_ids = [...new Set([...addProductsForm.product_ids, ...candidateProductIds])];
    }
};

const openAddProductsDialog = (family) => {
    familyReceivingProducts.value = family;
    productSearchQuery.value = '';
    addProductsForm.reset();
    addProductsForm.clearErrors();
    addProductsDialog.value = true;
};

const submitAddProducts = () => {
    addProductsForm.post(route('product-families.add-products', familyReceivingProducts.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            addProductsDialog.value = false;
            familyReceivingProducts.value = null;
            addProductsForm.reset();
        },
    });
};
</script>

<template>
    <div>
        <v-btn
            variant="outlined"
            size="small"
            prepend-icon="mdi-shape-outline"
            @click="managerDialog = true"
        >
            Familles
        </v-btn>

        <!-- Families list -->
        <v-dialog v-model="managerDialog" max-width="900px">
            <v-card>
                <v-card-title class="d-flex align-center justify-space-between">
                    <span class="text-h5">Familles de produits</span>
                    <v-btn color="primary" prepend-icon="mdi-plus" size="small" @click="openFamilyFormDialog()">
                        Nouvelle famille
                    </v-btn>
                </v-card-title>

                <v-card-text>
                    <v-alert v-if="families.length === 0" type="info" variant="tonal">
                        Aucune famille pour le moment. Créez-en une, puis ajoutez-y des produits.
                    </v-alert>

                    <v-table v-else density="comfortable">
                        <thead>
                            <tr>
                                <th>Famille</th>
                                <th>Catégorie</th>
                                <th class="text-center">Produits</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="family in families" :key="family.id">
                                <tr>
                                    <td>
                                        <v-btn
                                            variant="text"
                                            size="small"
                                            :icon="expandedFamilyIds.includes(family.id) ? 'mdi-chevron-down' : 'mdi-chevron-right'"
                                            :title="'Voir les produits de la famille'"
                                            @click="toggleFamilyExpansion(family)"
                                        />
                                        <span class="font-weight-medium">{{ family.name }}</span>
                                    </td>
                                    <td>{{ family.category_name ?? '—' }}</td>
                                    <td class="text-center">{{ family.products_count }}</td>
                                    <td class="text-right text-no-wrap">
                                        <v-btn
                                            icon="mdi-playlist-plus"
                                            variant="text"
                                            color="info"
                                            :title="'Ajouter des produits'"
                                            @click="openAddProductsDialog(family)"
                                        />
                                        <v-btn
                                            icon="mdi-pencil"
                                            variant="text"
                                            color="primary"
                                            :title="'Modifier la famille'"
                                            @click="openFamilyFormDialog(family)"
                                        />
                                        <v-btn
                                            icon="mdi-delete"
                                            variant="text"
                                            color="error"
                                            :title="'Supprimer la famille'"
                                            @click="openDeleteFamilyDialog(family)"
                                        />
                                    </td>
                                </tr>
                                <tr v-if="expandedFamilyIds.includes(family.id)">
                                    <td colspan="4" class="bg-grey-lighten-5">
                                        <div v-if="productsOfFamily(family).length === 0" class="text-medium-emphasis py-2">
                                            Aucun produit dans cette famille.
                                        </div>
                                        <div v-else class="d-flex flex-wrap ga-2 py-2">
                                            <v-chip
                                                v-for="product in productsOfFamily(family)"
                                                :key="product.id"
                                                size="small"
                                                closable
                                                @click:close="removeProductFromFamily(family, product)"
                                            >
                                                {{ product.name }}
                                            </v-chip>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </v-table>
                </v-card-text>

                <v-card-actions>
                    <v-spacer />
                    <v-btn color="primary" variant="text" @click="managerDialog = false">Fermer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Create / edit family -->
        <v-dialog v-model="familyFormDialog" max-width="500px">
            <v-card>
                <v-card-title>{{ familyBeingEdited ? 'Modifier la famille' : 'Nouvelle famille' }}</v-card-title>
                <v-card-text>
                    <v-form @submit.prevent="submitFamilyForm">
                        <v-text-field
                            v-model="familyForm.name"
                            label="Nom de la famille"
                            placeholder="Ex : Tasse à jeter"
                            autofocus
                            :error-messages="familyForm.errors.name"
                        />
                        <v-select
                            v-model="familyForm.product_category_id"
                            :items="productCategories"
                            item-title="name"
                            item-value="id"
                            label="Catégorie (optionnel)"
                            clearable
                            :error-messages="familyForm.errors.product_category_id"
                        />
                        <v-card-actions>
                            <v-spacer />
                            <v-btn color="error" variant="text" @click="familyFormDialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="familyForm.processing">
                                {{ familyBeingEdited ? 'Mettre à jour' : 'Créer' }}
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Delete family -->
        <v-dialog v-model="deleteFamilyDialog" max-width="450px">
            <v-card>
                <v-card-title class="text-h5">Supprimer la famille</v-card-title>
                <v-card-text>
                    Supprimer la famille <strong>{{ familyToDelete?.name }}</strong> ?
                    Ses {{ familyToDelete?.products_count ?? 0 }} produit(s) ne seront pas supprimés,
                    ils n'auront simplement plus de famille.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="isDeletingFamily" @click="deleteFamilyDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="text" :loading="isDeletingFamily" @click="confirmDeleteFamily">
                        Supprimer
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bulk add products -->
        <v-dialog v-model="addProductsDialog" max-width="600px" scrollable>
            <v-card>
                <v-card-title>Ajouter des produits à « {{ familyReceivingProducts?.name }} »</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="productSearchQuery"
                        label="Rechercher un produit"
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        hide-details
                        density="compact"
                        class="mb-2"
                    />
                    <div class="d-flex align-center justify-space-between mb-1">
                        <v-btn
                            size="small"
                            variant="text"
                            :disabled="candidateProductsForFamily.length === 0"
                            @click="toggleSelectionOfAllCandidateProducts"
                        >
                            {{ areAllCandidateProductsSelected ? 'Tout désélectionner' : 'Tout sélectionner' }}
                        </v-btn>
                        <span class="text-caption text-medium-emphasis">
                            {{ addProductsForm.product_ids.length }} sélectionné(s)
                        </span>
                    </div>

                    <v-alert v-if="addProductsForm.errors.product_ids" type="error" variant="tonal" density="compact" class="mb-2">
                        {{ addProductsForm.errors.product_ids }}
                    </v-alert>

                    <div v-if="candidateProductsForFamily.length === 0" class="text-medium-emphasis py-4 text-center">
                        Aucun produit à ajouter.
                    </div>
                    <v-checkbox
                        v-for="product in candidateProductsForFamily"
                        :key="product.id"
                        v-model="addProductsForm.product_ids"
                        :value="product.id"
                        density="compact"
                        hide-details
                    >
                        <template #label>
                            <span>{{ product.name }}</span>
                            <v-chip
                                v-if="product.product_family_id"
                                size="x-small"
                                class="ml-2"
                                color="orange"
                                variant="tonal"
                            >
                                déjà dans : {{ familyNameById[product.product_family_id] }}
                            </v-chip>
                        </template>
                    </v-checkbox>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" color="error" @click="addProductsDialog = false">Annuler</v-btn>
                    <v-btn
                        color="primary"
                        :loading="addProductsForm.processing"
                        :disabled="addProductsForm.product_ids.length === 0"
                        @click="submitAddProducts"
                    >
                        Ajouter ({{ addProductsForm.product_ids.length }})
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
