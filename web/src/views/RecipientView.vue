<template>
  <main class="page-shell fade-up">
    <section v-if="loading" class="loading card shimmer"></section>

    <section v-else-if="fatalError" class="card error-panel">
      <h2>Unable to load recipient</h2>
      <p class="error-text">{{ fatalError }}</p>
      <router-link to="/">Back to scanner</router-link>
    </section>

    <section v-else-if="recipient" class="recipient-grid">
      <section class="card-warm hero">
        <header>
          <h1>{{ recipient.nickname }}</h1>
          <p class="section-subtitle">{{ recipient.zone }}</p>
        </header>

        <p class="chip" v-if="recipient.verified_at">
          Verified by {{ recipient.verified_by_partner }} on {{ formatDate(recipient.verified_at) }}
        </p>

        <div class="kpi-grid">
          <article class="kpi">
            <h4>Total received</h4>
            <p>${{ (recipient.total_received_cents / 100).toFixed(2) }}</p>
          </article>
          <article class="kpi">
            <h4>Supporters</h4>
            <p>{{ recipient.supporters_count }}</p>
          </article>
          <article class="kpi">
            <h4>Support momentum</h4>
            <p>{{ supportMomentum }}</p>
          </article>
        </div>

        <div class="goal-wrap card">
          <div class="goal-header">
            <strong>Community Goal</strong>
            <span>${{ (monthlyGoalCents / 100).toFixed(0) }}/month</span>
          </div>
          <div class="goal-track">
            <div class="goal-progress" :style="{ width: `${goalPct}%` }"></div>
          </div>
          <small>{{ goalPct.toFixed(0) }}% of monthly target reached</small>
        </div>
      </section>

      <section class="card details">
        <article>
          <h2 class="section-title">Story</h2>
          <p>{{ recipient.story }}</p>
        </article>

        <article>
          <h2 class="section-title">Current Needs</h2>
          <p>{{ recipient.needs }}</p>
        </article>

        <article v-if="cacheNotice" class="cache-note card-warm">
          <h3>Offline fallback active</h3>
          <p>{{ cacheNotice }}</p>
        </article>
      </section>

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
const fatalError = ref('')
const cacheNotice = ref('')
const recipient = computed(() => recipientStore.current)
const monthlyGoalCents = 50000

const goalPct = computed(() => {
  if (!recipient.value) {
    return 0
  }

  return Math.min(100, (recipient.value.total_received_cents / monthlyGoalCents) * 100)
})

const supportMomentum = computed(() => {
  if (!recipient.value) {
    return 'new'
  }

  const supporters = recipient.value.supporters_count
  if (supporters >= 25) {
    return 'high'
  }
  if (supporters >= 10) {
    return 'steady'
  }
  return 'building'
})

async function loadRecipient() {
  fatalError.value = ''
  cacheNotice.value = ''
  loading.value = true

  const token = typeof route.query.token === 'string' ? route.query.token : ''
  const code = typeof route.query.code === 'string' ? route.query.code : ''

  if (!token && !code) {
    fatalError.value = 'No token or code provided.'
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
      cacheNotice.value = 'Network unavailable. Showing last cached profile.'
    } else {
      fatalError.value = fetchError?.message || 'Unable to load recipient.'
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
.loading {
  height: 16rem;
}

.error-panel {
  padding: 1rem;
}

.recipient-grid {
  display: grid;
  gap: 1rem;
}

.hero {
  padding: 1rem;
}

.hero h1 {
  margin: 0;
}

.goal-wrap {
  margin-top: 0.9rem;
  padding: 0.75rem;
}

.goal-header {
  display: flex;
  justify-content: space-between;
  gap: 0.8rem;
  margin-bottom: 0.5rem;
}

.goal-track {
  height: 0.65rem;
  border-radius: 999px;
  background: #ffe8d4;
  overflow: hidden;
}

.goal-progress {
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #ff8f44, #ff6b00);
}

.details {
  padding: 1rem;
  display: grid;
  gap: 0.9rem;
}

.details h2 {
  margin-bottom: 0.35rem;
}

.details p {
  margin: 0;
  color: var(--text-muted);
}

.cache-note {
  padding: 0.75rem;
}

.cache-note h3 {
  margin: 0;
}

.cache-note p {
  margin-top: 0.35rem;
}
</style>
