<template>
  <section class="donation-card card">
    <header>
      <h3>Support {{ recipientName }}</h3>
      <p class="section-subtitle">Choose a fast amount or set custom recurring support.</p>
    </header>

    <div class="quick-grid">
      <button
        v-for="amount in quickAmounts"
        :key="amount"
        type="button"
        class="quick-btn"
        :class="{ active: amountCents === amount }"
        @click="setAmount(amount)"
      >
        ${{ (amount / 100).toFixed(0) }}
      </button>
    </div>

    <label>
      Custom amount (USD)
      <input v-model="customAmount" type="number" min="1" step="0.01" placeholder="12.00" />
    </label>

    <p class="impact card-warm">{{ impactHint }}</p>

    <label>
      Donor email (optional)
      <input v-model="donorEmail" type="email" placeholder="you@example.com" />
    </label>

    <fieldset class="freq-wrap">
      <legend>Frequency</legend>
      <div class="freq-grid">
        <button type="button" :class="{ active: frequency === 'one-time' }" @click="frequency = 'one-time'">One-time</button>
        <button type="button" :class="{ active: frequency === 'week' }" @click="frequency = 'week'">Weekly</button>
        <button type="button" :class="{ active: frequency === 'month' }" @click="frequency = 'month'">Monthly</button>
      </div>
    </fieldset>

    <div v-if="clientSecret" class="card-input-wrap card-warm fade-up">
      <p>Card details</p>
      <div ref="cardMountRef" class="card-mount"></div>
      <button class="btn-primary" type="button" :disabled="busy" @click="confirmCardPayment">Confirm Payment</button>
    </div>

    <button v-else class="btn-primary" type="button" :disabled="busy" @click="startDonation">
      {{ actionLabel }}
    </button>

    <p v-if="errorMessage" class="error-text">{{ errorMessage }}</p>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { loadStripe, type Stripe, type StripeCardElement, type StripeElements } from '@stripe/stripe-js'
import { api } from '../services/api'

const props = defineProps<{
  recipientId: number
  recipientName: string
}>()

const emit = defineEmits<{
  completed: []
}>()

const quickAmounts = [100, 300, 500, 1000, 2000]
const selectedAmount = ref<number>(500)
const customAmount = ref('')
const donorEmail = ref('')
const frequency = ref<'one-time' | 'week' | 'month'>('one-time')

const busy = ref(false)
const errorMessage = ref('')
const clientSecret = ref('')

const cardMountRef = ref<HTMLDivElement | null>(null)
let stripe: Stripe | null = null
let elements: StripeElements | null = null
let cardElement: StripeCardElement | null = null

const amountCents = computed(() => {
  const custom = Number(customAmount.value)
  if (customAmount.value.trim() !== '' && !Number.isNaN(custom) && custom > 0) {
    return Math.round(custom * 100)
  }

  return selectedAmount.value
})

const actionLabel = computed(() => {
  if (frequency.value === 'one-time') {
    return `Donate $${(amountCents.value / 100).toFixed(2)}`
  }

  const cadence = frequency.value === 'week' ? 'weekly' : 'monthly'
  return `Start ${cadence} support ($${(amountCents.value / 100).toFixed(2)})`
})

const impactHint = computed(() => {
  const dollars = (amountCents.value / 100).toFixed(2)
  if (frequency.value === 'week') {
    return `$${dollars}/week can cover recurring meal support and transit assistance.`
  }

  if (frequency.value === 'month') {
    return `$${dollars}/month can stabilize hygiene + document access costs.`
  }

  return `$${dollars} today helps with immediate essentials in the current zone.`
})

function setAmount(cents: number) {
  selectedAmount.value = cents
  customAmount.value = ''
}

async function startDonation() {
  errorMessage.value = ''

  if (amountCents.value < 100) {
    errorMessage.value = 'Minimum donation is $1.00.'
    return
  }

  busy.value = true

  try {
    if (frequency.value === 'one-time') {
      const result = await api.post<{
        ok: true
        client_secret: string
        publishable_key: string
      }>('/donation/create-intent', {
        recipient_id: props.recipientId,
        amount_cents: amountCents.value,
        currency: 'usd',
        donor_email: donorEmail.value || null
      })

      clientSecret.value = result.client_secret
      await nextTick()
      await initCardElement(result.publishable_key || import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY)
      return
    }

    const result = await api.post<{ ok: true; checkout_url: string }>('/subscription/create', {
      recipient_id: props.recipientId,
      amount_cents: amountCents.value,
      interval: frequency.value,
      donor_email: donorEmail.value || null
    })

    if (!result.checkout_url) {
      throw new Error('Stripe checkout URL missing.')
    }

    window.location.href = result.checkout_url
  } catch (error: any) {
    errorMessage.value = error?.message || 'Unable to start donation flow.'
  } finally {
    busy.value = false
  }
}

async function initCardElement(publishableKey: string) {
  if (!publishableKey) {
    throw new Error('Stripe publishable key missing. Set VITE_STRIPE_PUBLISHABLE_KEY or API STRIPE_PUBLISHABLE_KEY.')
  }

  stripe = await loadStripe(publishableKey)
  if (!stripe) {
    throw new Error('Unable to initialize Stripe.js.')
  }

  elements = stripe.elements()
  cardElement = elements.create('card')

  if (!cardMountRef.value) {
    throw new Error('Card mount container missing.')
  }

  cardElement.mount(cardMountRef.value)
}

async function confirmCardPayment() {
  if (!stripe || !cardElement || !clientSecret.value) {
    errorMessage.value = 'Payment form is not ready.'
    return
  }

  busy.value = true
  errorMessage.value = ''

  try {
    const result = await stripe.confirmCardPayment(clientSecret.value, {
      payment_method: {
        card: cardElement,
        billing_details: donorEmail.value
          ? {
              email: donorEmail.value
            }
          : undefined
      }
    })

    if (result.error) {
      throw new Error(result.error.message || 'Payment failed.')
    }

    if (result.paymentIntent?.status === 'succeeded' || result.paymentIntent?.status === 'processing') {
      emit('completed')
      return
    }

    throw new Error('Payment was not confirmed yet.')
  } catch (error: any) {
    errorMessage.value = error?.message || 'Unable to confirm payment.'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.donation-card {
  padding: 1rem;
}

h3 {
  margin: 0;
}

.quick-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.45rem;
  margin: 0.85rem 0;
}

.quick-btn.active {
  border-color: #f78833;
  background: #fff1e4;
  color: #8d4207;
}

label {
  display: grid;
  gap: 0.3rem;
  margin-bottom: 0.75rem;
}

.impact {
  margin: 0 0 0.75rem;
  padding: 0.65rem;
  font-size: 0.92rem;
  color: #7a421d;
}

.freq-wrap {
  margin: 0 0 0.85rem;
  border: 1px solid var(--line);
  border-radius: 0.75rem;
  padding: 0.5rem;
}

.freq-wrap legend {
  color: var(--text-muted);
  padding: 0 0.3rem;
}

.freq-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.45rem;
}

.freq-grid button.active {
  border-color: #f78833;
  background: #fff1e4;
  color: #8d4207;
}

.card-input-wrap {
  margin-bottom: 0.75rem;
  padding: 0.8rem;
}

.card-input-wrap p {
  margin-top: 0;
}

.card-mount {
  border: 1px solid var(--line);
  border-radius: 0.6rem;
  padding: 0.75rem;
  background: #fff;
  margin-bottom: 0.75rem;
}

@media (max-width: 720px) {
  .quick-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
