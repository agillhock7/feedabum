import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { registerSW } from 'virtual:pwa-register'
import App from './App.vue'
import router from './router'
import './style.css'
import 'leaflet/dist/leaflet.css'
import { useNetworkStore } from './stores/network'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

const network = useNetworkStore()
network.init()

registerSW({
  onNeedRefresh() {
    network.setUpdateReady(true)
  },
  onOfflineReady() {
    // offline cache is ready
  }
})

app.mount('#app')
