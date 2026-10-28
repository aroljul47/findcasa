<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowLeft, Check, Download, ExternalLink, FileCheck2, Home, Image, Mail, MapPin, MessageSquareWarning, Phone, RefreshCw, ShieldCheck, Users, X } from 'lucide-vue-next'
import api from '../services/api'
import { mediaUrl as resolveMediaUrl } from '../services/mediaUrl'

const emit = defineEmits(['back'])
const activeTab = ref('owners')
const loading = ref(true)
const error = ref('')
const processing = ref(null)
const selectedOwner = ref(null)
const selectedProperty = ref(null)
const propertyLoading = ref(false)
const documentLoading = ref(false)
const moderationNote = ref('')
let documentUrl
const queues = ref({ owners: [], properties: [], media: [], reviews: [], reports: [] })

const tabs = [
  { id: 'owners', label: 'Propriétaires', icon: Users },
  { id: 'properties', label: 'Annonces', icon: FileCheck2 },
  { id: 'media', label: 'Médias', icon: Image },
  { id: 'reviews', label: 'Avis', icon: MessageSquareWarning },
  { id: 'reports', label: 'Signalements', icon: ShieldCheck },
]

const approvedRoomPhoto = computed(() => selectedProperty.value?.media?.some((item) => item.kind === 'photo' && item.category === 'chambre' && item.is_approved))
const approvedFrontPhoto = computed(() => selectedProperty.value?.media?.some((item) => item.kind === 'photo' && item.category === 'devanture' && item.is_approved))
const canPublishProperty = computed(() => approvedRoomPhoto.value && approvedFrontPhoto.value)

function pageData(response) { return response.data.data?.data || response.data.data || [] }
function itemTitle(item, tab) {
  if (tab === 'owners') return item.name
  if (tab === 'properties') return item.title
  if (tab === 'media') return item.property?.title || 'Média à vérifier'
  if (tab === 'reviews') return item.property?.title || 'Avis à vérifier'
  return item.property?.title || 'Signalement à vérifier'
}
function itemMeta(item, tab) {
  if (tab === 'owners') return `${item.email}${item.phone ? ` · ${item.phone}` : ''}`
  if (tab === 'properties') return `${item.owner?.name || item.external_source?.name || 'Annonce collectée'} · ${item.district || 'Localisation à préciser'}`
  if (tab === 'media') return `${item.category || item.kind} · ${item.mime_type || ''}`
  if (tab === 'reviews') return `${item.rating}/5 · ${item.student?.name || 'Étudiant'}`
  return `${item.reason} · ${item.reporter?.name || 'Utilisateur'}`
}
function mediaUrl(media) { return resolveMediaUrl(media.url) }
function money(value) { return value === null || value === undefined ? 'Non renseigné' : `${Number(value).toLocaleString('fr-FR')} FCFA` }

async function load() {
  loading.value = true
  error.value = ''
  try {
    const responses = await Promise.all(tabs.map((tab) => api.get({ owners: '/admin/owners/pending', properties: '/admin/properties/pending', media: '/admin/media/pending', reviews: '/admin/reviews/pending', reports: '/admin/reports/pending' }[tab.id])))
    queues.value = Object.fromEntries(tabs.map((tab, index) => [tab.id, pageData(responses[index])]))
  } catch (requestError) { error.value = requestError.response?.data?.message || 'La file de modération est indisponible.' } finally { loading.value = false }
}

