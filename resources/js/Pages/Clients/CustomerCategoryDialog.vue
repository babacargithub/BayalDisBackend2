<template>
  <div v-if="visible" class="dialog">
    <h2>Clients de la catégorie</h2>
    <!-- Add list or table for displaying customers -->
    <button @click="closeDialog">Fermer</button>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const visible = ref(false);

function closeDialog() {
  visible.value = false;
}
</script>

<style scoped>
/* Add styles for dialog here */
</style> 