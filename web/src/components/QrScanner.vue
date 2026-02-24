<template>
  <section class="scanner">
    <video ref="videoRef" class="scanner-video" autoplay playsinline muted></video>
    <canvas ref="canvasRef" class="scanner-canvas"></canvas>

    <div class="scanner-actions">
      <button type="button" @click="toggleScanner">{{ running ? 'Stop Camera' : 'Start Camera' }}</button>
      <p class="scanner-status">{{ statusText }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import jsQR from 'jsqr'

const emit = defineEmits<{
  scanned: [value: string]
}>()

const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const running = ref(false)
const statusText = ref('Camera ready.')

let stream: MediaStream | null = null
let frameId = 0

async function startScanner() {
  if (running.value) {
    return
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
      audio: false
    })

    if (!videoRef.value) {
      throw new Error('Video element missing.')
    }

    videoRef.value.srcObject = stream
    await videoRef.value.play()
    running.value = true
    statusText.value = 'Scanning...'
    scanLoop()
  } catch {
    statusText.value = 'Camera unavailable. Use Enter Code fallback.'
  }
}

function stopScanner() {
  running.value = false
  statusText.value = 'Camera stopped.'
  cancelAnimationFrame(frameId)
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

function toggleScanner() {
  if (running.value) {
    stopScanner()
  } else {
    void startScanner()
  }
}

function scanLoop() {
  if (!running.value || !videoRef.value || !canvasRef.value) {
    return
  }

  const video = videoRef.value
  const canvas = canvasRef.value

  if (video.readyState >= 2) {
    const width = video.videoWidth
    const height = video.videoHeight

    if (width > 0 && height > 0) {
      canvas.width = width
      canvas.height = height
      const context = canvas.getContext('2d', { willReadFrequently: true })
      if (context) {
        context.drawImage(video, 0, 0, width, height)
        const imageData = context.getImageData(0, 0, width, height)
        const decoded = jsQR(imageData.data, width, height)

        if (decoded?.data) {
          emit('scanned', decoded.data)
          statusText.value = 'QR code detected.'
          stopScanner()
          return
        }
      }
    }
  }

  frameId = requestAnimationFrame(scanLoop)
}

onMounted(() => {
  void startScanner()
})

onBeforeUnmount(() => {
  stopScanner()
})
</script>

<style scoped>
.scanner {
  display: grid;
  gap: 0.8rem;
}

.scanner-video {
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border-radius: 0.75rem;
  border: 1px solid #9ca3af;
  background: #111827;
}

.scanner-canvas {
  display: none;
}

.scanner-actions {
  display: grid;
  gap: 0.5rem;
}

.scanner-actions button {
  max-width: 10rem;
}

.scanner-status {
  margin: 0;
  color: #334155;
}
</style>
