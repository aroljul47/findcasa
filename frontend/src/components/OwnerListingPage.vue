<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  Check,
  ChevronDown,
  ImagePlus,
  Info,
  MapPin,
  PlayCircle,
  Save,
  ShieldCheck,
  Sparkles,
  Upload,
  X,
} from 'lucide-vue-next'
import api from '../services/api'
import { propertyTypes } from '../config/propertyTypes'
import LocationPicker from './LocationPicker.vue'
import { mediaUrl } from '../services/mediaUrl'

const emit = defineEmits(['back-to-dashboard', 'published'])
const props = defineProps({
  propertyId: { type: [String, Number], default: null },
  embedded: { type: Boolean, default: false },
})
const step = ref(1)
const submitted = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const stepError = ref('')
const createdPropertyId = ref(null)
const selectedAmenities = ref(['Eau courante', 'Sécurité'])
const photoFiles = ref([])
const videoFile = ref(null)
const videoError = ref('')
const locationMessage = ref('')
const existingMedia = ref([])
const existingRooms = ref([])
const loadingProperty = ref(false)
const draftSaving = ref(false)
const draftMessage = ref(props.propertyId ? 'Annonce chargée' : 'Brouillon non enregistré')
const editingStatus = ref(null)
const form = ref({
  title: '',
  type: propertyTypes[0],
  description: '',
  price: '',
  deposit: '',
  advance: '',
  rooms: '1',
  surface: '',
  address: '',
  district: 'Godomey',
  latitude: '',
  longitude: '',
  video: false,
})

const steps = [
  { id: 1, label: 'Informations' },
  { id: 2, label: 'Localisation' },
  { id: 3, label: 'Photos & vidéo' },
  { id: 4, label: 'Aperçu' },
]
const amenities = ['Eau courante', 'Électricité', 'Sécurité', 'Douche interne', 'Cuisine', 'Internet', 'Parking', 'Cour commune']
const previewTitle = computed(() => form.value.title || 'Ton titre apparaîtra ici')
const isEditing = computed(() => Boolean(createdPropertyId.value || props.propertyId))

async function loadProperty() {
  if (!props.propertyId) return
  loadingProperty.value = true
  errorMessage.value = ''
  try {
    const property = (await api.get(`/owner/properties/${props.propertyId}`)).data.data
    createdPropertyId.value = property.id
    editingStatus.value = property.publication_status
    existingRooms.value = property.rooms || []
    existingMedia.value = property.media || []
    selectedAmenities.value = (property.amenities || []).map((item) => item.name)
    const room = property.rooms?.[0]
    form.value = {
      ...form.value,
      title: property.title || '', type: property.type || propertyTypes[0], description: property.description || '',
      price: room?.rent || '', deposit: room?.deposit || '', advance: room?.advance || '', rooms: String(property.rooms?.length || property.bedroom_count || 1),
      surface: property.surface || '', address: property.address || '', district: property.district || 'Godomey', latitude: property.latitude || '', longitude: property.longitude || '',
    }
  } catch (error) { errorMessage.value = error.response?.data?.message || 'Impossible de charger cette annonce.' } finally { loadingProperty.value = false }
}

function toggleAmenity(amenity) {
  selectedAmenities.value = selectedAmenities.value.includes(amenity)
    ? selectedAmenities.value.filter((item) => item !== amenity)
    : [...selectedAmenities.value, amenity]
}

function nextStep() {
  stepError.value = ''
  if (step.value === 1 && (!form.value.title.trim() || !form.value.description.trim() || Number(form.value.surface) < 1)) {
    stepError.value = 'Le titre, la description et une superficie supérieure ou égale à 1 m² sont obligatoires.'
    return
  }
  if (step.value === 2 && (!form.value.address.trim() || !form.value.latitude || !form.value.longitude)) {
    stepError.value = 'L’adresse et la position GPS sont obligatoires.'
    return
  }
  const photoCount = existingMedia.value.filter((item) => item.kind === 'photo').length + photoFiles.value.length
  const existingCategories = existingMedia.value.map((item) => item.category)
  const missingRequiredPhotos = Number(!existingCategories.includes('chambre')) + Number(!existingCategories.includes('devanture'))
  if (step.value === 3 && (photoCount < 2 || photoFiles.value.length < missingRequiredPhotos)) {
    stepError.value = 'Ajoute au minimum une photo de chambre puis une photo de devanture.'
    return
  }
  if (step.value < 4) step.value += 1
}

