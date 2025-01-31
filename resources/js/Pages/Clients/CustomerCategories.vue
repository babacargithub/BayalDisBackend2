<template>
  <AuthenticatedLayout>
    <template #header>
      <h1>Catégories de Clients</h1>
      <v-btn color="primary" @click="showCreateDialog">
        <v-icon>mdi-plus</v-icon>
        Créer une nouvelle catégorie
      </v-btn>
    </template>

    <v-card class="mx-auto mt-4">
      <v-list>
        <v-list-item v-for="category in categories" :key="category.id">
          <v-list-item-title>{{ category.name }}</v-list-item-title>
          <template v-slot:append>
            <v-btn icon="mdi-account-plus" variant="text" color="primary" @click="showAddCustomersDialog(category)" />
            <v-btn icon="mdi-eye" variant="text" @click="showCategoryDialog(category)" />
            <v-btn icon="mdi-pencil" variant="text" @click="editCategory(category)" />
            <v-btn icon="mdi-delete" variant="text" color="error" @click="deleteCategory(category.id)" />
          </template>
        </v-list-item>
      </v-list>
    </v-card>

    <v-dialog v-model="dialog" max-width="500">
      <v-card>
        <v-card-title>
          <span v-if="dialogType === 'create'">Créer une nouvelle catégorie</span>
          <span v-else-if="dialogType === 'edit'">Modifier la catégorie</span>
          <span v-else>Clients de la catégorie</span>
        </v-card-title>
        
        <v-card-text v-if="dialogType !== 'view'">
          <v-form @submit.prevent="saveCategory">
            <v-text-field
              v-model="form.name"
              label="Nom"
              required
              :error-messages="form.errors.name"
            />
            <v-text-field
              v-model="form.description"
              label="Description"
              :error-messages="form.errors.description"
            />
          </v-form>
        </v-card-text>

        <v-card-text v-else>
          <!-- Add customer list view here -->
          <div v-if="currentCategory.customers && currentCategory.customers.length">
            <v-list>
              <v-list-item v-for="customer in currentCategory.customers" :key="customer.id">
                {{ customer.name }}
              </v-list-item>
            </v-list>
          </div>
          <div v-else class="text-center pa-4">
            Aucun client dans cette catégorie
          </div>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn color="error" @click="dialog = false">Annuler</v-btn>
          <v-btn 
            v-if="dialogType !== 'view'"
            color="primary"
            @click="saveCategory"
            :loading="form.processing"
          >
            {{ dialogType === 'create' ? 'Créer' : 'Modifier' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Customers Dialog -->
    <v-dialog v-model="customersDialog" max-width="600">
      <v-card>
        <v-card-title>
          Ajouter des clients à la catégorie
        </v-card-title>
        <v-card-text>
          <v-text-field
            v-model="searchCustomers"
            label="Rechercher des clients"
            prepend-inner-icon="mdi-magnify"
            clearable
            class="mb-4"
          />
          <v-select
            v-model="selectedCustomers"
            :items="filteredCustomers"
            item-title="name"
            item-value="id"
            label="Sélectionner des clients"
            multiple
            chips
            :menu-props="{ maxHeight: 400 }"
          >
            <template v-slot:selection="{ item }">
              <v-chip>
                {{ item.raw.name }}
                <span v-if="item.raw.phone_number" class="ml-1 text-caption">
                  ({{ item.raw.phone_number }})
                </span>
              </v-chip>
            </template>
            <template v-slot:item="{ props, item }">
              <v-list-item v-bind="props">
                <template v-slot:title>
                  {{ item.raw.name }}
                </template>
                <template v-slot:subtitle>
                  {{ item.raw.phone_number }}
                </template>
              </v-list-item>
            </template>
          </v-select>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn color="error" @click="customersDialog = false">Annuler</v-btn>
          <v-btn
            color="primary"
            @click="saveCustomers"
            :loading="customersForm.processing"
          >
            Enregistrer
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    categories: {
        type: Array,
        default: () => []
    },
    customers: {
        type: Array,
        default: () => []
    }
});

const categories = ref(props.categories);
const dialog = ref(false);
const dialogType = ref('');
const currentCategory = ref({ name: '', description: '' });
const customersDialog = ref(false);
const selectedCustomers = ref([]);
const searchCustomers = ref('');

const form = useForm({
    name: '',
    description: ''
});

const customersForm = useForm({
    customer_ids: []
});

const filteredCustomers = computed(() => {
    return props.customers.filter(customer => 
        customer.name.toLowerCase().includes(searchCustomers.value.toLowerCase()) ||
        customer.phone_number?.toLowerCase().includes(searchCustomers.value.toLowerCase())
    );
});

function showCategoryDialog(category) {
    dialogType.value = 'view';
    currentCategory.value = category;
    dialog.value = true;
}

function showCreateDialog() {
    dialogType.value = 'create';
    form.reset();
    dialog.value = true;
}

function editCategory(category) {
    dialogType.value = 'edit';
    form.name = category.name;
    form.description = category.description;
    currentCategory.value = category;
    dialog.value = true;
}

function deleteCategory(categoryId) {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette catégorie ?')) {
        router.delete(route('customer-categories.destroy', categoryId), {
            onSuccess: () => {
                categories.value = categories.value.filter(c => c.id !== categoryId);
            }
        });
    }
}

function showAddCustomersDialog(category) {
    currentCategory.value = category;
    selectedCustomers.value = category.customers?.map(c => c.id) || [];
    customersDialog.value = true;
}

function saveCustomers() {
    customersForm.customer_ids = selectedCustomers.value;
    customersForm.post(route('customer-categories.add-customers', currentCategory.value.id), {
        onSuccess: () => {
            customersDialog.value = false;
            selectedCustomers.value = [];
        }
    });
}

function saveCategory() {
    if (dialogType.value === 'create') {
        form.post(route('customer-categories.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            }
        });
    } else if (dialogType.value === 'edit') {
        form.put(route('customer-categories.update', currentCategory.value.id), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
            }
        });
    }
}

</script>

<style scoped>
/* Add styles here */
</style> 