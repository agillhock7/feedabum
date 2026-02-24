<template>
  <main class="page-shell fade-up">
    <section class="login card-warm">
      <header>
        <h1>Partner Admin Login</h1>
        <p class="section-subtitle">Manage recipient verification, QR rotations, and profile updates.</p>
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

        <button class="btn-primary" :disabled="busy" type="submit">Login</button>
        <p v-if="error" class="error-text">{{ error }}</p>
      </form>
    </section>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('admin@feedabum.local')
const password = ref('DevPass!234')
const busy = ref(false)
const error = ref('')

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
</script>

<style scoped>
.login {
  max-width: 560px;
  margin: 0 auto;
  padding: 1.2rem;
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
