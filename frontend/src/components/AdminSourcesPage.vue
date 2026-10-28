<script setup>
import { computed, onMounted, ref } from 'vue'
import { Activity, ArrowLeft, Check, ExternalLink, Home, Plus, RefreshCw, X } from 'lucide-vue-next'
import api from '../services/api'

defineEmits(['back'])
const sources = ref([])
const offers = ref([])
const loading = ref(false)
const error = ref('')
const activeTab = ref('sources')
const qualificationFilter = ref('all')
const form = ref({ name: '', url: '', adapter: 'sitemap', collection_interval_minutes: 360 })
const eligibleStatuses = ['priority', 'review']

const filteredOffers = computed(() => {
  if (qualificationFilter.value === 'all') return offers.value
  if (qualificationFilter.value === 'eligible') return offers.value.filter((offer) => eligibleStatuses.includes(offer.qualification_status))
  return offers.value.filter((offer) => offer.qualification_status === qualificationFilter.value)
})
const countByStatus = (status) => status === 'eligible'
  ? offers.value.filter((offer) => eligibleStatuses.includes(offer.qualification_status)).length
  : offers.value.filter((offer) => offer.qualification_status === status).length
const statusLabel = (status) => ({ priority: 'Prioritaire', review: 'À vérifier', incomplete: 'Incomplète', rejected: 'Écartée' }[status] || status)
const workflowLabel = (status) => ({ pending: 'En attente', approved: 'Approuvée', rejected: 'Rejetée' }[status] || status)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [sourceResponse, offerResponse] = await Promise.all([
      api.get('/admin/sources'),
      api.get('/admin/collected-offers', { params: { qualification: 'all' } }),
    ])
    sources.value = sourceResponse.data.data || []
    offers.value = offerResponse.data.data?.data || offerResponse.data.data || []
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Collecte indisponible.'
  } finally { loading.value = false }
}

