<template>
  <div v-if="!online" class="offline-banner">
    Offline mode: showing cached data when available.
  </div>
</template>

<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { useNetworkStore } from '../stores/network'

const network = useNetworkStore()
const { online } = storeToRefs(network)
</script>

<style scoped>
.offline-banner {
  position: sticky;
  top: 0;
  z-index: 20;
  background: #b91c1c;
  color: #fff;
  text-align: center;
  font-weight: 600;
  padding: 0.6rem;
}
</style>
