import { createRouter, createWebHistory } from 'vue-router'
import LandingView from '../views/LandingView.vue'
import RecipientView from '../views/RecipientView.vue'
import DonationSuccessView from '../views/DonationSuccessView.vue'
import AdminLoginView from '../views/AdminLoginView.vue'
import AdminDashboardView from '../views/AdminDashboardView.vue'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'landing', component: LandingView },
    { path: '/recipient', name: 'recipient', component: RecipientView },
    { path: '/donation-success', name: 'donation-success', component: DonationSuccessView },
    { path: '/admin/login', name: 'admin-login', component: AdminLoginView },
    { path: '/admin', name: 'admin-dashboard', component: AdminDashboardView, meta: { requiresAuth: true } }
  ]
})

router.beforeEach(async (to) => {
  if (!to.meta.requiresAuth) {
    return true
  }

  const auth = useAuthStore()
  const partner = await auth.checkSession()

  if (!partner) {
    return { name: 'admin-login' }
  }

  return true
})

export default router