function previousStep() {
  if (step.value > 1) step.value -= 1
}

function choosePhotos(event) { photoFiles.value = [...(event.target.files || [])] }
function chooseVideo(event) {
  const file = event.target.files?.[0] || null
  videoFile.value = null
  videoError.value = ''
  if (!file) return
  const video = document.createElement('video')
  const url = URL.createObjectURL(file)
  video.preload = 'metadata'
  video.onloadedmetadata = () => {
    URL.revokeObjectURL(url)
    if (video.duration < 120 || video.duration > 600) {
      videoError.value = 'La vidéo doit durer entre 2 et 10 minutes.'
      event.target.value = ''
      return
    }
    videoFile.value = file
  }
  video.onerror = () => {
    URL.revokeObjectURL(url)
    videoError.value = 'Impossible de lire cette vidéo MP4.'
    event.target.value = ''
  }
  video.src = url
}

async function saveDraft() {
  if (editingStatus.value && !['draft', 'rejected'].includes(editingStatus.value)) {
    errorMessage.value = 'Une annonce déjà soumise ne peut pas redevenir un brouillon.'
    return
  }
  draftSaving.value = true
  errorMessage.value = ''
  try {
    const response = await api.post('/owner/properties/draft', {
      property_id: createdPropertyId.value || undefined,
      title: form.value.title || undefined, description: form.value.description || undefined, type: form.value.type,
      address: form.value.address || undefined, district: form.value.district || undefined, surface: form.value.surface || undefined,
      bedroom_count: Number(form.value.rooms), latitude: form.value.latitude || undefined, longitude: form.value.longitude || undefined,
      amenity_names: selectedAmenities.value,
    })
    createdPropertyId.value = response.data.data.id
    editingStatus.value = 'draft'
    draftMessage.value = 'Brouillon enregistré'
  } catch (error) { errorMessage.value = error.response?.data?.message || 'Impossible d’enregistrer le brouillon.' } finally { draftSaving.value = false }
}
function locateProperty() {
  if (!navigator.geolocation) {
    locationMessage.value = 'La géolocalisation n’est pas disponible sur cet appareil.'
    return
  }
  locationMessage.value = 'Localisation en cours...'
  navigator.geolocation.getCurrentPosition(
    ({ coords }) => {
      form.value.latitude = coords.latitude.toFixed(7)
      form.value.longitude = coords.longitude.toFixed(7)
      locationMessage.value = 'Position enregistrée. Vérifie-la avant de continuer.'
    },
    () => { locationMessage.value = 'Autorise la géolocalisation ou renseigne les coordonnées manuellement.' },
    { enableHighAccuracy: true, timeout: 10000 },
  )
}

async function publish() {
  submitting.value = true
  errorMessage.value = ''
  try {
    const rent = Number(form.value.price)
    if (!rent || rent > 45000) {
      errorMessage.value = 'Le loyer mensuel ne peut pas dépasser 45 000 FCFA.'
      return
    }
    const rooms = Array.from({ length: Number(form.value.rooms) }, (_, index) => ({
      ...(existingRooms.value[index]?.id ? { id: existingRooms.value[index].id } : {}),
      name: `Chambre ${index + 1}`,
      rent,
      deposit: Number(form.value.deposit || 0),
      advance: Number(form.value.advance || 0),
      additional_fees: 0,
    }))
    const payload = {
      title: form.value.title,
      description: form.value.description,
      type: form.value.type,
      address: form.value.address,
      district: form.value.district,
      surface: Number(form.value.surface),
      latitude: Number(form.value.latitude),
      longitude: Number(form.value.longitude),
      bedroom_count: Number(form.value.rooms),
      rooms,
      amenity_names: selectedAmenities.value,
    }
    const response = createdPropertyId.value
      ? await api.put(`/owner/properties/${createdPropertyId.value}`, { ...payload, submit: true })
      : await api.post('/owner/properties', payload)
    createdPropertyId.value = response.data.data.id
    const mediaCategories = existingMedia.value.map((item) => item.category)
    for (const file of photoFiles.value) {
      const payload = new FormData()
      payload.append('file', file)
      const category = !mediaCategories.includes('chambre') ? 'chambre' : !mediaCategories.includes('devanture') ? 'devanture' : 'autre'
      mediaCategories.push(category)
      payload.append('category', category)
      await api.post(`/owner/properties/${response.data.data.id}/media`, payload)
    }
    if (videoFile.value) {
      const payload = new FormData()
      payload.append('file', videoFile.value)
      payload.append('category', 'autre')
      await api.post(`/owner/properties/${response.data.data.id}/media`, payload)
    }
    submitted.value = true
    emit('published', response.data.data)
    createdPropertyId.value = null
  } catch (error) {
    const errors = error.response?.data?.errors
    errorMessage.value = errors?.surface?.[0]
      ? 'La superficie doit être supérieure ou égale à 1 m².'
      : errors?.rooms?.[0]
        ? 'Ajoute au moins une chambre avec un loyer valide.'
        : error.response?.data?.message || 'L’annonce n’a pas pu être soumise. Vérifie les informations saisies.'
  } finally {
    submitting.value = false
  }
}

