<template>
  <main class="page-shell fade-up">
    <section class="card-warm shell">
      <header class="heading-row">
        <div>
          <h1>Partner Dashboard</h1>
          <p class="section-subtitle" v-if="partner">Signed in as {{ partner.name }} ({{ partner.email }})</p>
        </div>
        <button @click="logout">Logout</button>
      </header>

      <section class="kpi-grid summary">
        <article class="kpi">
          <h4>Recipients</h4>
          <p>{{ recipients.length }}</p>
        </article>
        <article class="kpi">
          <h4>Active recipients</h4>
          <p>{{ activeCount }}</p>
        </article>
        <article class="kpi">
          <h4>Total wallet credited</h4>
          <p>${{ totalWalletUsd }}</p>
        </article>
      </section>

      <form class="card create-form" @submit.prevent="createRecipient">
        <h2>Create Recipient</h2>
        <label>Nickname <input v-model="createForm.nickname" required /></label>
        <label>Story <textarea v-model="createForm.story" required rows="2"></textarea></label>
        <label>Needs <textarea v-model="createForm.needs" required rows="2"></textarea></label>
        <label>Zone <input v-model="createForm.zone" required /></label>
        <label class="inline"><input v-model="createForm.verified" type="checkbox" /> Verified now</label>
        <button class="btn-primary" type="submit" :disabled="busy">Create Recipient</button>
      </form>

      <p v-if="tokenNotice" class="token-notice">{{ tokenNotice }}</p>
      <p v-if="error" class="error-text">{{ error }}</p>

      <section v-for="item in recipients" :key="item.id" class="card recipient-card">
        <header class="recipient-header">
          <div>
            <h3>{{ item.nickname }}</h3>
            <p class="section-subtitle">Zone: {{ item.zone }} | Code: {{ item.code_short || 'none' }}</p>
          </div>
          <span class="chip">{{ item.status }}</span>
        </header>

        <section class="recipient-kpis">
          <article>
            <h4>Received</h4>
            <p>${{ (Number(item.total_received_cents) / 100).toFixed(2) }}</p>
          </article>
          <article>
            <h4>Supporters</h4>
            <p>{{ item.supporters_count }}</p>
          </article>
          <article>
            <h4>Verified</h4>
            <p>{{ item.verified_at ? 'yes' : 'no' }}</p>
          </article>
        </section>

        <label>Nickname <input v-model="item.nickname" /></label>
        <label>Story <textarea v-model="item.story" rows="2"></textarea></label>
        <label>Needs <textarea v-model="item.needs" rows="2"></textarea></label>
        <label>Zone <input v-model="item.zone" /></label>

        <div class="controls">
          <label>
            Status
            <select v-model="item.status">
              <option value="active">active</option>
              <option value="suspended">suspended</option>
            </select>
          </label>

          <label class="inline">
            <input :checked="Boolean(item.verified_at)" type="checkbox" @change="setVerified(item, $event)" />
            Verified
          </label>
        </div>

        <div class="row-actions">
          <button class="btn-primary" @click="updateRecipient(item)" :disabled="busy">Save</button>
          <button @click="rotateToken(item.id)" :disabled="busy">Rotate Token</button>
        </div>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../services/api'
import type { AdminRecipient, Partner } from '../types'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const recipients = ref<AdminRecipient[]>([])
const busy = ref(false)
const error = ref('')
const tokenNotice = ref('')

const createForm = ref({
  nickname: '',
  story: '',
  needs: '',
  zone: '',
  verified: true
})

const partner = computed(() => auth.partner)
const activeCount = computed(() => recipients.value.filter((item) => item.status === 'active').length)
const totalWalletUsd = computed(() => {
  const cents = recipients.value.reduce((sum, item) => sum + Number(item.total_received_cents || 0), 0)
  return (cents / 100).toFixed(2)
})

