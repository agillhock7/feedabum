import { defineStore } from 'pinia'

interface State {
  online: boolean
  swUpdateReady: boolean
  initialized: boolean
}

export const useNetworkStore = defineStore('network', {
  state: (): State => ({
    online: navigator.onLine,
    swUpdateReady: false,
    initialized: false
  }),
  actions: {
    init() {
      if (this.initialized) {
        return
      }

      this.initialized = true
      window.addEventListener('online', () => {
        this.online = true
      })
      window.addEventListener('offline', () => {
        this.online = false
      })
    },
    setUpdateReady(ready: boolean) {
      this.swUpdateReady = ready
    }
  }
})
