<template>
  <main class="page-shell fade-up">
    <section class="card-warm success-wrap">
      <h1>Thank you for supporting your neighbor.</h1>
      <p class="section-subtitle">
        Payment status is finalized after Stripe confirmation and secure webhook processing.
      </p>
      <div class="actions">
        <router-link to="/">Back to scan</router-link>
        <router-link to="/admin/login">Partner login</router-link>
      </div>
    </section>
  </main>
</template>

<style scoped>
.success-wrap {
  max-width: 740px;
  margin: 0 auto;
  padding: 1.2rem;
}

h1 {
  margin-top: 0;
}

.actions {
  display: flex;
  gap: 1rem;
}

@media (max-width: 720px) {
  .actions {
    flex-direction: column;
  }
}
</style>
