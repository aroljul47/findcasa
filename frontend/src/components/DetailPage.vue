<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowLeft,
  ArrowRight,
  BedDouble,
  CalendarDays,
  Check,
  ChevronLeft,
  ChevronDown,
  ChevronRight,
  Heart,
  Home,
  Info,
  LockKeyhole,
  MapPin,
  MessageCircle,
  Play,
  ShieldCheck,
  Star,
  UserRound,
  X,
} from 'lucide-vue-next'
import api from '../services/api'
import { mediaUrl } from '../services/mediaUrl'

const props = defineProps({
  listing: {
    type: Object,
    default: null,
  },
  embedded: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['back-to-catalogue'])
const reservationSent = ref(false)
const reservationSuccessMessage = ref('Le propriétaire va recevoir ta demande et te répondra rapidement.')
const reservationSubmitting = ref(false)
const saved = ref(false)
const isAuthenticated = ref(Boolean(window.localStorage.getItem('findcasa_token')))
const message = ref('')
const reportReason = ref('')
const actionMessage = ref('')
const messageField = ref(null)
const routeDistance = ref(null)
const routeLoading = ref(false)
const publicReviews = ref([])
const reviewSummary = ref({ average_rating: 0, reviews_count: 0 })
const form = ref({ date: '', duration: '12 mois', message: '' })
const today = new Date().toISOString().slice(0, 10)

const loadedListing = ref(null)
const loading = ref(false)
const loadError = ref('')
const selectedPhotoIndex = ref(0)
const lightboxOpen = ref(false)
const currentListing = computed(() => loadedListing.value || props.listing)
const roomsLabel = computed(() => `${currentListing.value?.rooms?.length || currentListing.value?.bedroom_count || 0} chambre(s)`)
const approvedMedia = computed(() => (currentListing.value?.media || []).map((item) => ({ ...item, resolvedUrl: mediaUrl(item.url) })).filter((item) => item.resolvedUrl))
const photos = computed(() => approvedMedia.value.filter((item) => item.kind === 'photo'))
const videos = computed(() => approvedMedia.value.filter((item) => item.kind === 'video'))
const activePhoto = computed(() => photos.value[selectedPhotoIndex.value] || null)
const mediaCount = computed(() => photos.value.length + videos.value.length)

async function loadListing() {
  const id = props.listing?.id || window.location.pathname.split('/').pop()
  if (!id) return
  loading.value = true
  try {
    const savedSearch = JSON.parse(window.sessionStorage.getItem('findcasa_search') || '{}')
    const response = await api.get(`/properties/${id}`, { params: { campus_id: savedSearch.campusId || undefined } })
    const property = response.data.data
    if (property.origin === 'collected' && property.external_url) {
      window.location.replace(property.external_url)
      return
    }
    const room = property.rooms?.[0]
    loadedListing.value = {
      ...property,
      location: property.address || property.district,
      formattedPrice: room ? new Intl.NumberFormat('fr-FR').format(Number(room.rent)) : 'Prix à préciser',
      distance: property.distance_km !== undefined ? `${property.distance_km} km` : 'Distance indisponible',
      features: (property.amenities || []).map((item) => item.name),
      image: mediaUrl(property.media?.[0]?.url),
      media: property.media || [],
    }
    selectedPhotoIndex.value = 0
  } catch (error) {
    loadError.value = error.response?.status === 404 ? 'Ce logement n’est pas disponible.' : 'Le logement est momentanément indisponible.'
  } finally {
    loading.value = false
  }
}

function openLightbox(index = selectedPhotoIndex.value) {
  if (!photos.value.length) return
  selectedPhotoIndex.value = index
  lightboxOpen.value = true
  document.body.style.overflow = 'hidden'
}

function closeLightbox() {
  lightboxOpen.value = false
  document.body.style.overflow = ''
}

function previousPhoto() {
  if (!photos.value.length) return
  selectedPhotoIndex.value = (selectedPhotoIndex.value - 1 + photos.value.length) % photos.value.length
}

function nextPhoto() {
  if (!photos.value.length) return
  selectedPhotoIndex.value = (selectedPhotoIndex.value + 1) % photos.value.length
}

function handleGalleryKeys(event) {
  if (!lightboxOpen.value) return
  if (event.key === 'Escape') closeLightbox()
  if (event.key === 'ArrowLeft') previousPhoto()
  if (event.key === 'ArrowRight') nextPhoto()
}

async function loadRouteDistance() {
  const savedSearch = JSON.parse(window.sessionStorage.getItem('findcasa_search') || '{}')
  const listing = currentListing.value
  if (!listing?.id || !savedSearch.campusId) return
  routeLoading.value = true
  try {
    const response = await api.get(`/properties/${listing.id}/route-distance`, { params: { campus_id: savedSearch.campusId } })
    routeDistance.value = response.data.data
  } catch {
    routeDistance.value = null
  } finally { routeLoading.value = false }
}

async function loadReviews() {
  if (!currentListing.value?.id) return
  try {
    const response = await api.get(`/properties/${currentListing.value.id}/reviews`)
    publicReviews.value = response.data.data?.reviews || []
    reviewSummary.value = { average_rating: response.data.data?.average_rating || 0, reviews_count: response.data.data?.reviews_count || 0 }
  } catch { publicReviews.value = [] }
}

async function toggleFavorite() {
  if (!isAuthenticated.value) { window.location.href = '/connexion'; return }
  try {
    if (saved.value) await api.delete(`/properties/${currentListing.value.id}/favorite`)
    else await api.post(`/properties/${currentListing.value.id}/favorite`)
    saved.value = !saved.value
  } catch { actionMessage.value = 'Impossible de modifier ce favori pour le moment.' }
}

async function sendMessage() {
  if (!isAuthenticated.value) { window.location.href = '/connexion'; return }
  if (!currentListing.value?.owner?.id) { actionMessage.value = 'Cette offre externe nécessite une mise en relation par FindCasa.'; return }
  if (!message.value.trim()) return
  try { await api.post(`/properties/${currentListing.value.id}/messages`, { body: message.value }); actionMessage.value = 'Message envoyé au propriétaire.'; message.value = '' } catch (error) { actionMessage.value = error.response?.data?.message || 'Message impossible à envoyer.' }
}

async function reportProperty() {
  if (!isAuthenticated.value || !reportReason.value) return
  try { await api.post(`/properties/${currentListing.value.id}/reports`, { reason: reportReason.value }); actionMessage.value = 'Signalement envoyé à l’administration.'; reportReason.value = '' } catch (error) { actionMessage.value = error.response?.data?.message || 'Signalement impossible à envoyer.' }
}

function submitReservation() {
  if (!currentListing.value) return
  const room = currentListing.value.rooms?.find((item) => item.status === 'available') || currentListing.value.rooms?.[0]
  if (!room) {
    actionMessage.value = 'Cette annonce ne contient pas encore de chambre réservable.'
    return
  }

  reservationSubmitting.value = true
  api.post('/reservations', {
    room_id: room.id,
    start_date: form.value.date,
    duration_months: Number.parseInt(form.value.duration, 10),
    message: form.value.message,
  }).then((response) => {
    reservationSent.value = true
    reservationSuccessMessage.value = response.data.requires_external_confirmation ? 'La demande a été transmise à FindCasa pour confirmer le propriétaire externe.' : 'Le propriétaire va recevoir ta demande et te répondra rapidement.'
  }).catch((error) => {
    actionMessage.value = error.response?.data?.message || 'La demande n’a pas pu être envoyée. Connecte-toi puis réessaie.'
  }).finally(() => {
    reservationSubmitting.value = false
  })
}

function focusMessage() {
  if (!isAuthenticated.value) { window.location.href = '/connexion'; return }
  if (!currentListing.value?.owner?.id) { actionMessage.value = 'Utilise la demande de réservation pour solliciter une mise en relation avec cette source externe.'; return }
  messageField.value?.focus()
  messageField.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

onMounted(async () => {
  window.addEventListener('keydown', handleGalleryKeys)
  await loadListing()
  await Promise.all([loadRouteDistance(), loadReviews()])
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleGalleryKeys)
  document.body.style.overflow = ''
})
</script>

<template>
  <div class="detail-shell" :class="{ embedded }">
    <header v-if="!embedded" class="detail-header">
      <div class="container detail-nav">
        <button class="back-brand" @click="emit('back-to-catalogue')"><span class="brand-mark"><Home :size="18" /></span><span>find<span>casa</span></span></button>
        <div class="detail-nav-links"><button @click="emit('back-to-catalogue')"><ArrowLeft :size="14" /> Retour aux annonces</button><span class="detail-nav-divider"></span><span><ShieldCheck :size="14" /> Plateforme sécurisée</span></div>
        
      </div>
    </header>

    <main v-if="currentListing" class="container detail-main">
      <div class="detail-breadcrumb"><button @click="emit('back-to-catalogue')">Annonces</button><span>/</span><strong>{{ currentListing.title }}</strong></div>
       <section class="property-media-section" aria-labelledby="property-media-title">
         <div class="property-media-heading"><div><span class="section-kicker">Photos et vidéos</span><h2 id="property-media-title">Découvre le logement.</h2></div><span v-if="mediaCount" class="property-media-count">{{ photos.length }} photo{{ photos.length > 1 ? 's' : '' }} · {{ videos.length }} vidéo{{ videos.length > 1 ? 's' : '' }}</span></div>
         <div v-if="photos.length" class="property-gallery">
           <button class="property-gallery-main" type="button" :aria-label="`Agrandir ${activePhoto?.category || 'la photo'}`" @click="openLightbox()"><img :src="activePhoto.resolvedUrl" :alt="`${currentListing.title} — ${activePhoto.category || 'photo'}`" /><span class="gallery-verified"><ShieldCheck :size="13" /> Média vérifié</span><span class="gallery-open-label">Agrandir la photo</span></button>
           <div class="property-gallery-thumbnails" role="list" aria-label="Toutes les photos"><button v-for="(photo, index) in photos" :key="photo.id" type="button" role="listitem" :class="{ active: selectedPhotoIndex === index }" :aria-label="`Afficher la photo ${index + 1} : ${photo.category || 'logement'}`" @click="selectedPhotoIndex = index"><img :src="photo.resolvedUrl" :alt="`${currentListing.title} — ${photo.category || `photo ${index + 1}`}`" /><span>{{ photo.category || `Photo ${index + 1}` }}</span></button></div>
         </div>
         <div v-else class="detail-media-empty property-media-empty"><Home :size="25" /><span>Aucune photo approuvée disponible</span></div>

           <div v-if="videos.length" class="property-video-section"><h3>Visite vidéo</h3><div class="property-video-grid"><article v-for="video in videos" :key="video.id"><video :src="video.resolvedUrl" controls preload="metadata" playsinline :poster="activePhoto?.resolvedUrl || undefined"></video><div><Play :size="15" /><span>{{ video.category === 'autre' ? 'Visite complète' : video.category }}</span><small v-if="video.duration_seconds">{{ Math.floor(video.duration_seconds / 60) }} min {{ video.duration_seconds % 60 }} s</small></div></article></div></div>
       </section>

      <div class="detail-layout">
         <section class="detail-content"><div class="detail-title-row"><div><div class="section-kicker">{{ currentListing.type }} · Disponible maintenant</div><h1>{{ currentListing.title }}</h1><p class="detail-location"><MapPin :size="15" /> {{ currentListing.location }} <span>·</span> {{ routeDistance ? `${routeDistance.distance_km} km par route · ${routeDistance.duration_minutes} min` : currentListing.distance }}</p></div><button class="detail-save" :class="{ saved }" @click="toggleFavorite"><Heart :size="18" :fill="saved ? 'currentColor' : 'none'" /> {{ saved ? 'Enregistré' : 'Enregistrer' }}</button></div><div class="detail-rating-row"><span v-if="routeLoading">Calcul de l’itinéraire...</span><span v-if="currentListing.rating" class="detail-rating"><Star :size="15" fill="currentColor" /> {{ currentListing.rating }}</span><span class="detail-verified-text"><ShieldCheck :size="14" /> Annonce vérifiée par FindCasa</span></div>
           <div class="detail-divider"></div><h2>À propos de ce logement</h2><p class="detail-description">{{ currentListing.description || 'La description de ce logement sera affichée par le propriétaire.' }}</p><div class="detail-highlights"><div><span class="highlight-icon"><BedDouble :size="18" /></span><strong>{{ roomsLabel }}</strong><small>Espace nuit</small></div><div><span class="highlight-icon"><Home :size="18" /></span><strong>{{ currentListing.surface || '—' }} m²</strong><small>Surface totale</small></div><div><span class="highlight-icon"><MapPin :size="18" /></span><strong>{{ currentListing.distance }}</strong><small>Du campus</small></div><div><span class="highlight-icon"><CalendarDays :size="18" /></span><strong>Disponible</strong><small>Dès maintenant</small></div></div>
           <div class="detail-divider"></div><h2>Ce que tu trouveras</h2><div class="amenities-grid"><span v-for="feature in currentListing.features" :key="feature"><Check :size="15" /> {{ feature }}</span></div><div class="detail-contact-box"><textarea ref="messageField" v-model="message" rows="2" placeholder="Écrire au propriétaire..."></textarea><button class="detail-message-button" @click="sendMessage"><MessageCircle :size="16" /> Envoyer un message</button><label><span>Signaler l’annonce</span><select v-model="reportReason"><option value="">Choisir un motif</option><option value="fausse_localisation">Fausse localisation</option><option value="prix_incorrect">Prix incorrect</option><option value="annonce_obsolete">Annonce obsolète</option><option value="photos_trompeuses">Photos trompeuses</option><option value="contenu_frauduleux">Contenu frauduleux</option></select><button @click="reportProperty">Signaler</button></label><p v-if="actionMessage" class="detail-action-message">{{ actionMessage }}</p></div>
           <div class="detail-divider"></div><div class="owner-profile"><div class="owner-avatar">{{ (currentListing.owner?.name || 'P').slice(0, 2).toUpperCase() }}</div><div><h2>À propos du propriétaire</h2><p><strong>{{ currentListing.owner?.name || 'Propriétaire FindCasa' }}</strong> · Propriétaire vérifié</p><span>Les informations sont contrôlées par FindCasa.</span></div><ShieldCheck :size="21" /></div><div class="detail-divider"></div><section class="public-reviews"><div class="public-review-heading"><div><h2>Avis des étudiants</h2><p v-if="reviewSummary.reviews_count"><Star :size="14" fill="currentColor" /> {{ reviewSummary.average_rating }}/5 · {{ reviewSummary.reviews_count }} avis</p><p v-else>Aucun avis publié pour le moment.</p></div></div><div v-if="publicReviews.length" class="public-review-grid"><article v-for="review in publicReviews" :key="review.id"><div><strong>{{ review.student?.name || 'Étudiant' }}</strong><span>{{ '★'.repeat(review.rating) }}{{ '☆'.repeat(5 - review.rating) }}</span></div><p>{{ review.comment || 'Aucun commentaire.' }}</p></article></div></section>
        </section>

        <aside class="reservation-column"><div class="reservation-card"><div class="reservation-price"><div><strong>{{ currentListing.formattedPrice }} <small>FCFA</small></strong><span>/ mois</span></div><span class="price-note">Hors charges</span></div><div v-if="reservationSent" class="reservation-success"><span><Check :size="17" /></span><div><strong>Demande envoyée</strong><p>{{ reservationSuccessMessage }}</p></div></div><form v-else @submit.prevent="submitReservation"><label><span>Date d’installation</span><div class="form-input"><CalendarDays :size="15" /><input v-model="form.date" type="date" :min="today" required /></div></label><label><span>Durée souhaitée</span><div class="form-input"><CalendarDays :size="15" /><select v-model="form.duration"><option>6 mois</option><option>12 mois</option><option>24 mois</option></select><ChevronDown :size="14" /></div></label><label><span>Message au propriétaire <small>(facultatif)</small></span><textarea v-model="form.message" rows="3" placeholder="Présente-toi en quelques mots..."></textarea></label><button class="reservation-submit" type="submit" :disabled="reservationSubmitting">Envoyer une demande <ArrowRight :size="16" /></button><p class="reservation-hint"><LockKeyhole :size="13" /> Aucun paiement demandé maintenant</p></form><div class="reservation-divider"></div><div class="reservation-contact"><span class="contact-avatar"><UserRound :size="16" /></span><span><strong>Une question avant de réserver ?</strong><small>Écris directement au propriétaire</small></span><button aria-label="Contacter le propriétaire" @click="focusMessage"><MessageCircle :size="16" /></button></div></div><div class="safety-note"><Info :size="17" /><p><strong>Réserve sereinement</strong><br />Ne verse jamais d’argent avant d’avoir visité le logement. FindCasa ne te demandera jamais tes codes secrets.</p></div></aside>
      </div>
     </main><div v-else class="detail-state"><span v-if="loading" class="state-spinner"></span><Home v-else :size="25" /><strong>{{ loading ? 'Chargement du logement...' : loadError || 'Logement indisponible' }}</strong><button v-if="!loading" @click="emit('back-to-catalogue')">Retour aux annonces</button></div>

     <div v-if="lightboxOpen && activePhoto" class="media-lightbox" role="dialog" aria-modal="true" aria-label="Galerie photos" @click.self="closeLightbox"><header><span>{{ selectedPhotoIndex + 1 }} / {{ photos.length }} · {{ activePhoto.category || 'Photo du logement' }}</span><button type="button" aria-label="Fermer la galerie" @click="closeLightbox"><X :size="22" /></button></header><button v-if="photos.length > 1" class="media-lightbox-nav previous" type="button" aria-label="Photo précédente" @click="previousPhoto"><ChevronLeft :size="30" /></button><figure><img :src="activePhoto.resolvedUrl" :alt="`${currentListing.title} — ${activePhoto.category || 'photo'}`" /><figcaption>{{ currentListing.title }} · {{ activePhoto.category || 'Photo' }}</figcaption></figure><button v-if="photos.length > 1" class="media-lightbox-nav next" type="button" aria-label="Photo suivante" @click="nextPhoto"><ChevronRight :size="30" /></button><div class="media-lightbox-thumbnails"><button v-for="(photo, index) in photos" :key="photo.id" type="button" :class="{ active: selectedPhotoIndex === index }" :aria-label="`Aller à la photo ${index + 1}`" @click="selectedPhotoIndex = index"><img :src="photo.resolvedUrl" alt="" /></button></div></div>
  </div>
</template>
