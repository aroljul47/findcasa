<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({ listings: { type: Array, default: () => [] } })
const emit = defineEmits(['open-listing'])
const mapElement = ref(null)
let map
let layer

function renderMarkers() {
  if (!map) return
  layer?.clearLayers()
  const points = props.listings.filter((item) => Number.isFinite(Number(item.map_latitude)) && Number.isFinite(Number(item.map_longitude)))
  if (!points.length) return
  const bounds = []
  points.forEach((listing) => {
    const position = [Number(listing.map_latitude), Number(listing.map_longitude)]
    bounds.push(position)
    const marker = L.marker(position).addTo(layer)
    const node = document.createElement('button')
    node.className = 'catalogue-map-popup'
    const title = document.createElement('strong')
    const price = document.createElement('span')
    const district = document.createElement('small')
    title.textContent = listing.title || 'Logement FindCasa'
    price.textContent = `${listing.formattedPrice} FCFA/mois`
    district.textContent = listing.district || ''
    node.append(title, price, district)
    node.addEventListener('click', () => emit('open-listing', listing))
    marker.bindPopup(node)
  })
  map.fitBounds(bounds, { padding: [35, 35], maxZoom: 15 })
}

onMounted(async () => {
  await nextTick()
  map = L.map(mapElement.value).setView([6.4485, 2.3557], 12)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map)
  layer = L.layerGroup().addTo(map)
  renderMarkers()
})
watch(() => props.listings, renderMarkers, { deep: true })
onBeforeUnmount(() => map?.remove())
</script>

<template><div ref="mapElement" class="catalogue-real-map"></div></template>
