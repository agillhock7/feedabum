import { defineStore } from 'pinia'
import { api } from '../services/api'
import type { Partner } from '../types'

interface State {
  partner: Partner | null
  sessionChecked: boolean
}

export const useAuthStore = defineStore('auth', {
  state: (): State => ({
    partner: null,
    sessionChecked: false
  }),
  getters: {
    isAuthenticated: (state) => state.partner !== null
  },
  actions: {
    async login(email: string, password: string) {
      const result = await api.post<{ ok: true; partner: Partner }>('/auth/login', { email, password })
      this.partner = result.partner
      this.sessionChecked = true
    },
    async logout() {
      await api.post('/auth/logout', {})
      this.partner = null
      this.sessionChecked = true
    },
    async checkSession() {
      if (this.sessionChecked && this.partner) {
        return this.partner
      }

      try {
        const result = await api.get<{ ok: true; partner: Partner | null }>('/admin/recipients')
        this.partner = result.partner
      } catch {
        this.partner = null
      }

      this.sessionChecked = true
      return this.partner
    }
  }
})
