<template>
  <main class="container panel">
    <h1>Partner Admin Login</h1>

    <form @submit.prevent="submit">
      <label>
        Email
        <input v-model="email" type="email" required autocomplete="username" />
      </label>

      <label>
        Password
        <input v-model="password" type="password" required autocomplete="current-password" />
      </label>

      <button :disabled="busy" type="submit">Login</button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
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
.container {
  max-width: 540px;
  margin: 1.2rem auto;
  padding: 1.2rem;
}

.panel {
  border: 1px solid #cbd5e1;
  border-radius: 1rem;
  background: #f8fafc;
}

form {
  display: grid;
  gap: 0.8rem;
}

label {
  display: grid;
  gap: 0.3rem;
}

.error {
  color: #b91c1c;
}
</style>
