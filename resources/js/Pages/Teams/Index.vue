<template>
  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Gestion des équipes
      </h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900">
            <v-card>
              <v-card-title class="d-flex justify-space-between align-center">
                <span>Liste des équipes</span>
                <v-btn color="primary" @click="openCreateDialog">
                  <v-icon>mdi-plus</v-icon>
                  Nouvelle équipe
                </v-btn>
              </v-card-title>

              <v-data-table
                  :headers="headers"
                  :items="teams.data"
                  :items-per-page="10"
                  class="elevation-1"
              >
                <template v-slot:item.actions="{ item }">

                  <div class="flex justify-end">
                    <v-icon color="indigo" size="20px" @click="openEditDialog(item)">mdi-pencil</v-icon>

                    <v-icon color="green" size="20px" @click="openCommercialsDialog(item)">mdi-account-group</v-icon>
                    <v-icon color="red" size="20px" @click="confirmDelete(item)">mdi-delete</v-icon>
                  </div>

                </template>
              </v-data-table>
            </v-card>

            <!-- Create/Edit Dialog -->
            <v-dialog v-model="dialog" max-width="500px">
              <v-card>
                <v-card-title>
                  <span>{{ formTitle }}</span>
                </v-card-title>

                <v-card-text>
                  <v-form @submit.prevent="save">
                    <v-text-field
                        v-model="form.name"
                        label="Nom de l'équipe"
                        required
                        :error-messages="form.errors.name"
                        variant="outlined"
                        color="primary"
                    ></v-text-field>
                    <v-select
                        v-model="form.user_id"
                        :items="users"
                        item-title="name"
                        item-value="id"
                        label="Manager"
                        required
                        :error-messages="form.errors.user_id"
                        variant="outlined"
                        color="primary"
                    ></v-select>
                  </v-form>
                </v-card-text>

                <v-card-actions>
                  <v-spacer></v-spacer>
                  <v-btn color="error" text @click="closeDialog">Annuler</v-btn>
                  <v-btn color="primary" text @click="save">Enregistrer</v-btn>
                </v-card-actions>
              </v-card>
            </v-dialog>

            <!-- Commercials Dialog -->
            <v-dialog v-model="commercialsDialog" max-width="800px">
              <v-card>
                <v-card-title>
                  <span>Commerciaux de l'équipe {{ selectedTeam?.name }}</span>
                </v-card-title>

                <v-card-text>
                  <div class="mb-4">
                    <v-select
                        v-model="selectedCommercial"
                        :items="availableCommercials"
                        item-title="name"
                        item-value="id"
                        label="Ajouter un commercial"
                        :error-messages="commercialForm.errors.commercial_id"
                        variant="outlined"
                        color="primary"
                    ></v-select>
                    <v-btn
                        color="primary"
                        @click="addCommercial"
                        :disabled="!selectedCommercial"
                        class="mt-2"
                    >
                      Ajouter
                    </v-btn>
                  </div>

                  <v-data-table
                      :headers="commercialHeaders"
                      :items="selectedTeam?.commercials || []"
                      class="elevation-1"
                  >
                    <template v-slot:item.actions="{ item }">
                      <v-btn
                          icon
                          small
                          color="error"
                          @click="removeCommercial(item)"
                      >
                        <v-icon>mdi-delete</v-icon>
                      </v-btn>
                    </template>
                  </v-data-table>
                </v-card-text>

                <v-card-actions>
                  <v-spacer></v-spacer>
                  <v-btn color="primary" text @click="commercialsDialog = false">Fermer</v-btn>
                </v-card-actions>
              </v-card>
            </v-dialog>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'

export default {
  components: {
    AuthenticatedLayout
  },

  props: {
    teams: Object,
    users: Array,
    commercials: Array
  },

  setup(props) {
    const dialog = ref(false)
    const commercialsDialog = ref(false)
    const editedIndex = ref(-1)
    const selectedTeam = ref(null)
    const selectedCommercial = ref(null)

    const headers = [
      { text: 'Nom', value: 'name' },
      { text: 'Manager', value: 'manager.name' },
      { text: 'Actions', value: 'actions', sortable: false }
    ]

    const commercialHeaders = [
      { text: 'Nom', value: 'name' },
      { text: 'Email', value: 'email' },
      { text: 'Actions', value: 'actions', sortable: false }
    ]

    const teamForm = useForm({
      name: '',
      user_id: null
    })

    const commercialForm = useForm({
      commercial_id: null
    })

    const formTitle = computed(() => {
      return editedIndex.value === -1 ? 'Nouvelle équipe' : 'Modifier l\'équipe'
    })

    const availableCommercials = computed(() => {
      return props.commercials.filter(c => !c.team_id || c.team_id === selectedTeam.value?.id)
    })

    function openCreateDialog() {
      editedIndex.value = -1
      teamForm.reset()
      teamForm.clearErrors()
      dialog.value = true
    }

    function openEditDialog(item) {
      editedIndex.value = props.teams.data.indexOf(item)
      teamForm.clearErrors()
      teamForm.name = item.name
      teamForm.user_id = item.user_id
      dialog.value = true
    }

    function openCommercialsDialog(team) {
      selectedTeam.value = team
      commercialForm.reset()
      commercialForm.clearErrors()
      commercialsDialog.value = true
    }

    function closeDialog() {
      dialog.value = false
      editedIndex.value = -1
      teamForm.reset()
      teamForm.clearErrors()
    }

    function save() {
      if (editedIndex.value > -1) {
        teamForm.put(route('teams.update', props.teams.data[editedIndex.value].id), {
          preserveScroll: true,
          onSuccess: () => {
            closeDialog()
          }
        })
      } else {
        teamForm.post(route('teams.store'), {
          preserveScroll: true,
          onSuccess: () => {
            closeDialog()
          }
        })
      }
    }

    function confirmDelete(item) {
      Swal.fire({
        title: 'Êtes-vous sûr?',
        text: "Cette action est irréversible!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Oui, supprimer!',
        cancelButtonText: 'Annuler'
      }).then((result) => {
        if (result.isConfirmed) {
          teamForm.delete(route('teams.destroy', item.id))
        }
      })
    }

    function addCommercial() {
      if (selectedTeam.value) {
        commercialForm.commercial_id = selectedCommercial.value
        commercialForm.post(route('teams.add-commercial', selectedTeam.value.id), {
          preserveScroll: true,
          onSuccess: () => {
            selectedCommercial.value = null
            commercialForm.reset()
          }
        })
      }
    }

    function removeCommercial(commercial) {
      if (selectedTeam.value) {
        commercialForm.commercial_id = commercial.id
        commercialForm.post(route('teams.remove-commercial', selectedTeam.value.id), {
          preserveScroll: true
        })
      }
    }

    return {
      dialog,
      commercialsDialog,
      headers,
      commercialHeaders,
      form: teamForm,
      commercialForm,
      formTitle,
      selectedTeam,
      selectedCommercial,
      availableCommercials,
      openCreateDialog,
      openEditDialog,
      openCommercialsDialog,
      closeDialog,
      save,
      confirmDelete,
      addCommercial,
      removeCommercial
    }
  }
}
</script> 