async function loadRecipients() {
  busy.value = true
  error.value = ''

  try {
    const result = await api.get<{ ok: true; partner: Partner | null; recipients: AdminRecipient[] }>('/admin/recipients')
    auth.partner = result.partner
    recipients.value = result.recipients
  } catch (err: any) {
    error.value = err?.message || 'Unable to load dashboard.'
    if (err?.status === 401) {
      await router.push({ name: 'admin-login' })
    }
  } finally {
    busy.value = false
  }
}

async function createRecipient() {
  busy.value = true
  error.value = ''
  tokenNotice.value = ''

  try {
    const result = await api.post<{ ok: true; recipient_id: number; token: string; code_short: string }>('/admin/recipient/create', createForm.value)
    tokenNotice.value = `New token for recipient #${result.recipient_id}: ${result.token} | short code: ${result.code_short}`
    createForm.value = { nickname: '', story: '', needs: '', zone: '', verified: true }
    await loadRecipients()
  } catch (err: any) {
    error.value = err?.message || 'Unable to create recipient.'
  } finally {
    busy.value = false
  }
}

async function updateRecipient(item: AdminRecipient) {
  busy.value = true
  error.value = ''
  tokenNotice.value = ''

  try {
    await api.post('/admin/recipient/update', {
      recipient_id: item.id,
      nickname: item.nickname,
      story: item.story,
      needs: item.needs,
      zone: item.zone,
      status: item.status,
      verified: Boolean(item.verified_at)
    })
    await loadRecipients()
  } catch (err: any) {
    error.value = err?.message || 'Unable to update recipient.'
  } finally {
    busy.value = false
  }
}

async function rotateToken(recipientId: number) {
  busy.value = true
  error.value = ''
  tokenNotice.value = ''

  try {
    const result = await api.post<{ ok: true; token: string; code_short: string }>('/admin/recipient/rotate-token', {
      recipient_id: recipientId
    })
    tokenNotice.value = `Rotated token for recipient #${recipientId}: ${result.token} | short code: ${result.code_short}`
    await loadRecipients()
  } catch (err: any) {
    error.value = err?.message || 'Unable to rotate token.'
  } finally {
    busy.value = false
  }
}

function setVerified(item: AdminRecipient, event: Event) {
  const checked = (event.target as HTMLInputElement).checked
  item.verified_at = checked ? new Date().toISOString() : null
}

async function logout() {
  await auth.logout()
  await router.push({ name: 'admin-login' })
}

onMounted(() => {
  void loadRecipients()
})
</script>

<style scoped>
.shell {
  padding: 1rem;
}

h1 {
  margin: 0;
}

.heading-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: start;
}

.summary {
  margin-top: 0.95rem;
}

.create-form,
.recipient-card {
  margin-top: 1rem;
  padding: 0.9rem;
}

label {
  display: grid;
  gap: 0.25rem;
  margin-bottom: 0.65rem;
}

.inline {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.recipient-header {
  display: flex;
  justify-content: space-between;
  gap: 0.8rem;
  align-items: start;
}

.recipient-header h3 {
  margin: 0;
}

.recipient-kpis {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
  margin: 0.75rem 0;
}

.recipient-kpis article {
  border: 1px solid var(--line);
  border-radius: 0.75rem;
  padding: 0.55rem;
  background: #fff8ef;
}

.recipient-kpis h4 {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.79rem;
}

.recipient-kpis p {
  margin: 0.2rem 0 0;
  font-weight: 700;
}

.controls {
  display: flex;
  gap: 1rem;
  align-items: end;
}

.row-actions {
  display: flex;
  gap: 0.6rem;
}

.token-notice {
  color: #6c340f;
  background: #ffe9d2;
  border: 1px solid #f5bc86;
  border-radius: 0.6rem;
  padding: 0.6rem;
  margin-top: 0.8rem;
}

@media (max-width: 860px) {
  .heading-row,
  .controls,
  .row-actions,
  .recipient-header {
    flex-direction: column;
    align-items: stretch;
  }

  .recipient-kpis {
    grid-template-columns: 1fr;
  }
}
</style>
