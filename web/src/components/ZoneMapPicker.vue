<template>
  <section class="map-wrap">
    <div ref="mapRef" class="map-canvas"></div>
    <p class="coords" v-if="modelValue.lat !== null && modelValue.lng !== null">
      Pin: {{ modelValue.lat.toFixed(5) }}, {{ modelValue.lng.toFixed(5) }}
    </p>
    <p class="coords" v-else>Tap/click map to set zone pin.</p>
  </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

interface CoordinateModel {
  lat: number | null
  lng: number | null
}

const props = withDefaults(
  defineProps<{
    modelValue: CoordinateModel
    height?: number
    interactive?: boolean
  }>(),
  {
    height: 280,
    interactive: true
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: CoordinateModel]
}>()

const mapRef = ref<HTMLDivElement | null>(null)

let map: any = null
let marker: any = null
let L: any = null

const defaultCenter = {
  lat: 32.2226,
  lng: -110.9747
}

function setMarker(lat: number, lng: number) {
  if (!map || !L) {
    return
  }

  if (!marker) {
    marker = L.circleMarker([lat, lng], {
      radius: 8,
      color: '#ff6b00',
      fillColor: '#ff9b4a',
      fillOpacity: 0.9,
      weight: 3
    }).addTo(map)
  } else {
    marker.setLatLng([lat, lng])
  }
}

onMounted(async () => {
  if (!mapRef.value) {
    return
  }

  mapRef.value.style.height = `${props.height}px`

  L = await import('leaflet')

  map = L.map(mapRef.value, {
    zoomControl: true,
    attributionControl: true,
    dragging: props.interactive,
    scrollWheelZoom: props.interactive,
    doubleClickZoom: props.interactive,
    touchZoom: props.interactive,
    keyboard: props.interactive
  })

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map)

  const initialLat = props.modelValue.lat ?? defaultCenter.lat
  const initialLng = props.modelValue.lng ?? defaultCenter.lng

  map.setView([initialLat, initialLng], props.modelValue.lat !== null ? 14 : 11)

  if (props.modelValue.lat !== null && props.modelValue.lng !== null) {
    setMarker(props.modelValue.lat, props.modelValue.lng)
  }

  if (props.interactive) {
    map.on('click', (event: any) => {
      const lat = Number(event.latlng.lat)
      const lng = Number(event.latlng.lng)
      setMarker(lat, lng)
      emit('update:modelValue', { lat, lng })
    })
  }

  setTimeout(() => {
    map.invalidateSize()
  }, 0)
})

watch(
  () => props.modelValue,
  (next) => {
    if (!map) {
      return
    }

    if (next.lat !== null && next.lng !== null) {
      setMarker(next.lat, next.lng)
    }
  },
  { deep: true }
)

onBeforeUnmount(() => {
  if (map) {
    map.remove()
    map = null
  }
})
</script>

<style scoped>
.map-wrap {
  border: 1px solid var(--line);
  border-radius: 0.85rem;
  background: #fff;
  overflow: hidden;
}

.map-canvas {
  width: 100%;
}

.coords {
  margin: 0;
  padding: 0.45rem 0.7rem;
  color: var(--text-muted);
  border-top: 1px solid var(--line);
  font-size: 0.87rem;
}
</style>
