<template>
  <main class="page-shell fade-up">
    <section class="hero card-warm">
      <div>
        <h1>Feed a Bum</h1>
        <p class="section-subtitle">
          Hyperlocal micro-giving for verified neighbors. Scan, verify, and donate in under a minute.
        </p>

        <div class="chip-row">
          <span class="chip">Partner-verified recipients</span>
          <span class="chip">Stripe secure payments</span>
          <span class="chip">QR + short code access</span>
        </div>

        <div class="actions">
          <button class="btn-primary" type="button" @click="focusCodeInput">Enter code now</button>
          <router-link class="signup-link" to="/signup">Need support? Self sign-up</router-link>
          <router-link class="admin-link" to="/admin/login">Partner admin</router-link>
        </div>
      </div>

      <aside class="kpi-grid">
        <article class="kpi">
          <h4>Average donation</h4>
          <p>$5.30</p>
        </article>
        <article class="kpi">
          <h4>Scan to donate</h4>
          <p>&lt; 60 sec</p>
        </article>
        <article class="kpi">
          <h4>Support model</h4>
          <p>One-time + recurring</p>
        </article>
      </aside>
    </section>

    <section class="grid-layout">
      <section class="card scanner-wrap">
        <h2 class="section-title">Scan Badge QR</h2>
        <p class="section-subtitle">Use camera to detect recipient token/code directly from partner badges.</p>
        <QrScanner @scanned="handleScanned" />
      </section>

      <section class="card form-wrap">
        <h2 class="section-title">Manual Code Entry</h2>
        <p class="section-subtitle">Fallback for low light or old devices.</p>

        <form class="code-form" @submit.prevent="submitCode">
          <label>
            Recipient code
            <input
              ref="codeInputRef"
              v-model="manualCode"
              placeholder="FAB1234"
              maxlength="12"
              autocomplete="off"
              required
            />
          </label>
          <button class="btn-primary" type="submit">Find Recipient</button>
        </form>

        <div class="steps">
          <h3>How it works</h3>
          <ol>
            <li>Scan a partner-issued QR badge.</li>
            <li>Review recipient verification and current needs.</li>
            <li>Donate one-time or set recurring support.</li>
          </ol>
        </div>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import QrScanner from '../components/QrScanner.vue'

const router = useRouter()
const manualCode = ref('')
const codeInputRef = ref<HTMLInputElement | null>(null)

function navigateByPayload(payload: string) {
  const cleaned = payload.trim()
  if (!cleaned) {
    return
  }

  try {
    const url = new URL(cleaned)
    const token = url.searchParams.get('token')
    const code = url.searchParams.get('code')
    if (token) {
      void router.push({ name: 'recipient', query: { token } })
      return
    }
    if (code) {
      void router.push({ name: 'recipient', query: { code } })
      return
    }
  } catch {
    // Not a URL; continue fallback parse.
  }

  if (/^[A-Za-z0-9]{4,80}$/.test(cleaned)) {
    if (cleaned.length <= 8) {
      void router.push({ name: 'recipient', query: { code: cleaned.toUpperCase() } })
      return
    }

    void router.push({ name: 'recipient', query: { token: cleaned } })
  }
}

function handleScanned(value: string) {
  navigateByPayload(value)
}

function submitCode() {
  navigateByPayload(manualCode.value)
}

function focusCodeInput() {
  codeInputRef.value?.focus()
}
</script>

<style scoped>
.hero {
  padding: 1.3rem;
  display: grid;
  grid-template-columns: 1.15fr 1fr;
  gap: 1rem;
  margin-bottom: 1rem;
}

h1 {
  margin: 0;
  font-size: clamp(1.8rem, 2vw, 2.3rem);
  line-height: 1.1;
}

.chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.8rem;
}

.actions {
  margin-top: 1rem;
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.admin-link {
  font-weight: 600;
}

.signup-link {
  font-weight: 600;
}

.grid-layout {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap: 1rem;
}

.scanner-wrap,
.form-wrap {
  padding: 1rem;
}

.code-form {
  display: grid;
  gap: 0.6rem;
  margin-top: 0.9rem;
}

.steps {
  margin-top: 1.15rem;
  padding: 0.85rem;
  border: 1px solid var(--line);
  border-radius: 0.9rem;
  background: #fff9f2;
}

.steps h3 {
  margin-top: 0;
  margin-bottom: 0.4rem;
}

.steps ol {
  margin: 0;
  padding-left: 1.1rem;
  color: var(--text-muted);
}

@media (max-width: 920px) {
  .hero,
  .grid-layout {
    grid-template-columns: 1fr;
  }
}
</style>
