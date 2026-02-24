<template>
  <main class="page-shell fade-up">
    <section class="login card-warm">
      <header>
        <img src="/branding/fab-wordmark.svg" alt="Feed A Bum" class="wordmark" />
        <h1>Partner Admin Login</h1>
        <p class="section-subtitle">Manage recipient verification, QR rotations, and profile updates.</p>
        <p v-if="auth.demoLoginEnabled" class="chip">
          Demo login enabled for outreach previews (read-only)
        </p>
        <p v-else class="section-subtitle">Demo login is currently disabled.</p>
      </header>

      <form @submit.prevent="submit">
        <label>
          Email
          <input v-model="email" type="email" required autocomplete="username" />
        </label>

        <label>
          Password
          <input v-model="password" type="password" required autocomplete="current-password" />
        </label>

        <button
          v-if="auth.demoLoginEnabled"
          type="button"
          @click="fillDemoCredentials"
        >
          Use Demo Credentials
        </button>
        <button class="btn-primary" :disabled="busy" type="submit">Login</button>
        <p v-if="error" class="error-text">{{ error }}</p>
      </form>
    </section>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('owner@feedabum.local')
const password = ref('OwnerPass!234')
const busy = ref(false)
const error = ref('')

function fillDemoCredentials() {
  email.value = auth.demoLoginEmail || 'demo@feedabum.local'
  password.value = 'DemoPass!234'
}

async function submit() {
  error.value = ''
  busy.value = true

  try {
    await auth.login(email.value, password.value)
    await router.push({ name: 'admin-dashboard' })
  } catch (err: any) {
    error.value = err?.message || 'Login failed.'
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void auth.loadPublicSettings()
})
</script>

<style scoped>
.login {
  max-width: 560px;
  margin: 0 auto;
  padding: 1.2rem;
}

.wordmark {
  width: min(100%, 280px);
  border-radius: 0.55rem;
  margin-bottom: 0.65rem;
}

h1 {
  margin: 0;
}

form {
  margin-top: 0.9rem;
  display: grid;
  gap: 0.8rem;
}

label {
  display: grid;
  gap: 0.3rem;
}
</style>
