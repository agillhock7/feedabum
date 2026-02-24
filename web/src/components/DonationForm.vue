<template>
  <section class="donation-card">
    <h3>Support {{ recipientName }}</h3>

    <div class="quick-grid">
      <button
        v-for="amount in quickAmounts"
        :key="amount"
        type="button"
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

    <label>
      Donor email (optional)
      <input v-model="donorEmail" type="email" placeholder="you@example.com" />
    </label>

    <label>
      Frequency
      <select v-model="frequency">
        <option value="one-time">One-time</option>
        <option value="week">Weekly recurring</option>
        <option value="month">Monthly recurring</option>
      </select>
    </label>

    <div v-if="clientSecret" class="card-input-wrap">
      <p>Card details</p>
      <div ref="cardMountRef" class="card-mount"></div>
      <button type="button" :disabled="busy" @click="confirmCardPayment">Confirm Payment</button>
    </div>

    <button v-else type="button" :disabled="busy" @click="startDonation">
      {{ frequency === 'one-time' ? `Donate $${(amountCents / 100).toFixed(2)}` : 'Continue to Stripe Checkout' }}
    </button>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
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

const quickAmounts = [100, 300, 500, 1000]
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
  border: 1px solid #cbd5e1;
  border-radius: 0.75rem;
  background: #fff;
}

.quick-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}

.quick-grid button.active {
  background: #0e7490;
  color: #fff;
}

label {
  display: grid;
  gap: 0.3rem;
  margin-bottom: 0.75rem;
}

.card-mount {
  border: 1px solid #94a3b8;
  border-radius: 0.5rem;
  padding: 0.75rem;
  background: #fff;
  margin-bottom: 0.75rem;
}

.error {
  color: #b91c1c;
  margin: 0.7rem 0 0;
}
</style>
