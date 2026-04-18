<template>
  <v-dialog v-model="dialog" max-width="900px">
    <v-card>
      <v-card-title class="text-h5 grey lighten-2">
        Beats - {{ sector.name }}
        <v-spacer></v-spacer>
        <v-btn color="primary" @click="showCreateDialog">
          <v-icon left>mdi-plus</v-icon>
          Nouveau beat
        </v-btn>
      </v-card-title>

      <v-card-text class="pt-4">
        <v-data-table
          :headers="headers"
          :items="beats"
          :loading="loading"
          no-data-text="Aucun beat trouvé"
          class="elevation-1"
        >
          <template v-slot:item.day_of_week_label="{ item }">
            {{ item.day_of_week_label }}
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

    <!-- Create Beat Dialog -->
    <v-dialog v-model="createDialog" max-width="500px">
      <v-card>
        <v-card-title class="text-h5 grey lighten-2">
          Nouveau beat
        </v-card-title>

        <v-card-text class="pt-4">
          <v-form ref="form" v-model="valid">
            <v-text-field
              v-model="newBatch.name"
              label="Nom du beat"
              :error-messages="errors.name"
              :rules="[v => !!v || 'Le nom est obligatoire']"
              required
            ></v-text-field>
            <v-select
              v-model="newBatch.day_of_week"
              :items="daysOfWeek"
              item-title="label"
              item-value="value"
              label="Jour de la semaine"
              :error-messages="errors.day_of_week"
              :rules="[v => !!v || 'Le jour est obligatoire']"
              required
            ></v-select>
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
            @click="createBeat"
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
      beats: [],
      commercials: [],
      errors: {},
      daysOfWeek: [
        { value: 'monday', label: 'Lundi' },
        { value: 'tuesday', label: 'Mardi' },
        { value: 'wednesday', label: 'Mercredi' },
        { value: 'thursday', label: 'Jeudi' },
        { value: 'friday', label: 'Vendredi' },
        { value: 'saturday', label: 'Samedi' },
        { value: 'sunday', label: 'Dimanche' },
      ],
      newBatch: {
        name: '',
        day_of_week: null,
        commercial_id: null
      },
      headers: [
        { text: 'Nom', value: 'name' },
        { text: 'Commercial', value: 'commercial.name' },
        { text: 'Jour', value: 'day_of_week_label' },
        { text: 'Clients', value: 'total_stops' },
        { text: 'Créé le', value: 'created_at' }
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
        this.loadBeats()
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
      if (item.cancelled_stops === item.total_stops) return 'error'
      if (item.progress_percentage > 0) return 'warning'
      return 'grey'
    },

    getStatusText(item) {
      if (item.progress_percentage === 100) return 'Terminé'
      if (item.cancelled_stops === item.total_stops) return 'Annulé'
      if (item.progress_percentage > 0) return 'En cours'
      return 'Planifié'
    },

    async loadBeats() {
      this.loading = true
      try {
        const response = await axios.get(`/sectors/${this.sector.id}/beats`)
        this.beats = response.data
      } catch (error) {
        Swal.fire({
          icon: 'error',
          title: 'Erreur',
          text: 'Impossible de charger les beats'
        })
      }
      this.loading = false
    },

    showCreateDialog() {
      this.errors = {}
      this.newBatch = {
        name: '',
        day_of_week: null,
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

    async createBeat() {
      if (!this.$refs.form.validate()) return

      this.creating = true
      this.errors = {}

      try {
        const response = await axios.post(`/sectors/${this.sector.id}/beats`, this.newBatch)
        this.beats.unshift(response.data.data)
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
            text: error.response?.data?.message || 'Impossible de créer le beat'
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