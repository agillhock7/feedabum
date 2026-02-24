<template>
  <main class="page-shell fade-up">
    <section class="card-warm shell">
      <header class="heading-row">
        <div>
          <h1>Tucson Operations Dashboard</h1>
          <p class="section-subtitle" v-if="partner">Signed in as {{ partner.name }} ({{ partner.email }})</p>
        </div>
        <button @click="logout">Logout</button>
      </header>

      <p v-if="isDemo" class="token-notice">
        Demo mode is active. This session is read-only and cannot create or modify recipients.
      </p>

      <section class="kpi-grid summary">
        <article class="kpi">
          <h4>Total recipients</h4>
          <p>{{ summary.total_recipients }}</p>
        </article>
        <article class="kpi">
          <h4>Self signups</h4>
          <p>{{ summary.self_signup_count }}</p>
        </article>
        <article class="kpi">
          <h4>New onboarding</h4>
          <p>{{ summary.new_onboarding_count }}</p>
        </article>
      </section>

      <section class="card create-form">
        <header class="row-head">
          <h2>Create Recipient (Admin Intake)</h2>
          <button type="button" @click="showCreate = !showCreate">{{ showCreate ? 'Hide' : 'Show' }}</button>
        </header>

        <form v-if="showCreate" class="form-grid" @submit.prevent="createRecipient">
          <label>Nickname <input v-model="createForm.nickname" required /></label>
          <label>Zone <input v-model="createForm.zone" required placeholder="Downtown Tucson" /></label>
          <label>City <input v-model="createForm.city" required /></label>
          <label>Contact email <input v-model="createForm.contact_email" type="email" /></label>
          <label>Contact phone <input v-model="createForm.contact_phone" /></label>

          <label class="full">Story <textarea v-model="createForm.story" required rows="2"></textarea></label>
          <label class="full">Needs <textarea v-model="createForm.needs" required rows="2"></textarea></label>

          <ZoneMapPicker v-model="createCoordinates" :height="250" :interactive="true" class="full" />

          <label class="inline"><input v-model="createForm.verified" type="checkbox" /> Mark verified now</label>
          <button class="btn-primary" type="submit" :disabled="busy || isDemo">Create Recipient</button>
        </form>
      </section>

      <p v-if="tokenNotice" class="token-notice">{{ tokenNotice }}</p>
      <p v-if="error" class="error-text">{{ error }}</p>

      <section class="manage-grid">
        <section class="card roster">
          <header class="roster-controls">
            <h2>Recipient Pipeline</h2>
            <input v-model="search" placeholder="Search name, zone, city" />
            <select v-model="filter">
              <option value="all">All</option>
              <option value="new">Onboarding new</option>
              <option value="verified">Verified</option>
              <option value="self">Self signup</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
            </select>
          </header>

          <div class="roster-list">
            <button
              v-for="item in filteredRecipients"
              :key="item.id"
              type="button"
              class="recipient-item"
              :class="{ active: selectedRecipient?.id === item.id }"
              @click="selectRecipient(item.id)"
            >
              <div>
                <strong>{{ item.nickname }}</strong>
                <p>{{ item.zone }}, {{ item.city }}</p>
              </div>
              <div class="chip-stack">
                <span class="chip">{{ item.signup_source }}</span>
                <span class="chip">{{ item.onboarding_status }}</span>
                <span class="chip">{{ item.status }}</span>
              </div>
            </button>
            <p v-if="filteredRecipients.length === 0" class="section-subtitle">No recipients match current filters.</p>
          </div>
        </section>

        <section class="card editor" v-if="selectedRecipient">
          <header class="row-head">
            <h2>Edit {{ selectedRecipient.nickname }}</h2>
            <span class="chip">ID #{{ selectedRecipient.id }}</span>
          </header>

          <div class="recipient-kpis">
            <article>
              <h4>Received</h4>
              <p>${{ (Number(selectedRecipient.total_received_cents) / 100).toFixed(2) }}</p>
            </article>
            <article>
              <h4>Supporters</h4>
              <p>{{ selectedRecipient.supporters_count }}</p>
            </article>
            <article>
              <h4>Code</h4>
              <p>{{ selectedRecipient.code_short || 'none' }}</p>
            </article>
          </div>

          <form class="form-grid" @submit.prevent="saveSelected">
            <label>Nickname <input v-model="editor.nickname" required /></label>
            <label>Zone <input v-model="editor.zone" required /></label>
            <label>City <input v-model="editor.city" required /></label>
            <label>Contact email <input v-model="editor.contact_email" type="email" /></label>
            <label>Contact phone <input v-model="editor.contact_phone" /></label>

            <label>
              Status
              <select v-model="editor.status">
                <option value="active">active</option>
                <option value="suspended">suspended</option>
              </select>
            </label>

            <label>
              Onboarding
              <select v-model="editor.onboarding_status">
                <option value="new">new</option>
                <option value="reviewed">reviewed</option>
                <option value="verified">verified</option>
              </select>
            </label>

            <label class="inline"><input v-model="editorVerified" type="checkbox" /> Verified badge</label>

            <label class="full">Story <textarea v-model="editor.story" rows="2" required></textarea></label>
            <label class="full">Needs <textarea v-model="editor.needs" rows="2" required></textarea></label>

            <ZoneMapPicker v-model="editorCoordinates" :height="240" :interactive="true" class="full" />

            <div class="actions full">
              <button class="btn-primary" :disabled="busy || isDemo" type="submit">Save Changes</button>
              <button type="button" :disabled="busy || isDemo" @click="rotateToken(selectedRecipient.id)">Rotate Token</button>
            </div>
          </form>
        </section>

        <section class="card editor empty" v-else>
          <h2>Select a recipient to edit</h2>
          <p class="section-subtitle">Choose from the pipeline list to manage onboarding and zone map data.</p>
        </section>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../services/api'