async function createSource() {
  error.value = ''
  try {
    const response = await api.post('/admin/sources', form.value)
    sources.value.unshift(response.data.data)
    form.value = { name: '', url: '', adapter: 'sitemap', collection_interval_minutes: 360 }
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Impossible d’ajouter cette source.' }
}

async function toggleSource(source) {
  Object.assign(source, (await api.put(`/admin/sources/${source.id}`, { is_active: !source.is_active })).data.data)
}

async function updateSource(source) {
  try { Object.assign(source, (await api.put(`/admin/sources/${source.id}`, { name: source.name, collection_interval_minutes: Number(source.collection_interval_minutes) })).data.data) }
  catch (requestError) { error.value = requestError.response?.data?.message || 'Impossible de modifier cette source.' }
}

async function reviewOffer(offer, status) {
  try {
    await api.patch(`/admin/collected-offers/${offer.id}/review`, { status })
    offer.status = status
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Impossible de traiter cette offre.' }
}

onMounted(load)
</script>

<template>
  <div class="moderation-shell">
    <header class="moderation-header"><div class="container moderation-nav"><button class="back-brand" @click="$emit('back')"><span class="brand-mark"><Home :size="18" /></span><span>find<span>casa</span></span></button><span><Activity :size="14" /> Collecte supervisée</span><button class="moderation-exit" @click="$emit('back')"><ArrowLeft :size="14" /> Retour</button></div></header>
    <main class="container moderation-main">
      <div class="moderation-heading"><div><div class="section-kicker">Sources externes</div><h1>Superviser la<br /><em>collecte.</em></h1><p>Toutes les offres restent visibles dans l’historique. Seules les offres éligibles peuvent être approuvées.</p></div><button class="moderation-refresh" @click="load"><RefreshCw :size="14" /> Actualiser</button></div>
      <div class="moderation-tabs"><button :class="{ active: activeTab === 'sources' }" @click="activeTab = 'sources'">Sources <b>{{ sources.length }}</b></button><button :class="{ active: activeTab === 'offers' }" @click="activeTab = 'offers'">Offres collectées <b>{{ offers.length }}</b></button></div>
      <div v-if="error" class="moderation-alert">{{ error }}</div>
      <div v-if="loading" class="moderation-loading"><span class="state-spinner"></span> Chargement...</div>

      <template v-else-if="activeTab === 'sources'">
        <form class="dashboard-panel source-create-form" @submit.prevent="createSource"><label><span>Nom</span><input v-model="form.name" required maxlength="100" /></label><label><span>URL sitemap/RSS/API</span><input v-model="form.url" type="url" required /></label><label><span>Adaptateur</span><select v-model="form.adapter"><option value="sitemap">Sitemap</option><option value="rss">RSS</option><option value="json">JSON</option></select></label><label><span>Intervalle</span><input v-model.number="form.collection_interval_minutes" type="number" min="30" required /></label><button class="dashboard-primary" type="submit"><Plus :size="14" /> Ajouter</button></form>
        <div v-if="!sources.length" class="moderation-empty"><Activity :size="24" /><strong>Aucune source configurée</strong></div>
        <div v-else class="source-admin-grid"><article v-for="source in sources" :key="source.id" class="dashboard-panel source-admin-card"><div><strong>{{ source.name }}</strong><span :class="source.is_active ? 'source-good' : 'source-warning'">{{ source.is_active ? 'Active' : 'Désactivée' }}</span></div><p>{{ source.url }}</p><dl><div><dt>Adaptateur</dt><dd>{{ source.adapter }}</dd></div><div><dt>Offres</dt><dd>{{ source.offers_count || 0 }}</dd></div><div><dt>Exécutions</dt><dd>{{ source.runs_count || 0 }}</dd></div></dl><p v-if="source.last_error" class="source-error">{{ source.last_error }}</p><div class="source-card-actions"><button class="source-toggle" @click="updateSource(source)">Enregistrer</button><button class="source-toggle" @click="toggleSource(source)"><Check :size="13" /> {{ source.is_active ? 'Désactiver' : 'Activer' }}</button></div></article></div>
      </template>

      <template v-else>
        <div class="qualification-filters"><button v-for="filter in [{ id: 'all', label: 'Toutes' }, { id: 'eligible', label: 'Éligibles' }, { id: 'priority', label: 'Prioritaires' }, { id: 'review', label: 'À vérifier' }, { id: 'incomplete', label: 'Incomplètes' }, { id: 'rejected', label: 'Écartées' }]" :key="filter.id" :class="{ active: qualificationFilter === filter.id }" @click="qualificationFilter = filter.id">{{ filter.label }} <b>{{ filter.id === 'all' ? offers.length : countByStatus(filter.id) }}</b></button></div>
        <div v-if="!filteredOffers.length" class="moderation-empty"><Check :size="24" /><strong>Aucune offre dans cette catégorie</strong></div>
        <div v-else class="collected-offer-list"><article v-for="offer in filteredOffers" :key="offer.id" class="dashboard-panel collected-offer"><div><strong>{{ offer.title }}</strong><span>{{ offer.source?.name }} · {{ offer.city || 'Localisation inconnue' }} · {{ offer.monthly_rent ? `${Number(offer.monthly_rent).toLocaleString('fr-FR')} FCFA/mois` : 'Prix absent' }}</span><span class="offer-score" :class="offer.qualification_status">Score {{ offer.relevance_score }}/100 · {{ statusLabel(offer.qualification_status) }} · {{ workflowLabel(offer.status) }}<template v-if="offer.distance_km !== null"> · {{ offer.distance_km }} km du campus</template></span><p v-if="offer.qualification_reasons?.length" class="offer-reasons">{{ offer.qualification_reasons.join(' ') }}</p><a :href="offer.source_url" target="_blank" rel="noopener noreferrer"><ExternalLink :size="13" /> Consulter la source</a></div><div class="moderation-actions"><button v-if="offer.status === 'pending' && eligibleStatuses.includes(offer.qualification_status)" class="approve" @click="reviewOffer(offer, 'approved')"><Check :size="13" /> Approuver</button><span v-else class="offer-not-eligible">{{ offer.status !== 'pending' ? workflowLabel(offer.status) : 'Non éligible' }}</span><button v-if="offer.status === 'pending'" class="reject" @click="reviewOffer(offer, 'rejected')"><X :size="13" /> Rejeter</button></div></article></div>
      </template>
    </main>
  </div>
</template>
