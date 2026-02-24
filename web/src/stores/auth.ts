import { defineStore } from 'pinia'
import { api } from '../services/api'
import type { Partner } from '../types'

interface State {
  partner: Partner | null
  isDemo: boolean
  demoLoginEnabled: boolean
  demoLoginEmail: string
  sessionChecked: boolean
}

export const useAuthStore = defineStore('auth', {
  state: (): State => ({
    partner: null,
    isDemo: false,
    demoLoginEnabled: false,
    demoLoginEmail: 'admin@feedabum.local',
    sessionChecked: false
  }),
  getters: {
    isAuthenticated: (state) => state.partner !== null
  },
  actions: {
    async login(email: string, password: string) {
      const result = await api.post<{
        ok: true
        partner: Partner
        is_demo: boolean
        demo_login_enabled: boolean
      }>('/auth/login', { email, password })
      this.partner = result.partner
      this.isDemo = Boolean(result.is_demo)
      this.demoLoginEnabled = Boolean(result.demo_login_enabled)
      this.sessionChecked = true
    },
    async logout() {
      await api.post('/auth/logout', {})
      this.partner = null
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
        this.demoLoginEmail = result.demo_login_email || 'admin@feedabum.local'
      } catch {
        // Keep defaults if API metadata is unavailable.
      }
    },
    async checkSession() {
      if (this.sessionChecked && this.partner) {
        return this.partner
      }

      try {
        const result = await api.get<{
          ok: true
          partner: Partner | null
          is_demo?: boolean
          demo_login_enabled?: boolean
        }>('/admin/recipients')
        this.partner = result.partner
        this.isDemo = Boolean(result.is_demo)
        if (typeof result.demo_login_enabled === 'boolean') {
          this.demoLoginEnabled = result.demo_login_enabled
        }
      } catch {
        this.partner = null
        this.isDemo = false
      }

      this.sessionChecked = true
      return this.partner
    }
  }
})