import type { AdminRecipient, Partner } from '../types'
import { useAuthStore } from '../stores/auth'
import ZoneMapPicker from '../components/ZoneMapPicker.vue'

const router = useRouter()
const auth = useAuthStore()

const recipients = ref<AdminRecipient[]>([])
const busy = ref(false)
const error = ref('')
const tokenNotice = ref('')
const search = ref('')
const filter = ref<'all' | 'new' | 'verified' | 'self' | 'active' | 'suspended'>('all')
const showCreate = ref(true)
const selectedId = ref<number | null>(null)

const summary = ref({
  total_recipients: 0,
  active_recipients: 0,
  self_signup_count: 0,
  new_onboarding_count: 0
})

const createForm = ref({
  nickname: '',
  story: '',
  needs: '',
  zone: '',
  city: 'Tucson, AZ',
  contact_email: '',
  contact_phone: '',
  verified: true
})

const createCoordinates = ref<{ lat: number | null; lng: number | null }>({
  lat: 32.2226,
  lng: -110.9747
})

const editor = reactive<{
  id: number
  nickname: string
  story: string
  needs: string
  zone: string
  city: string
  status: 'active' | 'suspended'
  onboarding_status: 'new' | 'reviewed' | 'verified'
  contact_email: string
  contact_phone: string
  verified_at: string | null
}>({
  id: 0,
  nickname: '',
  story: '',
  needs: '',
  zone: '',
  city: 'Tucson, AZ',
  status: 'active',
  onboarding_status: 'new',
  contact_email: '',
  contact_phone: '',
  verified_at: null
})

const editorCoordinates = ref<{ lat: number | null; lng: number | null }>({ lat: null, lng: null })

const partner = computed(() => auth.partner)
const isDemo = computed(() => auth.isDemo)
const selectedRecipient = computed(() => recipients.value.find((item) => item.id === selectedId.value) || null)

const filteredRecipients = computed(() => {
  const query = search.value.trim().toLowerCase()

  return recipients.value.filter((item) => {
    if (filter.value === 'new' && item.onboarding_status !== 'new') {
      return false
    }
    if (filter.value === 'verified' && !item.verified_at) {
      return false
    }
    if (filter.value === 'self' && item.signup_source !== 'self') {
      return false
    }
    if (filter.value === 'active' && item.status !== 'active') {
      return false
    }
    if (filter.value === 'suspended' && item.status !== 'suspended') {
      return false
    }

    if (!query) {
      return true
    }

    return [item.nickname, item.zone, item.city].some((field) => field.toLowerCase().includes(query))
  })
})

const editorVerified = computed({
  get: () => editor.verified_at !== null,
  set: (value: boolean) => {
    editor.verified_at = value ? new Date().toISOString() : null
    if (value) {
      editor.onboarding_status = 'verified'
    } else if (editor.onboarding_status === 'verified') {
      editor.onboarding_status = 'reviewed'
    }
  }
})

function syncEditor(recipient: AdminRecipient) {
  editor.id = recipient.id
  editor.nickname = recipient.nickname
  editor.story = recipient.story
  editor.needs = recipient.needs
  editor.zone = recipient.zone
  editor.city = recipient.city
  editor.status = recipient.status
  editor.onboarding_status = recipient.onboarding_status
  editor.contact_email = recipient.contact_email || ''
  editor.contact_phone = recipient.contact_phone || ''
  editor.verified_at = recipient.verified_at
  editorCoordinates.value = {
    lat: recipient.latitude,
    lng: recipient.longitude
  }
}

function selectRecipient(recipientId: number) {
  selectedId.value = recipientId
  const recipient = recipients.value.find((item) => item.id === recipientId)
  if (recipient) {
    syncEditor(recipient)
  }
}

