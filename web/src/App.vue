<template>
  <OfflineBanner />
  <RouterView />
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router'
import OfflineBanner from './components/OfflineBanner.vue'
</script>
