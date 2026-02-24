<template>
  <section class="scanner">
    <div class="preview-shell">
      <video ref="videoRef" class="scanner-video" autoplay playsinline muted></video>
      <div class="scan-overlay" :class="{ active: running }"></div>
    </div>
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
    statusText.value = 'Scanning for badge code...'
    scanLoop()
  } catch {
    statusText.value = 'Camera unavailable. Use manual short code entry.'
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

.preview-shell {
  position: relative;
  border-radius: 0.9rem;
  overflow: hidden;
}

.scanner-video {
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border: 1px solid #eab88e;
  background: #2f1b0c;
}

.scan-overlay {
  position: absolute;
  inset: 0;
  pointer-events: none;
  border: 2px solid rgba(255, 255, 255, 0.35);
  box-shadow: inset 0 0 0 2rem rgba(17, 24, 39, 0.12);
}

.scan-overlay::after {
  content: "";
  position: absolute;
  left: 8%;
  right: 8%;
  height: 2px;
  background: #ff8a3c;
  top: 10%;
  opacity: 0;
}

.scan-overlay.active::after {
  opacity: 1;
  animation: scanline 1.6s infinite ease-in-out;
}

@keyframes scanline {
  0% {
    top: 10%;
  }

  50% {
    top: 88%;
  }

  100% {
    top: 10%;
  }
}

.scanner-canvas {
  display: none;
}

.scanner-actions {
  display: grid;
  gap: 0.4rem;
}

.scanner-status {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.92rem;
}
</style>
