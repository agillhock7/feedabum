import { defineStore } from 'pinia'
import { api } from '../services/api'
import type { AdminUser } from '../types'

interface State {
  admin: AdminUser | null
  isDemo: boolean
  demoLoginEnabled: boolean
  demoLoginEmail: string
  sessionChecked: boolean
}

export const useAuthStore = defineStore('auth', {
  state: (): State => ({
    admin: null,
    isDemo: false,
    demoLoginEnabled: false,
    demoLoginEmail: 'demo@feedabum.local',
    sessionChecked: false
  }),
  getters: {
    isAuthenticated: (state) => state.admin !== null
  },
  actions: {
    async login(email: string, password: string) {
      const result = await api.post<{
        ok: true
        admin: AdminUser
        is_demo: boolean
        demo_login_enabled: boolean
      }>('/auth/login', { email, password })

      this.admin = result.admin
      this.isDemo = Boolean(result.is_demo)
      this.demoLoginEnabled = Boolean(result.demo_login_enabled)
      this.sessionChecked = true
    },

    async logout() {
      await api.post('/auth/logout', {})
      this.admin = null
      this.isDemo = false
      this.sessionChecked = true
    },

    async loadPublicSettings() {
      try {
        const result = await api.get<{
          ok: true
          demo_login_enabled: boolean
          demo_login_email?: string
        }>('/')
        this.demoLoginEnabled = Boolean(result.demo_login_enabled)
        this.demoLoginEmail = result.demo_login_email || 'demo@feedabum.local'
      } catch {
        // Keep defaults if API metadata is unavailable.
      }
    },

    async checkSession() {
      if (this.sessionChecked && this.admin) {
        return this.admin
      }

      try {
        const result = await api.get<{
          ok: true
          admin: AdminUser | null
          is_demo?: boolean
          demo_login_enabled?: boolean
        }>('/admin/recipients')

        this.admin = result.admin
        this.isDemo = Boolean(result.is_demo)
        if (typeof result.demo_login_enabled === 'boolean') {
          this.demoLoginEnabled = result.demo_login_enabled
        }
      } catch {
        this.admin = null
        this.isDemo = false
      }

      this.sessionChecked = true
      return this.admin
    }
  }
})
