<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { MapPin } from 'lucide-vue-next'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({ latitude: { type: [String, Number], default: 6.4485 }, longitude: { type: [String, Number], default: 2.3557 } })
const emit = defineEmits(['update:latitude', 'update:longitude'])
const mapElement = ref(null)
let map
let marker

function setPosition(latitude, longitude) {
  const lat = Number(latitude)
  const lng = Number(longitude)
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || !map) return
  marker?.setLatLng([lat, lng])
  map.setView([lat, lng])
  emit('update:latitude', lat.toFixed(7))
  emit('update:longitude', lng.toFixed(7))
}

onMounted(() => {
  const initial = [Number(props.latitude) || 6.4485, Number(props.longitude) || 2.3557]
  map = L.map(mapElement.value).setView(initial, 13)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map)
  marker = L.marker(initial, { draggable: true }).addTo(map)
  marker.on('dragend', () => {
    const position = marker.getLatLng()
    setPosition(position.lat, position.lng)
  })
  map.on('click', (event) => setPosition(event.latlng.lat, event.latlng.lng))
})

watch(() => [props.latitude, props.longitude], ([latitude, longitude]) => setPosition(latitude, longitude))
onBeforeUnmount(() => map?.remove())
</script>

<template><div class="location-picker"><div ref="mapElement" class="location-map"></div><div class="location-map-hint"><MapPin :size="14" /> Clique sur la carte ou déplace le marqueur pour choisir la position.</div></div></template>
