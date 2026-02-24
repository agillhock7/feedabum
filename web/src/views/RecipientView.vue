<template>
  <main class="container">
    <section v-if="loading" class="panel">Loading recipient...</section>

    <section v-else-if="error" class="panel error">{{ error }}</section>

    <section v-else-if="recipient" class="panel">
      <header>
        <h1>{{ recipient.nickname }}</h1>
        <p>{{ recipient.zone }}</p>
      </header>

      <p class="badge" v-if="recipient.verified_at">
        Verified by {{ recipient.verified_by_partner }} on {{ formatDate(recipient.verified_at) }}
      </p>

      <p><strong>Story:</strong> {{ recipient.story }}</p>
      <p><strong>Current needs:</strong> {{ recipient.needs }}</p>

      <div class="stats">
        <article>
          <h3>Total received</h3>
          <p>${{ (recipient.total_received_cents / 100).toFixed(2) }}</p>
        </article>
        <article>
          <h3>Supporters</h3>
          <p>{{ recipient.supporters_count }}</p>
        </article>
      </div>

      <DonationForm
        :recipient-id="recipient.id"
        :recipient-name="recipient.nickname"
        @completed="goSuccess"
      />
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../services/api'
import type { RecipientProfile } from '../types'
import { useRecipientStore } from '../stores/recipient'
import DonationForm from '../components/DonationForm.vue'

const route = useRoute()
const router = useRouter()
const recipientStore = useRecipientStore()

const loading = ref(false)
const error = ref('')
const recipient = computed(() => recipientStore.current)

async function loadRecipient() {
  error.value = ''
  loading.value = true

  const token = typeof route.query.token === 'string' ? route.query.token : ''
  const code = typeof route.query.code === 'string' ? route.query.code : ''

  if (!token && !code) {
    error.value = 'No token or code provided.'
    loading.value = false
    return
  }

  try {
    const path = token
      ? `/recipient/by-token?token=${encodeURIComponent(token)}`
      : `/recipient/by-code?code=${encodeURIComponent(code)}`

    const result = await api.get<{ ok: true; recipient: RecipientProfile }>(path)
    recipientStore.setLive(result.recipient)
  } catch (fetchError: any) {
    const cached = recipientStore.loadCache()
    if (cached) {
      error.value = 'Network unavailable. Showing last cached profile.'
    } else {
      error.value = fetchError?.message || 'Unable to load recipient.'
    }
  } finally {
    loading.value = false
  }
}

function formatDate(value: string | null) {
  if (!value) {
    return 'Unknown date'
  }
  return new Date(value + 'Z').toLocaleDateString()
}

function goSuccess() {
  void router.push({ name: 'donation-success', query: { type: 'one-time' } })
}

onMounted(() => {
  void loadRecipient()
})

watch(
  () => route.fullPath,
  () => {
    void loadRecipient()
  }
)
</script>

<style scoped>
.container {
  max-width: 860px;
  margin: 0 auto;
  padding: 1.2rem;
}

.panel {
  border: 1px solid #cbd5e1;
  border-radius: 1rem;
  background: #f8fafc;
  padding: 1.2rem;
}

.badge {
  display: inline-block;
  background: #cffafe;
  color: #0f172a;
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.9rem;
}

.stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.8rem;
  margin: 1rem 0;
}

.stats article {
  border: 1px solid #bfdbfe;
  border-radius: 0.75rem;
  padding: 0.7rem;
  background: #eff6ff;
}

.error {
  color: #b91c1c;
}
</style>
