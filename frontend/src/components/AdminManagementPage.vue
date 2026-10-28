<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Activity, ArrowLeft, Building2, Check, Home, MapPin, Plus, RefreshCw, Search, ShieldCheck, Users } from 'lucide-vue-next'
import api from '../services/api'

defineEmits(['back'])
const activeTab = ref('stats')
const loading = ref(false)
const error = ref('')
const stats = ref({})
const users = ref([])
const userPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const institutions = ref([])
const logs = ref([])
const userFilters = ref({ q: '', role: '', status: '' })
const institutionForm = ref({ name: '', code: '', category: 'Établissements privés indépendants' })
const campusForms = ref({})
let userSearchTimer

const tabs = [
  { id: 'stats', label: 'Statistiques', icon: Activity },
  { id: 'users', label: 'Utilisateurs', icon: Users },
  { id: 'institutions', label: 'Établissements', icon: Building2 },
  { id: 'logs', label: 'Historique', icon: ShieldCheck },
]
const roleLabel = (role) => ({ student: 'Étudiant', owner: 'Propriétaire', admin: 'Administrateur' }[role] || role)
const actionLabel = (action) => ({
  'user.suspended': 'Utilisateur suspendu', 'user.reactivated': 'Utilisateur réactivé',
  'institution.created': 'Établissement créé', 'institution.updated': 'Établissement modifié', 'institution.disabled': 'Établissement désactivé',
  'campus.created': 'Campus créé', 'campus.updated': 'Campus modifié', 'campus.disabled': 'Campus désactivé',
  'property.reviewed.published': 'Annonce publiée', 'property.reviewed.rejected': 'Annonce rejetée',
}[action] || action)

async function loadStats() { stats.value = (await api.get('/admin/statistics')).data.data }
async function loadUsers(page = 1) {
  const response = await api.get('/admin/users', { params: { ...userFilters.value, page } })
  users.value = response.data.data || []
  userPagination.value = { current_page: response.data.current_page || 1, last_page: response.data.last_page || 1, total: response.data.total || 0 }
}
async function loadInstitutions() {
  institutions.value = (await api.get('/admin/institutions')).data.data || []
  institutions.value.forEach((institution) => { campusForms.value[institution.id] ||= { name: '', address: '', latitude: '', longitude: '' } })
}
async function loadLogs() { logs.value = (await api.get('/admin/audit-logs')).data.data || [] }

