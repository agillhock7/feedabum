<template>
  <main class="page-shell fade-up">
    <section class="card-warm wrap">
      <header>
        <img src="/branding/fab-wordmark.svg" alt="Feed A Bum" class="wordmark" />
        <h1>Password Recovery</h1>
        <p class="section-subtitle">Use your account email to request a secure reset link.</p>
      </header>

      <form v-if="!hasToken" class="form" @submit.prevent="requestReset">
        <label>
          Account email
          <input v-model="email" type="email" required autocomplete="email" />
        </label>

        <button class="btn-primary" :disabled="busy" type="submit">Send Reset Link</button>
        <p v-if="message" class="success-text">{{ message }}</p>
        <p v-if="error" class="error-text">{{ error }}</p>
      </form>

      <form v-else class="form" @submit.prevent="resetPassword">
        <label>
          New password
          <input v-model="newPassword" type="password" minlength="8" required autocomplete="new-password" />
        </label>

        <label>
          Confirm new password
          <input v-model="confirmPassword" type="password" minlength="8" required autocomplete="new-password" />
        </label>

        <button class="btn-primary" :disabled="busy" type="submit">Set New Password</button>
        <p v-if="message" class="success-text">{{ message }}</p>
        <p v-if="error" class="error-text">{{ error }}</p>
      </form>

      <router-link to="/admin/login">Back to admin login</router-link>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../services/api'

const route = useRoute()

const email = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const busy = ref(false)
const message = ref('')
const error = ref('')

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const hasToken = computed(() => token.value.trim() !== '')

async function requestReset() {
  busy.value = true
  message.value = ''
  error.value = ''

  try {
    const result = await api.post<{ ok: true; message: string }>('/auth/password/forgot', {
      email: email.value
    })
    message.value = result.message || 'If an account exists for that email, a reset link has been sent.'
  } catch (err: any) {
    error.value = err?.message || 'Unable to send reset request.'
  } finally {
    busy.value = false
  }
}

async function resetPassword() {
  if (newPassword.value !== confirmPassword.value) {
    error.value = 'Passwords do not match.'
    return
  }

  busy.value = true
  message.value = ''
  error.value = ''

  try {
    const result = await api.post<{ ok: true; message: string }>('/auth/password/reset', {
      token: token.value,
      new_password: newPassword.value
    })
    message.value = result.message || 'Password has been reset successfully.'
  } catch (err: any) {
    error.value = err?.message || 'Unable to reset password.'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.wrap {
  max-width: 560px;
  margin: 0 auto;
  padding: 1.2rem;
}

.wordmark {
  width: min(100%, 300px);
  border-radius: 0.55rem;
  margin-bottom: 0.65rem;
}

h1 {
  margin: 0;
}

.form {
  margin: 0.9rem 0;
  display: grid;
  gap: 0.75rem;
}

label {
  display: grid;
  gap: 0.3rem;
}
</style>
