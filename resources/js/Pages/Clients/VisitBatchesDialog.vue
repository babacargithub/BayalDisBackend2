<template>
  <v-dialog v-model="dialog" max-width="900px">
    <v-card>
      <v-card-title class="text-h5 grey lighten-2">
        Lots de visite - {{ sector.name }}
        <v-spacer></v-spacer>
        <v-btn color="primary" @click="showCreateDialog">
          <v-icon left>mdi-plus</v-icon>
         Nouvelle visite
        </v-btn>
      </v-card-title>

      <v-card-text class="pt-4">
        <v-data-table
          :headers="headers"
          :items="visitBatches"
          :loading="loading"
          no-data-text="Aucun lot de visite trouvé"
          class="elevation-1"
        >
          <template v-slot:item.progress_percentage="{ item }">
            <v-progress-linear
              :value="item.progress_percentage"
              height="20"
              :color="getProgressColor(item.progress_percentage)"
            >
              <template v-slot:default="{ value }">
                <strong>{{ value }}%</strong>
              </template>
            </v-progress-linear>
          </template>

          <template v-slot:item.visit_date="{ item }">
            {{ formatDate(item.visit_date) }}
          </template>

          <template v-slot:item.created_at="{ item }">
            {{ formatDate(item.created_at) }}
          </template>

          <template v-slot:item.status="{ item }">
            <v-chip
              :color="getStatusColor(item)"
              small
            >
              {{ getStatusText(item) }}
            </v-chip>
          </template>
        </v-data-table>
      </v-card-text>

      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn color="grey" text @click="dialog = false">
          Fermer
        </v-btn>
      </v-card-actions>
    </v-card>

    <!-- Create Visit Batch Dialog -->
    <v-dialog v-model="createDialog" max-width="500px">
      <v-card>
        <v-card-title class="text-h5 grey lighten-2">
          Nouveau lot de visite
        </v-card-title>

        <v-card-text class="pt-4">
          <v-form ref="form" v-model="valid">
            <v-text-field
              v-model="newBatch.name"
              label="Nom du lot"
              :error-messages="errors.name"
              :rules="[v => !!v || 'Le nom est obligatoire']"
              required
            ></v-text-field>
            <v-text-field
              v-model="newBatch.visit_date"
              label="Date de visite"
              type="date"
              :error-messages="errors.visit_date"
              :rules="[v => !!v || 'La date est obligatoire']"
              required
            ></v-text-field>

          

            <v-select
              v-model="newBatch.commercial_id"
              :items="commercials"
              item-title="name"
              item-value="id"
              label="Commercial"
              :error-messages="errors.commercial_id"
              :rules="[v => !!v || 'Le commercial est obligatoire']"
              :loading="loadingCommercials"
              required
            ></v-select>
          </v-form>
        </v-card-text>

        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="grey" text @click="createDialog = false">
            Annuler
          </v-btn>
          <v-btn
            color="primary"
            :loading="creating"
            :disabled="!valid || creating"
            @click="createVisitBatch"
          >
            Créer
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script>
import Swal from 'sweetalert2'

export default {
  props: {
    modelValue: Boolean,
    sector: {
      type: Object,
      required: true
    }
  },

  data() {
    return {
      dialog: this.modelValue,
      createDialog: false,
      dateMenu: false,
      loading: false,
      loadingCommercials: false,
      creating: false,
      valid: false,
      visitBatches: [],
      commercials: [],
      errors: {},
      newBatch: {
        name: '',
        visit_date: '',
        commercial_id: null
      },
      headers: [
        { text: 'Nom', value: 'name' },
        { text: 'Commercial', value: 'commercial.name' },
        { text: 'Date de visite', value: 'visit_date' },
        { text: 'Progrès', value: 'progress_percentage' },
        { text: 'Visites totales', value: 'total_visits' },
        { text: 'Complétées', value: 'completed_visits' },
        { text: 'Annulées', value: 'cancelled_visits' },
        { text: 'En attente', value: 'pending_visits' },
        { text: 'Créé le', value: 'created_at' },
        { text: 'Statut', value: 'status' }
      ]
    }
  },

  watch: {
    modelValue(val) {
      this.dialog = val
    },
    dialog(val) {
      this.$emit('update:modelValue', val)
      if (val) {
        this.loadVisitBatches()
      }
    },
    createDialog(val) {
      if (val && this.commercials.length === 0) {
        this.loadCommercials()
      }
    }
  },

  methods: {
    formatDate(date) {
      return new Date(date).toLocaleDateString('fr-FR')
    },

    getProgressColor(percentage) {
      if (percentage >= 75) return 'success'
      if (percentage >= 50) return 'primary'
      if (percentage >= 25) return 'warning'
      return 'error'
    },

    getStatusColor(item) {
      if (item.progress_percentage === 100) return 'success'
      if (item.cancelled_visits === item.total_visits) return 'error'
      if (item.progress_percentage > 0) return 'warning'
      return 'grey'
    },

    getStatusText(item) {
      if (item.progress_percentage === 100) return 'Terminé'
      if (item.cancelled_visits === item.total_visits) return 'Annulé'
      if (item.progress_percentage > 0) return 'En cours'
      return 'Planifié'
    },

    async loadVisitBatches() {
      this.loading = true
      try {
        const response = await axios.get(`/sectors/${this.sector.id}/visit-batches`)
        this.visitBatches = response.data
      } catch (error) {
        Swal.fire({
          icon: 'error',
          title: 'Erreur',
          text: 'Impossible de charger les lots de visite'
        })
      }
      this.loading = false
    },

    showCreateDialog() {
      this.errors = {}
      this.newBatch = {
        name: '',
        visit_date: new Date().toISOString().substr(0, 10),
        commercial_id: null
      }
      this.createDialog = true
    },

    async loadCommercials() {
      this.loadingCommercials = true
      try {
        const response = await axios.get('/commercials', {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        })
        this.commercials = response.data
        console.log('Loaded commercials:', this.commercials)
      } catch (error) {
        console.error('Error loading commercials:', error)
        Swal.fire({
          icon: 'error',
          title: 'Erreur',
          text: 'Impossible de charger la liste des commerciaux'
        })
      }
      this.loadingCommercials = false
    },

    async createVisitBatch() {
      if (!this.$refs.form.validate()) return

      this.creating = true
      this.errors = {}

      try {
        const response = await axios.post(`/sectors/${this.sector.id}/visit-batches`, this.newBatch)
        this.visitBatches.unshift(response.data.data)
        this.createDialog = false
        Swal.fire({
          icon: 'success',
          title: 'Succès',
          text: response.data.message
        })
      } catch (error) {
        if (error.response?.status === 422) {
          this.errors = error.response.data.errors
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Erreur',
            text: error.response?.data?.message || 'Impossible de créer le lot de visite'
          })
        }
      }
      this.creating = false
    }
  },

  async created() {
    // Remove the commercials loading from created hook
    // It will be loaded when the create dialog is opened
  }
}
</script> 