<template>
  <main class="page-shell fade-up">
    <section class="card-warm wrap">
      <header>
        <img src="/branding/fab-wordmark.svg" alt="Feed A Bum" class="wordmark" />
        <h1>Self Sign-Up (Tucson Launch)</h1>
        <p class="section-subtitle">
          Sign up free, set your zone on the map, and get your QR donation code instantly.
        </p>
        <p class="chip">Backed by Dark Horses USA for initial rollout</p>
      </header>

      <form v-if="!result" class="form" @submit.prevent="submit">
        <label>
          Display name or nickname
          <input v-model="form.nickname" required maxlength="120" placeholder="Coach Ray" />
        </label>

        <label>
          Your current story
          <textarea v-model="form.story" required rows="3" placeholder="Tell donors who you are and what you're working toward."></textarea>
        </label>

        <label>
          Current needs
          <textarea v-model="form.needs" required rows="3" placeholder="Food, transport, hygiene supplies, etc."></textarea>
        </label>

        <div class="zone-grid">
          <label>
            Zone / neighborhood
            <input v-model="form.zone" required maxlength="120" placeholder="Downtown Tucson" />
          </label>

          <label>
            City
            <input v-model="form.city" required maxlength="120" />
          </label>
        </div>

        <ZoneMapPicker v-model="coordinates" :height="320" :interactive="true" />

        <div class="contact-grid">
          <label>
            Account email
            <input v-model="form.contact_email" type="email" maxlength="191" placeholder="you@example.com" required />
          </label>

          <label>
            Account password
            <input v-model="form.account_password" type="password" minlength="8" placeholder="At least 8 characters" required />
          </label>

          <label>
            Contact phone (optional)
            <input v-model="form.contact_phone" maxlength="40" placeholder="520-555-0101" />
          </label>
        </div>

        <p class="section-subtitle">
          Signup creates an active profile with onboarding status <strong>new</strong>. Partner verification can be added later in-person.
        </p>

        <button class="btn-primary" type="submit" :disabled="busy">Create My Profile + QR</button>
        <p v-if="error" class="error-text">{{ error }}</p>
      </form>

      <section v-else class="result card fade-up">
        <h2>You're live in Feed a Bum</h2>
        <p class="section-subtitle">Your member account and recipient profile were created. Share your code or QR so local donors can support you directly.</p>

        <div class="kpi-grid">
          <article class="kpi">
            <h4>Short code</h4>
            <p>{{ result.code_short }}</p>
          </article>
          <article class="kpi">
            <h4>Onboarding</h4>
            <p>{{ result.onboarding_status }}</p>
          </article>
          <article class="kpi">
            <h4>Recipient ID</h4>
            <p>#{{ result.recipient_id }}</p>
          </article>
        </div>

        <div class="qr-wrap" v-if="qrDataUrl">
          <img :src="qrDataUrl" alt="Recipient QR code" />
        </div>

        <label>
          Donation link
          <input :value="result.recipient_url" readonly />
        </label>

        <label>
          Raw token (keep private)
          <input :value="result.token" readonly />
        </label>

        <div class="result-actions">
          <button class="btn-primary" type="button" @click="copy(result.recipient_url)">Copy Donation Link</button>
          <button type="button" @click="openRecipient">Open Public Profile</button>
        </div>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import QRCode from 'qrcode'
import { api } from '../services/api'
import ZoneMapPicker from '../components/ZoneMapPicker.vue'

const router = useRouter()

const form = reactive({
  nickname: '',
  story: '',
  needs: '',
  zone: '',
  city: 'Tucson, AZ',
  contact_email: '',
  account_password: '',
  contact_phone: ''
})

const coordinates = ref<{ lat: number | null; lng: number | null }>({
  lat: 32.2226,
  lng: -110.9747
})

const busy = ref(false)
const error = ref('')
const qrDataUrl = ref('')
const result = ref<{
  recipient_id: number
  onboarding_status: 'new' | 'reviewed' | 'verified'
  token: string
  code_short: string
  recipient_url: string
} | null>(null)

async function submit() {
  busy.value = true
  error.value = ''

  try {
    const response = await api.post<{
      ok: true
      recipient_id: number
      onboarding_status: 'new' | 'reviewed' | 'verified'
      token: string
      code_short: string
      recipient_url: string
    }>('/recipient/signup', {
      ...form,
      latitude: coordinates.value.lat,
      longitude: coordinates.value.lng
    })

    result.value = {
      recipient_id: response.recipient_id,
      onboarding_status: response.onboarding_status,
      token: response.token,
      code_short: response.code_short,
      recipient_url: response.recipient_url
    }

    qrDataUrl.value = await QRCode.toDataURL(response.recipient_url, {
      width: 260,
      margin: 2,
      color: {
        dark: '#2f1b0c',
        light: '#fff7ef'
      }
    })
  } catch (err: any) {
    error.value = err?.message || 'Unable to complete signup.'
  } finally {
    busy.value = false
  }
}

async function copy(value: string) {
  await navigator.clipboard.writeText(value)
}

function openRecipient() {
  if (!result.value) {
    return
  }

  const token = result.value.token
  void router.push({ name: 'recipient', query: { token } })
}
</script>

<style scoped>
.wrap {
  padding: 1.1rem;
}

.wordmark {
  width: min(100%, 340px);
  border-radius: 0.55rem;
  margin-bottom: 0.65rem;
}

h1 {
  margin: 0;
}

.form {
  margin-top: 0.9rem;
  display: grid;
  gap: 0.8rem;
}

label {
  display: grid;
  gap: 0.3rem;
}

.zone-grid,
.contact-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.result {
  margin-top: 1rem;
  padding: 1rem;
}

.result h2 {
  margin-top: 0;
}

.qr-wrap {
  margin: 0.9rem 0;
  display: inline-flex;
  border: 1px solid var(--line);
  border-radius: 0.8rem;
  background: #fff;
  padding: 0.5rem;
}

.qr-wrap img {
  width: 220px;
  height: 220px;
}

.result-actions {
  display: flex;
  gap: 0.6rem;
}

@media (max-width: 860px) {
  .zone-grid,
  .contact-grid,
  .result-actions {
    grid-template-columns: 1fr;
    display: grid;
  }
}
</style>
