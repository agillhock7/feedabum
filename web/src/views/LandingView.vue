<template>
  <main class="container">
    <section class="panel">
      <h1>Feed a Bum</h1>
      <p>Scan a verified badge QR code to donate in seconds.</p>

      <QrScanner @scanned="handleScanned" />

      <form class="code-form" @submit.prevent="submitCode">
        <label>
          Enter short code
          <input v-model="manualCode" placeholder="FAB1234" maxlength="12" />
        </label>
        <button type="submit">Find Recipient</button>
      </form>

      <router-link to="/admin/login" class="admin-link">Partner admin login</router-link>
    </section>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import QrScanner from '../components/QrScanner.vue'

const router = useRouter()
const manualCode = ref('')

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

  if (/^[A-Za-z0-9]{4,12}$/.test(cleaned)) {
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
</script>

<style scoped>
.container {
  max-width: 820px;
  margin: 0 auto;
  padding: 1.2rem;
}

.panel {
  background: #f8fafc;
  border: 1px solid #d1d5db;
  border-radius: 1rem;
  padding: 1.2rem;
}

h1 {
  margin-top: 0;
}

.code-form {
  margin-top: 1rem;
  display: grid;
  gap: 0.6rem;
}

.admin-link {
  display: inline-block;
  margin-top: 1rem;
}
</style>