async function loadActive() {
  loading.value = true
  error.value = ''
  try {
    if (activeTab.value === 'stats') await loadStats()
    if (activeTab.value === 'users') await loadUsers()
    if (activeTab.value === 'institutions') await loadInstitutions()
    if (activeTab.value === 'logs') await loadLogs()
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Impossible de charger cette section.' } finally { loading.value = false }
}

async function toggleSuspension(user) {
  try {
    const response = await api.patch(`/admin/users/${user.id}/suspension`, { suspended: !user.is_suspended })
    Object.assign(user, response.data.data)
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Action impossible.' }
}

async function createInstitution() {
  try {
    const response = await api.post('/admin/institutions', institutionForm.value)
    const institution = { ...response.data.data, campuses: [] }
    institutions.value.push(institution)
    campusForms.value[institution.id] = { name: '', address: '', latitude: '', longitude: '' }
    institutionForm.value = { name: '', code: '', category: 'Établissements privés indépendants' }
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Création impossible.' }
}

async function updateInstitution(institution) {
  const response = await api.put(`/admin/institutions/${institution.id}`, { name: institution.name, code: institution.code, category: institution.category, is_active: institution.is_active })
  Object.assign(institution, response.data.data)
}

async function disableInstitution(institution) {
  await api.delete(`/admin/institutions/${institution.id}`)
  institution.is_active = false
}

async function createCampus(institution) {
  const form = campusForms.value[institution.id]
  const response = await api.post(`/admin/institutions/${institution.id}/campuses`, { ...form, latitude: form.latitude || null, longitude: form.longitude || null })
  institution.campuses.push(response.data.data)
  campusForms.value[institution.id] = { name: '', address: '', latitude: '', longitude: '' }
}

async function updateCampus(campus) {
  const response = await api.put(`/admin/campuses/${campus.id}`, { name: campus.name, address: campus.address, latitude: campus.latitude, longitude: campus.longitude, is_active: campus.is_active })
  Object.assign(campus, response.data.data)
}

async function disableCampus(campus) {
  await api.delete(`/admin/campuses/${campus.id}`)
  campus.is_active = false
}

watch(activeTab, loadActive)
watch(userFilters, () => { clearTimeout(userSearchTimer); userSearchTimer = setTimeout(() => activeTab.value === 'users' && loadUsers(1), 350) }, { deep: true })
onMounted(loadActive)
</script>

<template>
  <div class="moderation-shell"><header class="moderation-header"><div class="container moderation-nav"><button class="back-brand" @click="$emit('back')"><span class="brand-mark"><Home :size="18" /></span><span>find<span>casa</span></span></button><span><ShieldCheck :size="14" /> Gestion de la plateforme</span><button class="moderation-exit" @click="$emit('back')"><ArrowLeft :size="14" /> Retour</button></div></header><main class="container moderation-main"><div class="moderation-heading"><div><div class="section-kicker">Administration</div><h1>Piloter<br /><em>FindCasa.</em></h1><p>Utilisateurs, référentiels, statistiques et traçabilité au même endroit.</p></div><button class="moderation-refresh" @click="loadActive"><RefreshCw :size="14" /> Actualiser</button></div><div class="moderation-tabs"><button v-for="tab in tabs" :key="tab.id" :class="{ active: activeTab === tab.id }" @click="activeTab = tab.id"><component :is="tab.icon" :size="15" /> {{ tab.label }}</button></div><div v-if="error" class="moderation-alert">{{ error }}</div><div v-if="loading" class="moderation-loading"><span class="state-spinner"></span> Chargement...</div>

    <section v-else-if="activeTab === 'stats'" class="admin-stats-grid"><article v-for="metric in [{key:'users',label:'Utilisateurs'},{key:'students',label:'Étudiants'},{key:'owners',label:'Propriétaires'},{key:'verified_owners',label:'Propriétaires vérifiés'},{key:'published_properties',label:'Logements publiés'},{key:'available_rooms',label:'Chambres disponibles'},{key:'reservations',label:'Réservations'},{key:'active_rentals',label:'Locations actives'}]" :key="metric.key" class="dashboard-panel"><span>{{ metric.label }}</span><strong>{{ stats[metric.key] || 0 }}</strong></article><article class="dashboard-panel admin-stat-wide"><span>Loyer moyen</span><strong>{{ Number(stats.average_rent || 0).toLocaleString('fr-FR') }} FCFA</strong></article><article class="dashboard-panel admin-stat-wide"><span>Part des annonces collectées</span><strong>{{ stats.collected_share || 0 }} %</strong></article><div class="dashboard-panel district-stats"><h2>Loyer moyen par zone</h2><div v-if="!stats.average_rent_by_district?.length" class="admin-empty">Aucune donnée disponible</div><div v-for="district in stats.average_rent_by_district" :key="district.district" class="district-stat-row"><span>{{ district.district }}</span><strong>{{ Number(district.average_rent).toLocaleString('fr-FR') }} FCFA</strong></div></div></section>

    <section v-else-if="activeTab === 'users'" class="admin-section"><div class="admin-filter-bar"><label><Search :size="14" /><input v-model="userFilters.q" placeholder="Nom, email ou téléphone" /></label><select v-model="userFilters.role"><option value="">Tous les rôles</option><option value="student">Étudiants</option><option value="owner">Propriétaires</option><option value="admin">Administrateurs</option></select><select v-model="userFilters.status"><option value="">Tous les statuts</option><option value="active">Actifs</option><option value="suspended">Suspendus</option></select></div><div class="dashboard-panel admin-user-table"><div class="admin-user-head"><span>Utilisateur</span><span>Rôle</span><span>Établissement</span><span>Statut</span><span></span></div><article v-for="user in users" :key="user.id"><div><strong>{{ user.name }}</strong><small>{{ user.email }} · {{ user.phone || 'Sans téléphone' }}</small></div><span>{{ roleLabel(user.role) }}</span><span>{{ user.institution?.name || '—' }}</span><span :class="user.is_suspended ? 'admin-status-danger' : 'admin-status-ok'">{{ user.is_suspended ? 'Suspendu' : 'Actif' }}</span><button v-if="user.role !== 'admin'" @click="toggleSuspension(user)">{{ user.is_suspended ? 'Réactiver' : 'Suspendre' }}</button></article></div><nav v-if="userPagination.last_page > 1" class="catalogue-pagination"><button :disabled="userPagination.current_page === 1" @click="loadUsers(userPagination.current_page - 1)">Précédent</button><span>Page {{ userPagination.current_page }} / {{ userPagination.last_page }}</span><button :disabled="userPagination.current_page === userPagination.last_page" @click="loadUsers(userPagination.current_page + 1)">Suivant</button></nav></section>

    <section v-else-if="activeTab === 'institutions'" class="admin-section"><form class="dashboard-panel institution-create" @submit.prevent="createInstitution"><input v-model="institutionForm.name" placeholder="Nom de l’établissement" required /><input v-model="institutionForm.code" placeholder="Code" /><select v-model="institutionForm.category"><option>Universités publiques</option><option>Universités et grands établissements privés</option><option>Établissements privés indépendants</option></select><button class="dashboard-primary" type="submit"><Plus :size="14" /> Ajouter</button></form><div class="institution-admin-list"><article v-for="institution in institutions" :key="institution.id" class="dashboard-panel institution-admin-card"><header><input v-model="institution.name" /><input v-model="institution.code" placeholder="Code" /><select v-model="institution.category"><option>Universités publiques</option><option>Universités et grands établissements privés</option><option>Établissements privés indépendants</option></select><button @click="updateInstitution(institution)"><Check :size="13" /> Enregistrer</button><button class="danger" @click="disableInstitution(institution)">Désactiver</button></header><div class="campus-admin-list"><article v-for="campus in institution.campuses" :key="campus.id"><input v-model="campus.name" /><input v-model="campus.address" placeholder="Adresse" /><input v-model="campus.latitude" type="number" step="any" placeholder="Latitude" /><input v-model="campus.longitude" type="number" step="any" placeholder="Longitude" /><button @click="updateCampus(campus)">Enregistrer</button><button class="danger" @click="disableCampus(campus)">Désactiver</button></article></div><form class="campus-create" @submit.prevent="createCampus(institution)"><input v-model="campusForms[institution.id].name" placeholder="Nouveau campus" required /><input v-model="campusForms[institution.id].address" placeholder="Adresse" /><input v-model="campusForms[institution.id].latitude" type="number" step="any" placeholder="Latitude" /><input v-model="campusForms[institution.id].longitude" type="number" step="any" placeholder="Longitude" /><button type="submit"><Plus :size="13" /> Ajouter</button></form></article></div></section>

    <section v-else class="admin-section"><div v-if="!logs.length" class="moderation-empty"><ShieldCheck :size="23" /><strong>Aucune action enregistrée</strong></div><div v-else class="dashboard-panel audit-log-list"><article v-for="log in logs" :key="log.id"><span class="audit-icon"><ShieldCheck :size="14" /></span><div><strong>{{ actionLabel(log.action) }}</strong><small>{{ log.actor?.name || 'Système' }} · {{ log.target_type || 'Plateforme' }} #{{ log.target_id || '—' }}</small></div><time>{{ new Date(log.created_at).toLocaleString('fr-FR') }}</time></article></div></section>
  </main></div>
</template>
