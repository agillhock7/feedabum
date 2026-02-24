<template>
  <main class="container">
    <section class="panel">
      <header class="heading-row">
        <div>
          <h1>Partner Dashboard</h1>
          <p v-if="partner">Signed in as {{ partner.name }} ({{ partner.email }})</p>
        </div>
        <button @click="logout">Logout</button>
      </header>

      <form class="create-form" @submit.prevent="createRecipient">
        <h2>Create Recipient</h2>
        <label>Nickname <input v-model="createForm.nickname" required /></label>
        <label>Story <textarea v-model="createForm.story" required rows="2"></textarea></label>
        <label>Needs <textarea v-model="createForm.needs" required rows="2"></textarea></label>
        <label>Zone <input v-model="createForm.zone" required /></label>
        <label class="inline"><input v-model="createForm.verified" type="checkbox" /> Verified now</label>
        <button type="submit" :disabled="busy">Create Recipient</button>
      </form>

      <p v-if="tokenNotice" class="token-notice">{{ tokenNotice }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <section v-for="item in recipients" :key="item.id" class="recipient-card">
        <header>
          <h3>{{ item.nickname }}</h3>
          <p>Code: {{ item.code_short || 'none' }} | Received: ${{ (Number(item.total_received_cents) / 100).toFixed(2) }} | Supporters: {{ item.supporters_count }}</p>
        </header>

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
          <button @click="updateRecipient(item)" :disabled="busy">Save</button>
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
.container {
  max-width: 960px;
  margin: 0 auto;
  padding: 1.2rem;
}

.panel {
  border: 1px solid #cbd5e1;
  border-radius: 1rem;
  background: #f8fafc;
  padding: 1.2rem;
}

.heading-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: start;
}

.create-form,
.recipient-card {
  margin-top: 1rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.75rem;
  padding: 0.8rem;
  background: #fff;
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
  color: #065f46;
  background: #dcfce7;
  border: 1px solid #86efac;
  border-radius: 0.6rem;
  padding: 0.6rem;
}

.error {
  color: #b91c1c;
}
</style>