async function action(item, tab, status, note = null) {
  processing.value = `${tab}-${item.id}`
  error.value = ''
  try {
    const routes = { owners: `/admin/owners/${item.id}/verification`, properties: `/admin/properties/${item.id}/review`, media: `/admin/media/${item.id}/review`, reviews: `/admin/reviews/${item.id}/review`, reports: `/admin/reports/${item.id}/review` }
    const payload = tab === 'media' ? { approved: status === 'published' } : { status, ...(note ? { moderation_note: note } : {}) }
    const response = await api.patch(routes[tab], payload)
    queues.value[tab] = queues.value[tab].filter((entry) => entry.id !== item.id)
    if (tab === 'owners') selectedOwner.value = null
    if (tab === 'properties') selectedProperty.value = null
    return response.data
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Action impossible.'; throw requestError } finally { processing.value = null }
}

async function suspendFromReport(item) {
  processing.value = `reports-${item.id}`
  try {
    await api.patch(`/admin/reports/${item.id}/review`, { status: 'resolved', suspend_property: true, admin_note: 'Annonce suspendue après examen du signalement.' })
    queues.value.reports = queues.value.reports.filter((entry) => entry.id !== item.id)
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Impossible de suspendre cette annonce.' } finally { processing.value = null }
}

async function openProperty(item) {
  selectedProperty.value = null
  moderationNote.value = item.moderation_note || ''
  propertyLoading.value = true
  try { selectedProperty.value = (await api.get(`/admin/properties/${item.id}`)).data.data } catch (requestError) { error.value = requestError.response?.data?.message || 'Annonce indisponible.' } finally { propertyLoading.value = false }
}

async function reviewProperty(status) {
  if (!selectedProperty.value) return
  try { await action(selectedProperty.value, 'properties', status, moderationNote.value) } catch { /* Error displayed globally. */ }
}

async function reviewMedia(media, approved) {
  processing.value = `media-${media.id}`
  try {
    const response = await api.patch(`/admin/media/${media.id}/review`, { approved })
    Object.assign(media, response.data.data)
    queues.value.media = queues.value.media.filter((item) => item.id !== media.id)
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Impossible de traiter ce média.' } finally { processing.value = null }
}

async function openIdentityDocument(owner) {
  documentLoading.value = true
  try {
    const response = await api.get(`/admin/owners/${owner.id}/identity-document`, { responseType: 'blob' })
    if (documentUrl) URL.revokeObjectURL(documentUrl)
    documentUrl = URL.createObjectURL(response.data)
    window.open(documentUrl, '_blank', 'noopener,noreferrer')
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Document indisponible.' } finally { documentLoading.value = false }
}

onMounted(load)
onBeforeUnmount(() => { if (documentUrl) URL.revokeObjectURL(documentUrl) })
</script>

<template>
  <div class="moderation-shell">
    <header class="moderation-header"><div class="container moderation-nav"><button class="back-brand" @click="emit('back')"><span class="brand-mark"><Home :size="18" /></span><span>find<span>casa</span></span></button><span><ShieldCheck :size="14" /> Espace administration</span><button class="moderation-exit" @click="emit('back')"><ArrowLeft :size="14" /> Retour</button></div></header>
    <main class="container moderation-main"><div class="moderation-breadcrumb"><button @click="emit('back')"><ArrowLeft :size="13" /> Tableau de bord</button><span>/</span><strong>Modération</strong></div><div class="moderation-heading"><div><div class="section-kicker">Contrôle qualité</div><h1>Examiner avant<br /><em>publication.</em></h1><p>Ouvre chaque dossier pour consulter toutes les informations avant décision.</p></div><button class="moderation-refresh" @click="load"><RefreshCw :size="14" /> Actualiser</button></div><div class="moderation-tabs"><button v-for="tab in tabs" :key="tab.id" :class="{ active: activeTab === tab.id }" @click="activeTab = tab.id"><component :is="tab.icon" :size="15" /> {{ tab.label }} <b>{{ queues[tab.id].length }}</b></button></div><div v-if="error" class="moderation-alert">{{ error }}</div><div v-if="loading" class="moderation-loading"><span class="state-spinner"></span> Chargement...</div>

      <section v-else class="moderation-list moderation-list-wide"><div class="moderation-list-heading"><div><h2>{{ tabs.find((tab) => tab.id === activeTab)?.label }}</h2><p>{{ queues[activeTab].length }} élément(s) en attente</p></div><span class="moderation-count">{{ queues[activeTab].length }}</span></div><div v-if="!queues[activeTab].length" class="moderation-empty"><Check :size="23" /><strong>Aucun élément en attente</strong></div><article v-for="item in queues[activeTab]" :key="item.id" class="moderation-item" :class="{ 'moderation-item-clickable': ['owners', 'properties'].includes(activeTab) }" @click="activeTab === 'owners' ? selectedOwner = item : activeTab === 'properties' ? openProperty(item) : null"><div class="moderation-avatar">{{ activeTab === 'owners' ? item.name?.slice(0, 2).toUpperCase() : activeTab === 'reports' ? '!' : 'FC' }}</div><div class="moderation-item-body"><strong>{{ itemTitle(item, activeTab) }}</strong><small>{{ itemMeta(item, activeTab) }}</small></div><div class="moderation-actions" @click.stop><button v-if="activeTab === 'owners'" class="owner-details-button" @click="selectedOwner = item"><ExternalLink :size="13" /> Examiner</button><button v-else-if="activeTab === 'properties'" class="owner-details-button" @click="openProperty(item)"><ExternalLink :size="13" /> Voir l’annonce</button><template v-else><button v-if="activeTab === 'reports'" class="owner-details-button" @click="suspendFromReport(item)">Suspendre l'annonce</button><button class="approve" @click="action(item, activeTab, activeTab === 'reports' ? 'resolved' : 'published')"><Check :size="13" /> Valider</button><button class="reject" @click="action(item, activeTab, 'rejected')"><X :size="13" /> Rejeter</button></template></div></article></section>
    </main>

    <div v-if="selectedOwner" class="owner-review-overlay" @click.self="selectedOwner = null"><section class="owner-review-panel"><header><div><span class="section-kicker">Vérification propriétaire</span><h2>{{ selectedOwner.name }}</h2></div><button @click="selectedOwner = null"><X :size="18" /></button></header><div class="owner-review-profile"><span class="owner-review-avatar">{{ selectedOwner.name?.slice(0, 2).toUpperCase() }}</span><div><strong>{{ selectedOwner.name }}</strong><small>Inscrit le {{ new Date(selectedOwner.created_at).toLocaleDateString('fr-FR') }}</small></div></div><dl class="owner-review-details"><div><dt>Email</dt><dd>{{ selectedOwner.email }}</dd></div><div><dt>Téléphone</dt><dd>{{ selectedOwner.phone || 'Non renseigné' }}</dd></div><div><dt>Pièce d’identité</dt><dd>{{ selectedOwner.has_identity_document ? 'Document disponible' : 'Document absent' }}</dd></div></dl><div class="owner-contact-actions"><a :href="`mailto:${selectedOwner.email}?subject=Vérification de votre compte FindCasa`"><Mail :size="15" /> Envoyer un email</a><a v-if="selectedOwner.phone" :href="`tel:${selectedOwner.phone}`"><Phone :size="15" /> Appeler</a><button v-if="selectedOwner.has_identity_document" @click="openIdentityDocument(selectedOwner)"><Download :size="15" /> Voir la pièce</button></div><div class="owner-decision-actions"><button class="approve" :disabled="!selectedOwner.has_identity_document" @click="action(selectedOwner, 'owners', 'verified')"><Check :size="15" /> Valider</button><button class="reject" @click="action(selectedOwner, 'owners', 'rejected')"><X :size="15" /> Refuser</button></div></section></div>

    <div v-if="propertyLoading || selectedProperty" class="property-review-overlay" @click.self="selectedProperty = null"><section class="property-review-panel"><div v-if="propertyLoading" class="moderation-loading"><span class="state-spinner"></span> Chargement de l’annonce...</div><template v-else><header><div><span class="section-kicker">Examen de l’annonce</span><h2>{{ selectedProperty.title }}</h2><p>{{ selectedProperty.origin === 'collected' ? `Collectée depuis ${selectedProperty.external_source?.name || 'une source externe'}` : `Publiée par ${selectedProperty.owner?.name || 'un propriétaire'}` }}</p></div><button @click="selectedProperty = null"><X :size="19" /></button></header><div class="property-review-gallery"><article v-for="media in selectedProperty.media" :key="media.id" class="property-review-media"><img v-if="media.kind === 'photo'" :src="mediaUrl(media)" :alt="media.category" /><video v-else :src="mediaUrl(media)" controls preload="metadata"></video><div><span>{{ media.category }} · {{ media.is_approved ? 'Approuvé' : 'En attente' }}</span><div v-if="!media.is_approved"><button class="approve" @click="reviewMedia(media, true)"><Check :size="12" /> Approuver</button><button class="reject" @click="reviewMedia(media, false)"><X :size="12" /> Rejeter</button></div></div></article><div v-if="!selectedProperty.media?.length" class="property-review-no-media"><Image :size="24" /><strong>Aucun média déposé</strong></div></div><div class="property-review-columns"><section><h3>Informations</h3><dl><div><dt>Description</dt><dd>{{ selectedProperty.description || 'Non renseignée' }}</dd></div><div><dt>Type</dt><dd>{{ selectedProperty.type }}</dd></div><div><dt>Superficie</dt><dd>{{ selectedProperty.surface ? `${selectedProperty.surface} m²` : 'Non renseignée' }}</dd></div><div><dt>Quartier</dt><dd>{{ selectedProperty.district || 'Non renseigné' }}</dd></div><div><dt>Adresse</dt><dd>{{ selectedProperty.address || 'Non renseignée' }}</dd></div><div><dt>Coordonnées</dt><dd><MapPin :size="13" /> {{ selectedProperty.latitude }}, {{ selectedProperty.longitude }}</dd></div></dl></section><section><h3>Chambres et finances</h3><article v-for="room in selectedProperty.rooms" :key="room.id" class="property-review-room"><strong>{{ room.name }}</strong><span>Loyer : {{ money(room.rent) }}</span><span>Caution : {{ money(room.deposit) }}</span><span>Avance : {{ money(room.advance) }}</span><span>Frais : {{ money(room.additional_fees) }}</span></article><h3>Équipements</h3><div class="property-review-amenities"><span v-for="amenity in selectedProperty.amenities" :key="amenity.id"><Check :size="12" /> {{ amenity.name }}</span><em v-if="!selectedProperty.amenities?.length">Aucun équipement renseigné</em></div></section></div><div class="property-review-requirements"><span :class="{ ok: approvedRoomPhoto }"><Check :size="13" /> Photo chambre approuvée</span><span :class="{ ok: approvedFrontPhoto }"><Check :size="13" /> Photo devanture approuvée</span></div><label class="property-review-note"><span>Note de modération</span><textarea v-model="moderationNote" rows="3" placeholder="Explique les corrections demandées en cas de rejet..."></textarea></label><div class="owner-decision-actions"><button class="approve" :disabled="!canPublishProperty || processing === `properties-${selectedProperty.id}`" @click="reviewProperty('published')"><Check :size="15" /> Publier l’annonce</button><button class="reject" :disabled="processing === `properties-${selectedProperty.id}`" @click="reviewProperty('rejected')"><X :size="15" /> Rejeter l’annonce</button></div></template></section></div>
  </div>
</template>
