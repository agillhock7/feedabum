import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/pwa-192.svg', 'icons/pwa-512.svg'],
      manifest: {
        name: 'Feed a Bum',
        short_name: 'FeedABum',
        description: 'Hyperlocal micro-giving for verified recipients.',
        start_url: '/',
        display: 'standalone',
        background_color: '#f7fcfd',
        theme_color: '#0e7490',
        icons: [
          {
            src: '/icons/pwa-192.svg',
            sizes: '192x192',
            type: 'image/svg+xml'
          },
          {
            src: '/icons/pwa-512.svg',
            sizes: '512x512',
            type: 'image/svg+xml'
          }
        ]
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg}']
      }
    })
  ],
  build: {
    outDir: '../dist',
    emptyOutDir: true
  }
})
