import { defineStore } from 'pinia'
import type { RecipientProfile } from '../types'

const CACHE_KEY = 'fab:last_recipient'

interface State {
  current: RecipientProfile | null
  source: 'live' | 'cache' | null
}

export const useRecipientStore = defineStore('recipient', {
  state: (): State => ({
    current: null,
    source: null
  }),
  actions: {
    setLive(recipient: RecipientProfile) {
      this.current = recipient
      this.source = 'live'
      localStorage.setItem(CACHE_KEY, JSON.stringify(recipient))
    },
    loadCache() {
      const raw = localStorage.getItem(CACHE_KEY)
      if (!raw) {
        return null
      }

      try {
        const parsed = JSON.parse(raw) as RecipientProfile
        this.current = parsed
        this.source = 'cache'
        return parsed
      } catch {
        localStorage.removeItem(CACHE_KEY)
        return null
      }
    }
  }
})