async function loadRecipients() {
  busy.value = true
  error.value = ''

  try {
    const result = await api.get<{
      ok: true
      partner: Partner | null
      is_demo?: boolean
      demo_login_enabled?: boolean
      summary: {
        total_recipients: number
        active_recipients: number
        self_signup_count: number
        new_onboarding_count: number
      }
      recipients: AdminRecipient[]
    }>('/admin/recipients')

    auth.partner = result.partner
    auth.isDemo = Boolean(result.is_demo)
    if (typeof result.demo_login_enabled === 'boolean') {
      auth.demoLoginEnabled = result.demo_login_enabled
    }
    summary.value = {
      total_recipients: Number(result.summary.total_recipients || 0),
      active_recipients: Number(result.summary.active_recipients || 0),
      self_signup_count: Number(result.summary.self_signup_count || 0),
      new_onboarding_count: Number(result.summary.new_onboarding_count || 0)
    }

    recipients.value = result.recipients
    if (selectedId.value !== null) {
      const selected = result.recipients.find((item) => item.id === selectedId.value)
      if (selected) {
        syncEditor(selected)
      }
    }
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
  if (isDemo.value) {
    error.value = 'Demo mode is read-only. Log in with a non-demo admin account to create recipients.'
    return
  }

  busy.value = true
  error.value = ''
  tokenNotice.value = ''

  try {
    const result = await api.post<{ ok: true; recipient_id: number; token: string; code_short: string }>('/admin/recipient/create', {
      ...createForm.value,
      latitude: createCoordinates.value.lat,
      longitude: createCoordinates.value.lng
    })

    tokenNotice.value = `New token for recipient #${result.recipient_id}: ${result.token} | short code: ${result.code_short}`
    createForm.value = {
      nickname: '',
      story: '',
      needs: '',
      zone: '',
      city: 'Tucson, AZ',
      contact_email: '',
      contact_phone: '',
      verified: true
    }
    createCoordinates.value = { lat: 32.2226, lng: -110.9747 }
    await loadRecipients()
    selectRecipient(result.recipient_id)
  } catch (err: any) {
    error.value = err?.message || 'Unable to create recipient.'
  } finally {
    busy.value = false
  }
}

async function saveSelected() {
  if (!selectedRecipient.value) {
    return
  }

  if (isDemo.value) {
    error.value = 'Demo mode is read-only. Log in with a non-demo admin account to save changes.'
    return
  }

  busy.value = true
  error.value = ''
  tokenNotice.value = ''

  try {
    await api.post('/admin/recipient/update', {
      recipient_id: selectedRecipient.value.id,
      nickname: editor.nickname,
      story: editor.story,
      needs: editor.needs,
      zone: editor.zone,
      city: editor.city,
      status: editor.status,
      onboarding_status: editor.onboarding_status,
      verified: editor.verified_at !== null,
      contact_email: editor.contact_email || null,
      contact_phone: editor.contact_phone || null,
      latitude: editorCoordinates.value.lat,
      longitude: editorCoordinates.value.lng
    })

    await loadRecipients()
  } catch (err: any) {
    error.value = err?.message || 'Unable to update recipient.'
  } finally {
    busy.value = false
  }
}

async function rotateToken(recipientId: number) {
  if (isDemo.value) {
    error.value = 'Demo mode is read-only. Log in with a non-demo admin account to rotate tokens.'
    return
  }

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

h1,
h2 {
  margin: 0;
}

.heading-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: start;
}

.summary,
.create-form,
.manage-grid {
  margin-top: 1rem;
}

.row-head {
  display: flex;
  justify-content: space-between;
  gap: 0.8rem;
  align-items: center;
}

.create-form,
.roster,
.editor {
  padding: 0.85rem;
}

.form-grid {
  margin-top: 0.8rem;
  display: grid;
  gap: 0.65rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.form-grid .full {
  grid-column: 1 / -1;
}

label {
  display: grid;
  gap: 0.25rem;
}

.inline {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.manage-grid {
  display: grid;
  grid-template-columns: 0.85fr 1.15fr;
  gap: 1rem;
}

.roster-controls {
  display: grid;
  gap: 0.5rem;
}

.roster-list {
  margin-top: 0.75rem;
  display: grid;
  gap: 0.55rem;
}

.recipient-item {
  text-align: left;
  display: flex;
  justify-content: space-between;
  gap: 0.8rem;
  align-items: start;
}

.recipient-item p {
  margin: 0.25rem 0 0;
  color: var(--text-muted);
  font-size: 0.87rem;
}

.recipient-item.active {
  border-color: #f78833;
  background: #fff2e5;
}

.chip-stack {
  display: grid;
  gap: 0.25rem;
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

.actions {
  display: flex;
  gap: 0.6rem;
}

.empty {
  display: grid;
  place-content: center;
  min-height: 16rem;
}

.token-notice {
  color: #6c340f;
  background: #ffe9d2;
  border: 1px solid #f5bc86;
  border-radius: 0.6rem;
  padding: 0.6rem;
  margin-top: 0.8rem;
}

@media (max-width: 980px) {
  .heading-row,
  .row-head,
  .actions,
  .recipient-item {
    flex-direction: column;
    align-items: stretch;
  }

  .manage-grid,
  .form-grid,
  .recipient-kpis {
    grid-template-columns: 1fr;
  }
}
</style>