async function deleteMedia(media) {
  try {
    await api.delete(`/owner/media/${media.id}`)
    existingMedia.value = existingMedia.value.filter((item) => item.id !== media.id)
  } catch (error) { errorMessage.value = error.response?.data?.message || 'Impossible de supprimer ce média.' }
}

async function moveMedia(index, direction) {
  const target = index + direction
  if (target < 0 || target >= existingMedia.value.length || !createdPropertyId.value) return
  const reordered = [...existingMedia.value]
  ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]
  existingMedia.value = reordered
  try {
    const response = await api.put(`/owner/properties/${createdPropertyId.value}/media/reorder`, { media_ids: reordered.map((item) => item.id) })
    existingMedia.value = response.data.data || reordered
  } catch (error) { errorMessage.value = error.response?.data?.message || 'Impossible de réorganiser les médias.' }
}

onMounted(loadProperty)
</script>

<template>
  <div class="publish-shell" :class="{ embedded }">
    <header v-if="!embedded" class="publish-header"><div class="container publish-nav"><button class="back-brand" @click="emit('back-to-dashboard')"><span class="brand-mark"><Building2 :size="18" /></span><span>find<span>casa</span></span></button><div class="publish-nav-center"><ShieldCheck :size="14" /> Ton annonce sera vérifiée avant publication</div><button class="publish-exit" @click="emit('back-to-dashboard')">Quitter <X :size="15" /></button></div></header>
    <main class="container publish-main">
      <div v-if="!embedded" class="publish-breadcrumb"><button @click="emit('back-to-dashboard')">Mon espace</button><span>/</span><strong>Publier un logement</strong></div>
      <div v-if="loadingProperty" class="publish-loading"><span class="state-spinner"></span> Chargement de l’annonce...</div>
      <div v-else-if="!submitted">
        <div class="publish-heading"><div><div class="section-kicker">{{ isEditing ? 'Modifier l’annonce' : 'Nouvelle annonce' }}</div><h1>{{ isEditing ? 'Mets ton annonce' : 'Présente ton logement' }}<br /><em>{{ isEditing ? 'à jour.' : 'aux bons étudiants.' }}</em></h1><p>Complète les informations puis soumets l’annonce à la modération.</p></div><div class="publish-heading-actions"><button v-if="embedded" type="button" class="publish-cancel" @click="emit('back-to-dashboard')"><X :size="14" /> Annuler</button><button class="publish-save" :disabled="draftSaving" @click="saveDraft"><Save :size="14" /> {{ draftSaving ? 'Enregistrement...' : draftMessage }}</button></div></div>
        <div class="publish-stepper"><div v-for="item in steps" :key="item.id" class="publish-step" :class="{ active: step === item.id, done: step > item.id }"><span>{{ step > item.id ? '✓' : `0${item.id}` }}</span><strong>{{ item.label }}</strong><i v-if="item.id < steps.length" :class="{ filled: step > item.id }"></i></div></div>
        <div class="publish-layout"><section class="publish-form-card">
           <div v-if="step === 1" class="publish-step-content"><div class="form-section-title"><div><h2>Les informations essentielles</h2><p>Donne envie aux étudiants d’en savoir plus.</p></div><span>Étape 1 sur 4</span></div><label class="publish-field full"><span>Titre de l’annonce</span><input v-model="form.title" type="text" maxlength="255" placeholder="Ex. Chambre salon sanitaire à Calavi" required /><small>Un titre court et précis attire plus de visites.</small></label><div class="publish-fields-three"><label class="publish-field"><span>Type de logement</span><div class="select-wrap"><select v-model="form.type"><option v-for="type in propertyTypes" :key="type" :value="type">{{ type }}</option></select><ChevronDown :size="15" /></div></label><label class="publish-field"><span>Nombre de chambres</span><div class="select-wrap"><select v-model="form.rooms"><option value="1">1 chambre</option><option value="2">2 chambres</option><option value="3">3 chambres</option></select><ChevronDown :size="15" /></div></label><label class="publish-field"><span>Superficie</span><div class="amount-input"><input v-model.number="form.surface" type="number" min="1" step="0.5" placeholder="Ex. 24" required /><small>m²</small></div></label></div><label class="publish-field full"><span>Description</span><textarea v-model="form.description" rows="5" maxlength="500" placeholder="Décris l’ambiance du logement, le quartier et ce qui le rend agréable..." required></textarea><small>{{ form.description.length }}/500 caractères</small></label></div>
           <div v-else-if="step === 2" class="publish-step-content"><div class="form-section-title"><div><h2>Où se trouve le logement ?</h2><p>Une localisation précise aide les étudiants à se projeter.</p></div><span>Étape 2 sur 4</span></div><label class="publish-field full"><span>Adresse ou repère</span><div class="publish-input-icon"><MapPin :size="16" /><input v-model="form.address" type="text" placeholder="Ex. près du marché de Godomey" required /></div></label><label class="publish-field full"><span>Quartier</span><div class="select-wrap"><select v-model="form.district"><option>Godomey</option><option>Zogbadjè</option><option>Arconville</option><option>Bidossessi</option><option>Autre quartier</option></select><ChevronDown :size="15" /></div></label><LocationPicker v-model:latitude="form.latitude" v-model:longitude="form.longitude" /><div class="publish-fields-two location-coordinates"><label class="publish-field"><span>Latitude</span><input v-model="form.latitude" type="number" step="any" min="-90" max="90" placeholder="6.4485000" required /></label><label class="publish-field"><span>Longitude</span><input v-model="form.longitude" type="number" step="any" min="-180" max="180" placeholder="2.3557000" required /></label></div><button class="location-gps-button" type="button" @click="locateProperty"><MapPin :size="14" /> Utiliser ma position actuelle</button><p v-if="locationMessage" class="location-message">{{ locationMessage }}</p><div class="privacy-note"><ShieldCheck :size="16" /><span>La position exacte peut rester masquée jusqu’à l’acceptation d’une demande.</span></div></div>
           <div v-else-if="step === 3" class="publish-step-content"><div class="form-section-title"><div><h2>Montre le meilleur du logement</h2><p>Les photos et vidéos rassurent les étudiants avant la visite.</p></div><span>Étape 3 sur 4</span></div><div v-if="existingMedia.length" class="existing-media-grid"><article v-for="(media, index) in existingMedia" :key="media.id"><img v-if="media.kind === 'photo'" :src="mediaUrl(media.url)" :alt="media.category" /><video v-else :src="mediaUrl(media.url)" controls></video><div><span>{{ media.category }} · {{ media.is_approved ? 'Approuvé' : 'En attente' }}</span><span class="media-order-actions"><button :disabled="index === 0" @click="moveMedia(index, -1)">↑</button><button :disabled="index === existingMedia.length - 1" @click="moveMedia(index, 1)">↓</button><button @click="deleteMedia(media)">Supprimer</button></span></div></article></div><div class="upload-zone"><span><ImagePlus :size="22" /></span><strong>Ajoute les photos du logement</strong><p>Chambre et devanture obligatoires · JPG, PNG · 50 Mo max par fichier</p><label class="upload-button"><Upload :size="14" /> Sélectionner des photos<input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="choosePhotos" /></label><small v-if="photoFiles.length">{{ photoFiles.length }} photo(s) sélectionnée(s)</small></div><div class="video-upload"><span class="video-icon"><PlayCircle :size="20" /></span><div><strong>Ajouter une visite vidéo <small>(facultatif)</small></strong><p>Format MP4 · 2 à 10 minutes · 50 Mo maximum</p><small v-if="videoError" class="video-error">{{ videoError }}</small></div><label class="video-button">{{ videoFile ? 'Vidéo sélectionnée' : 'Ajouter' }}<input type="file" accept="video/mp4" @change="chooseVideo" /></label></div></div>
          <div v-else class="publish-step-content"><div class="form-section-title"><div><h2>Derniers détails et aperçu</h2><p>Indique les conditions financières avant de soumettre ton annonce.</p></div><span>Étape 4 sur 4</span></div><div class="publish-fields-three"><label class="publish-field"><span>Loyer mensuel</span><div class="amount-input"><input v-model="form.price" type="number" min="0" max="45000" placeholder="45 000" /><small>FCFA</small></div></label><label class="publish-field"><span>Caution</span><div class="amount-input"><input v-model="form.deposit" type="number" min="0" max="45000" placeholder="45 000" /><small>FCFA</small></div></label><label class="publish-field"><span>Avance</span><div class="amount-input"><input v-model="form.advance" type="number" placeholder="Montant libre" /><small>FCFA</small></div></label></div><div class="amenities-title"><h3>Équipements disponibles</h3><span>{{ selectedAmenities.length }} sélectionnés</span></div><div class="amenities-selector"><button v-for="amenity in amenities" :key="amenity" :class="{ selected: selectedAmenities.includes(amenity) }" @click="toggleAmenity(amenity)"><Check :size="13" /> {{ amenity }}</button></div><div class="publish-review-note"><Info :size="16" /><p>Après envoi, notre équipe vérifiera les informations et les médias avant de rendre l’annonce visible.</p></div></div>
           <p v-if="stepError || errorMessage" class="publish-step-error">{{ stepError || errorMessage }}</p><div class="publish-actions"><button v-if="step > 1" class="publish-secondary" @click="previousStep"><ArrowLeft :size="15" /> Retour</button><span v-else></span><button v-if="step < 4" class="publish-next" @click="nextStep">Continuer <ArrowRight :size="15" /></button><button v-else class="publish-next" :disabled="submitting" @click="publish">{{ submitting ? 'Envoi en cours...' : 'Soumettre l’annonce' }} <ArrowRight :size="15" /></button></div>
        </section><aside class="publish-preview"><div class="preview-label"><span><Building2 :size="13" /> Aperçu de ton annonce</span><span>Visible après validation</span></div><div class="preview-card"><div class="preview-image"><span><ImagePlus :size="19" /> Tes photos apparaîtront ici</span></div><div class="preview-card-content"><span class="preview-type">{{ form.type }} · {{ form.rooms }} chambre</span><h3>{{ previewTitle }}</h3><p><MapPin :size="13" /> {{ form.district || 'Ton quartier' }}</p><div class="preview-price"><strong>{{ form.price || '45 000' }} <small>FCFA</small></strong><span>/ mois</span></div></div></div><div class="publish-tip"><Sparkles :size="15" /><div><strong>Conseil FindCasa</strong><p>Les annonces avec au moins 5 photos reçoivent plus de demandes.</p></div></div></aside></div>
      </div>
      <div v-else class="publish-success"><span class="success-icon"><Check :size="24" /></span><div class="section-kicker">Annonce reçue</div><h1>Ton logement est<br /><em>entre de bonnes mains.</em></h1><p>Notre équipe va vérifier les informations et les médias. Tu recevras une notification dès que ton annonce sera validée.</p><div class="success-actions"><button class="publish-next" @click="emit('back-to-dashboard')">Retour à mon espace <ArrowRight :size="15" /></button><button class="publish-secondary" @click="submitted = false; step = 1">Créer une autre annonce</button></div></div>
    </main>
  </div>
</template>

