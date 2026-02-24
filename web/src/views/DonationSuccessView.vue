<template>
  <main class="container panel">
    <h1>Thank you for supporting your neighbor.</h1>
    <p>
      Payment status will be finalized after Stripe confirmation and webhook processing.
    </p>
    <router-link to="/">Back to scan</router-link>
  </main>
</template>

<style scoped>
.container {
  max-width: 720px;
  margin: 1.2rem auto;
  padding: 1.2rem;
}

.panel {
  border: 1px solid #cbd5e1;
  border-radius: 1rem;
  background: #f8fafc;
}
</style>
