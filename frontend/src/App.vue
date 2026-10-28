<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  ArrowRight,
  ExternalLink,
  BedDouble,
  Bell,
  Building2,
  CalendarDays,
  Check,
  ChevronDown,
  CircleHelp,
  Compass,
  Heart,
  Home,
  LockKeyhole,
  MapPin,
  Menu,
  MessageCircle,
  Play,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  Sparkles,
  Star,
  Users,
  X,
} from 'lucide-vue-next'
import api from './services/api'
import ToastContainer from './components/ToastContainer.vue'
import { disconnectRealtime } from './services/realtime'
import { propertyTypes } from './config/propertyTypes'
import { mediaUrl } from './services/mediaUrl'

const CataloguePage = defineAsyncComponent(() => import('./components/CataloguePage.vue'))
const DetailPage = defineAsyncComponent(() => import('./components/DetailPage.vue'))
const AuthPage = defineAsyncComponent(() => import('./components/AuthPage.vue'))
const DashboardPage = defineAsyncComponent(() => import('./components/DashboardPage.vue'))
const OwnerListingPage = defineAsyncComponent(() => import('./components/OwnerListingPage.vue'))
const AdminModerationPage = defineAsyncComponent(() => import('./components/AdminModerationPage.vue'))
const StaticPage = defineAsyncComponent(() => import('./components/StaticPage.vue'))
const AdminSourcesPage = defineAsyncComponent(() => import('./components/AdminSourcesPage.vue'))
const AdminManagementPage = defineAsyncComponent(() => import('./components/AdminManagementPage.vue'))

const mobileMenuOpen = ref(false)
const currentPath = window.location.pathname
const staticPaths = { '/conditions': 'conditions', '/confidentialite': 'confidentialite', '/aide': 'aide', '/contact': 'contact' }
const adminPathViews = { '/admin/moderation': 'À modérer', '/admin/sources': 'Sources de collecte', '/admin/gestion': 'Gestion' }
const currentStaticPage = ref(staticPaths[currentPath] || 'conditions')
const currentView = ref(staticPaths[currentPath] ? 'static' : currentPath.startsWith('/annonces/') ? 'detail' : currentPath === '/annonces' ? 'catalogue' : currentPath === '/connexion' || currentPath === '/inscription' ? 'auth' : (currentPath === '/espace/logement/nouveau' || /^\/espace\/logements\/\d+\/modifier$/.test(currentPath)) ? 'publish' : adminPathViews[currentPath] || currentPath === '/espace' ? 'dashboard' : 'home')
const dashboardInitialNav = ref(adminPathViews[currentPath] || 'Vue d’ensemble')
const catalogueReturnView = ref('home')
const selectedListing = ref(null)
const selectedOwnerPropertyId = ref(currentPath.match(/^\/espace\/logements\/(\d+)\/modifier$/)?.[1] || null)
const authMode = ref(currentPath === '/inscription' ? 'register' : 'login')
const currentRole = ref(null)
const currentUser = ref(null)
const sessionReady = ref(!['dashboard', 'publish'].includes(currentView.value))
const protectedViewLoading = computed(() => ['dashboard', 'publish'].includes(currentView.value) && !sessionReady.value)
const campus = ref('Choisir un campus')
const campusOptions = ref([])
const selectedCampusId = ref('')
const selectedPropertyType = ref('')
const searchTerm = ref('')
const budget = ref(45000)
const favorites = ref([])
const selectedFilter = ref('Tous')

const filters = ['Tous', 'Moins de 45k']
let revealObserver

const listings = ref([])
const budgetLabel = computed(() => `${new Intl.NumberFormat('fr-FR').format(Math.min(Number(budget.value) || 0, 45000))} FCFA / mois`)

async function loadFeaturedListings() {
  try {
    const response = await api.get('/properties', { params: { max_price: 45000, campus_id: selectedCampusId.value || undefined, sort: selectedCampusId.value ? 'distance' : 'newest', per_page: 6 } })
    listings.value = (response.data.data || []).filter((property) => property.media?.length || property.external_image_url).slice(0, 6).map((property) => {
      const room = property.rooms?.[0]
      return {
        id: property.id,
        image: property.origin === 'collected' ? property.external_image_url : mediaUrl(property.media?.[0]?.url),
        title: property.title,
        location: property.district || property.address,
        price: room ? new Intl.NumberFormat('fr-FR').format(Number(room.rent)) : 'Prix à préciser',
        distance: property.distance_km !== undefined ? `${Number(property.distance_km).toFixed(2)} km du campus` : 'Choisis un campus pour calculer la distance',
        type: property.type,
        rating: null,
        featured: false,
        origin: property.origin,
        external_url: property.external_url,
        source_name: property.external_source?.name || 'Source externe',
      }
    })
  } catch {
    listings.value = []
  }
}

async function loadCampusOptions() {
  try {
    const response = await api.get('/institutions')
    campusOptions.value = (response.data.data || []).flatMap((institution) => (institution.campuses || []).map((campusItem) => ({
      id: campusItem.id,
      label: `${institution.name} — ${campusItem.name}`,
    })))
  } catch {
    campusOptions.value = []
  }
}

const visibleListings = computed(() => {
  if (selectedFilter.value === 'Tous') return listings.value
  if (selectedFilter.value === 'Moins de 45k') return listings.value.filter((listing) => Number(String(listing.price).replace(/\s/g, '')) <= 45000)
  return listings.value
})

async function toggleFavorite(id) {
  const listing = listings.value.find((item) => item.id === id)
  if (listing?.origin === 'collected') return
  if (!window.localStorage.getItem('findcasa_token')) {
    openAuth('login')
    return
  }
  const saved = favorites.value.includes(id)
  try {
    if (saved) await api.delete(`/properties/${id}/favorite`)
    else await api.post(`/properties/${id}/favorite`)
    favorites.value = saved ? favorites.value.filter((favoriteId) => favoriteId !== id) : [...favorites.value, id]
  } catch {
    // Keep the current state when the API rejects the action.
  }
}

function runSearch() {
  window.sessionStorage.setItem('findcasa_search', JSON.stringify({ campus: campus.value, campusId: selectedCampusId.value, propertyType: selectedPropertyType.value, budget: Math.min(Number(budget.value) || 0, 45000) }))
  openCatalogue()
}

function openCatalogue() {
  catalogueReturnView.value = currentView.value === 'dashboard' ? 'dashboard' : 'home'
  currentView.value = 'catalogue'
  window.history.pushState({}, '', '/annonces')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function leaveCatalogue() {
  if (catalogueReturnView.value === 'dashboard' && currentUser.value) backToDashboard()
  else goHome()
}

function openListing(listing) {
  if (listing.origin === 'collected' && listing.external_url) {
    window.location.assign(listing.external_url)
    return
  }
  selectedListing.value = listing
  currentView.value = 'detail'
  window.history.pushState({}, '', `/annonces/${listing.id}`)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function backToCatalogue() {
  currentView.value = 'catalogue'
  window.history.pushState({}, '', '/annonces')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function openAuth(mode = 'login') {
  authMode.value = mode
  currentView.value = 'auth'
  mobileMenuOpen.value = false
  window.history.pushState({}, '', mode === 'register' ? '/inscription' : '/connexion')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function handleAuthenticated(user) {
  currentUser.value = user
  currentRole.value = user.role
  sessionReady.value = true
  currentView.value = 'dashboard'
  window.history.pushState({}, '', '/espace')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function handleUserUpdated(user) {
  currentUser.value = user
  currentRole.value = user.role
}

async function logout() {
  try {
    await api.post('/auth/logout')
  } finally {
    window.localStorage.removeItem('findcasa_token')
    disconnectRealtime()
    currentUser.value = null
    currentRole.value = null
    sessionReady.value = true
    authMode.value = 'login'
    currentView.value = 'auth'
    window.history.pushState({}, '', '/connexion')
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

async function restoreSession() {
  if (!window.localStorage.getItem('findcasa_token')) {
    if (['dashboard', 'moderation', 'sources', 'management', 'publish'].includes(currentView.value)) openAuth('login')
    sessionReady.value = true
    return
  }

  try {
    const response = await api.get('/auth/me')
    currentUser.value = response.data
    currentRole.value = response.data.role
    if (currentView.value === 'moderation' && response.data.role !== 'admin') openAuth('login')
    if (currentView.value === 'sources' && response.data.role !== 'admin') openAuth('login')
    if (currentView.value === 'management' && response.data.role !== 'admin') openAuth('login')
    if (currentView.value === 'publish' && response.data.role !== 'owner') openAuth('login')
  } catch {
    window.localStorage.removeItem('findcasa_token')
    currentUser.value = null
    currentRole.value = null
    if (['dashboard', 'moderation', 'sources', 'management', 'publish'].includes(currentView.value)) openAuth('login')
  } finally {
    sessionReady.value = true
  }
}

function openPublish(property = null) {
  selectedOwnerPropertyId.value = property?.id || null
  currentView.value = 'publish'
  window.history.pushState({}, '', property?.id ? `/espace/logements/${property.id}/modifier` : '/espace/logement/nouveau')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function backToDashboard() {
  currentView.value = 'dashboard'
  window.history.pushState({}, '', '/espace')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function openStaticPage(page) {
  currentStaticPage.value = page
  currentView.value = 'static'
  window.history.pushState({}, '', `/${page}`)
  window.scrollTo({ top: 0 })
}

function openAdminModeration() {
  dashboardInitialNav.value = 'À modérer'
  currentView.value = 'dashboard'
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function openAdminSources() {
  dashboardInitialNav.value = 'Sources de collecte'
  currentView.value = 'dashboard'
  window.scrollTo({ top: 0 })
}

function openAdminManagement() {
  dashboardInitialNav.value = 'Gestion'
  currentView.value = 'dashboard'
  window.scrollTo({ top: 0 })
}

function goHome() {
  currentView.value = 'home'
  window.history.pushState({}, '', '/')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function scrollToSection(id) {
  mobileMenuOpen.value = false
  const target = document.querySelector(id)
  if (!target) return

  const announcementHeight = document.querySelector('.announcement-bar')?.offsetHeight || 0
  const navigationHeight = document.querySelector('.site-header')?.offsetHeight || 0
  const top = target.getBoundingClientRect().top + window.scrollY - announcementHeight - navigationHeight - 12

  window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
}

function syncViewFromLocation() {
  const path = window.location.pathname
  if (staticPaths[path]) {
    currentStaticPage.value = staticPaths[path]
    currentView.value = 'static'
  } else if (path.startsWith('/annonces/')) currentView.value = 'detail'
  else if (path === '/annonces') currentView.value = 'catalogue'
  else if (path === '/connexion' || path === '/inscription') {
    authMode.value = path === '/inscription' ? 'register' : 'login'
    currentView.value = 'auth'
  } else if (path === '/espace/logement/nouveau' || /^\/espace\/logements\/\d+\/modifier$/.test(path)) {
    selectedOwnerPropertyId.value = path.match(/^\/espace\/logements\/(\d+)\/modifier$/)?.[1] || null
    currentView.value = 'publish'
  }
  else if (adminPathViews[path]) {
    dashboardInitialNav.value = adminPathViews[path]
    currentView.value = 'dashboard'
  }
  else if (path === '/espace') currentView.value = 'dashboard'
  else currentView.value = 'home'
}

async function setupScrollReveal() {
  revealObserver?.disconnect()
  revealObserver = undefined
  if (currentView.value !== 'home' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  await nextTick()
  const sections = [...document.querySelectorAll('.app-shell main > section')]
  sections.slice(1).forEach((section) => section.classList.add('reveal-section'))
  revealObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return
      entry.target.classList.add('reveal-visible')
      observer.unobserve(entry.target)
    })
  }, { threshold: 0.12, rootMargin: '0px 0px -50px' })
  sections.slice(1).forEach((section) => revealObserver.observe(section))
}

onMounted(restoreSession)
onMounted(loadFeaturedListings)
onMounted(loadCampusOptions)
onMounted(() => window.addEventListener('popstate', syncViewFromLocation))
onMounted(setupScrollReveal)
onBeforeUnmount(() => {
  window.removeEventListener('popstate', syncViewFromLocation)
  revealObserver?.disconnect()
})
watch(selectedCampusId, loadFeaturedListings)
watch(currentView, setupScrollReveal)
</script>

<template>
  <ToastContainer />
  <CataloguePage v-if="currentView === 'catalogue'" :show-dashboard-link="Boolean(currentUser)" @go-home="goHome" @go-dashboard="backToDashboard" @open-listing="openListing" />
  <DetailPage v-else-if="currentView === 'detail'" :listing="selectedListing" @back-to-catalogue="backToCatalogue" />
  <AuthPage v-else-if="currentView === 'auth'" :initial-mode="authMode" @go-home="goHome" @authenticated="handleAuthenticated" />
  <div v-else-if="protectedViewLoading" class="session-loading"><span class="state-spinner"></span><strong>Vérification de la session...</strong></div>
  <OwnerListingPage v-else-if="currentView === 'publish'" :property-id="selectedOwnerPropertyId" @back-to-dashboard="backToDashboard" />
  <StaticPage v-else-if="currentView === 'static'" :page="currentStaticPage" @back="goHome" />
  <DashboardPage v-else-if="currentView === 'dashboard' && currentUser" :role="currentRole" :user="currentUser" :initial-nav="dashboardInitialNav" @logout="logout" @open-catalogue="openCatalogue" @open-listing="openListing" @open-publish="openPublish" @open-admin-moderation="openAdminModeration" @open-admin-sources="openAdminSources" @open-admin-management="openAdminManagement" @user-updated="handleUserUpdated" />
  <div v-else class="app-shell">
    <div class="announcement-bar">
      <div class="container announcement-inner">
        <span class="announcement-dot"></span>
        <span>Les meilleures chambres étudiantes, au bon endroit.</span>
        <a href="#annonces" @click.prevent="scrollToSection('#annonces')">Découvrir les offres <ArrowRight :size="14" /></a>
      </div>
    </div>

    <header class="site-header">
      <div class="container nav-wrap">
        <a href="#accueil" class="brand" aria-label="FindCasa accueil" @click.prevent="scrollToSection('#accueil')">
          <span class="brand-mark"><Home :size="19" :stroke-width="2.4" /></span>
          <span>find<span>casa</span></span>
        </a>

        <nav class="desktop-nav" aria-label="Navigation principale">
          <a href="#annonces" @click.prevent="scrollToSection('#annonces')">Trouver un logement</a>
          <a href="#fonctionnement" @click.prevent="scrollToSection('#fonctionnement')">Comment ça marche</a>
          <a href="#proprietaires" @click.prevent="scrollToSection('#proprietaires')">Je suis propriétaire</a>
        </nav>

        <div class="nav-actions">
          <button class="login-button" @click="openAuth('login')">Se connecter</button>
          <button class="primary-button nav-cta" @click="openAuth('register')"><span>Créer un compte</span><ArrowRight :size="16" /></button>
          <button class="mobile-menu-button" aria-label="Ouvrir le menu" @click="mobileMenuOpen = !mobileMenuOpen">
            <X v-if="mobileMenuOpen" :size="22" />
            <Menu v-else :size="22" />
          </button>
        </div>
      </div>
      <div v-if="mobileMenuOpen" class="mobile-menu">
        <a href="#annonces" @click.prevent="scrollToSection('#annonces')">Trouver un logement</a>
        <a href="#fonctionnement" @click.prevent="scrollToSection('#fonctionnement')">Comment ça marche</a>
        <a href="#proprietaires" @click.prevent="scrollToSection('#proprietaires')">Je suis propriétaire</a>
        <button class="mobile-login-link" @click="openAuth('login')">Se connecter <ArrowRight :size="16" /></button>
        <button class="primary-button" @click="openAuth('register')">Créer un compte <ArrowRight :size="16" /></button>
      </div>
    </header>

    <main>
      <section id="accueil" class="hero-section">
        <div class="hero-orbit orbit-one"></div>
        <div class="hero-orbit orbit-two"></div>
        <div class="container hero-grid">
          <div class="hero-copy">
            <div class="eyebrow"><Sparkles :size="15" /> Pensé pour la vie étudiante</div>
            <h1>Ton chez-toi,<br /><em>près de ton campus.</em></h1>
            <p class="hero-description">FindCasa réunit les logements publiés par les propriétaires et les offres pertinentes collectées sur le web, au bon prix et près de ton campus.</p>

            <div class="hero-trust">
              <div class="avatar-stack">
                <span class="avatar avatar-one">A</span>
                <span class="avatar avatar-two">M</span>
                <span class="avatar avatar-three">K</span>
                <span class="avatar avatar-four">+</span>
              </div>
              <div><strong>Recherche locale</strong><span>Des offres autour de ton campus</span></div>
            </div>
          </div>

          <div class="hero-visual" aria-label="Aperçu d'un logement FindCasa">
            <div class="hero-image-frame">
              <img src="https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1200&q=90" alt="Intérieur lumineux d'un logement étudiant" />
              <div class="image-shade"></div>
              <div class="verified-pill"><ShieldCheck :size="15" /> Validation FindCasa</div>
              <div class="visual-caption"><div><span class="caption-kicker">Aperçu de la plateforme</span><strong>Des logements autour de ton campus</strong><span class="caption-location"><MapPin :size="13" /> Position calculée après sélection</span></div></div>
            </div>
            <div class="floating-note note-top"><span class="note-icon teal"><MapPin :size="16" /></span><span><strong>Autour du campus</strong><small>Distance calculée par l’API</small></span></div>
            <div class="floating-note note-bottom"><span class="note-icon orange"><Check :size="16" /></span><span><strong>Annonce contrôlée</strong><small>Avant publication</small></span></div>
            <span class="visual-scribble">ton futur<br />chez-toi <span>↗</span></span>
          </div>
        </div>

        <div class="container search-container">
          <div class="search-card">
            <div class="search-heading"><span class="search-icon"><Search :size="18" /></span><div><strong>Qu’est-ce que tu recherches ?</strong><span>On t’aide à trouver le logement idéal.</span></div></div>
             <div class="search-fields">
               <label class="search-field"><span class="field-label"><MapPin :size="15" /> Campus</span><select v-model="selectedCampusId" @change="campus = campusOptions.find((item) => String(item.id) === String(selectedCampusId))?.label || 'Choisir un campus'"><option value="">Choisir un campus</option><option v-for="option in campusOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select><ChevronDown :size="16" /></label>
               <label class="search-field"><span class="field-label"><Building2 :size="15" /> Type de logement</span><select v-model="selectedPropertyType"><option value="">Tous les types</option><option v-for="type in propertyTypes" :key="type" :value="type">{{ type }}</option></select><ChevronDown :size="16" /></label>
               <label class="search-field"><span class="field-label"><SlidersHorizontal :size="15" /> Budget mensuel</span><input v-model.number="budget" type="number" min="0" max="45000" step="1000" aria-label="Budget mensuel maximum" /><span class="field-suffix">FCFA / mois</span></label>
              <button class="search-submit" @click="runSearch"><Search :size="18" /><span>Rechercher</span></button>
            </div>
            <div class="search-footnote"><LockKeyhole :size="13" /> {{ searchTerm || 'Budget maximum : 45 000 FCFA par mois' }}</div>
          </div>
        </div>
      </section>

      <section class="stats-section">
        <div class="container stats-grid">
          <div class="stat-item"><strong>{{ listings.length }}</strong><small>logements visibles</small></div>
          <div class="stat-divider"></div>
          <div class="stat-item"><strong>{{ campusOptions.length }}</strong><small>campus référencés</small></div>
          <div class="stat-divider"></div>
          <div class="stat-item"><strong>45k</strong><small>budget maximum autorisé</small></div>
          <div class="stat-divider"></div>
          <div class="stat-item stat-highlight"><span class="mini-spark"><Sparkles :size="16" /></span><div><strong>Ta recherche,<br />en toute sérénité.</strong><small>Des annonces contrôlées pour toi.</small></div></div>
        </div>
      </section>

      <section class="model-section section-padding">
        <div class="container">
          <div class="section-heading-row model-heading">
            <div><div class="section-kicker">Le meilleur des deux mondes</div><h2>Deux façons de trouver.<br /><em>Un seul endroit.</em></h2></div>
            <p>Que l’offre vienne directement d’un propriétaire ou d’une source publique, elle est contrôlée avant d’être proposée aux étudiants.</p>
          </div>
          <div class="model-grid">
            <article class="model-card direct-model"><div class="model-card-top"><span class="model-icon"><Building2 :size="21" /></span><span class="model-label">Volet 01</span></div><h3>Les propriétaires publient</h3><p>Les propriétaires présentent directement leurs logements, ajoutent leurs photos, leurs équipements et gèrent les demandes des étudiants.</p><div class="model-points"><span><Check :size="14" /> Informations détaillées</span><span><Check :size="14" /> Échange direct</span><span><Check :size="14" /> Disponibilité en temps réel</span></div><a href="#proprietaires" @click.prevent="scrollToSection('#proprietaires')">Publier un logement <ArrowRight :size="15" /></a></article>
            <article class="model-card collect-model"><div class="model-card-top"><span class="model-icon"><Sparkles :size="21" /></span><span class="model-label">Volet 02</span></div><h3>FindCasa collecte pour eux</h3><p>Notre module explore des sites publics comme OWE.bj, Keur-Immo.com et CoinAfrique pour repérer des offres adaptées aux étudiants.</p><div class="model-points"><span><Check :size="14" /> Prix et proximité analysés</span><span><Check :size="14" /> Source toujours tracée</span><span><Check :size="14" /> Validation par l’administrateur</span></div><a href="#fonctionnement" @click.prevent="scrollToSection('#fonctionnement')">Comprendre la collecte <ArrowRight :size="15" /></a></article>
          </div>
          <div class="model-footnote"><ShieldCheck :size="15" /><span>Pour l’étudiant, l’expérience reste la même : une recherche unique, sans distinction visible entre les deux origines.</span></div>
        </div>
      </section>

      <section id="annonces" class="listings-section section-padding">
        <div class="container">
          <div class="section-heading-row">
            <div><div class="section-kicker">Sélection FindCasa</div><h2>Des logements qui<br /><em>te correspondent.</em></h2></div>
            <a href="/annonces" class="text-link" @click.prevent="openCatalogue">Voir toutes les annonces <ArrowRight :size="16" /></a>
          </div>
          <div class="filter-row">
            <span class="filter-label">Filtrer par :</span>
            <button v-for="filter in filters" :key="filter" class="filter-chip" :class="{ active: selectedFilter === filter }" @click="selectedFilter = filter">{{ filter }}</button>
            <span class="results-count">{{ visibleListings.length }} résultats</span>
          </div>
          <div v-if="visibleListings.length" class="listing-grid">
            <article v-for="listing in visibleListings" :key="listing.id" class="listing-card">
              <div class="listing-image-wrap">
                <img :src="listing.image" :alt="listing.title" class="listing-image" />
                <span v-if="listing.origin === 'collected'" class="listing-badge external"><ExternalLink :size="13" /> {{ listing.source_name }}</span><span v-else-if="listing.featured" class="listing-badge"><Sparkles :size="13" /> Coup de cœur</span>
                <button v-if="listing.origin !== 'collected'" class="favorite-button" :class="{ liked: favorites.includes(listing.id) }" :aria-label="favorites.includes(listing.id) ? 'Retirer des favoris' : 'Ajouter aux favoris'" @click="toggleFavorite(listing.id)"><Heart :size="18" :fill="favorites.includes(listing.id) ? 'currentColor' : 'none'" /></button>
                <span class="distance-badge"><MapPin :size="12" /> {{ listing.distance }}</span>
              </div>
              <div class="listing-content"><div class="listing-topline"><span>{{ listing.type }}</span><span v-if="listing.rating" class="rating"><Star :size="13" fill="currentColor" /> {{ listing.rating }}</span></div><h3>{{ listing.title }}</h3><p class="listing-location"><MapPin :size="14" /> {{ listing.location }}</p><div class="listing-footer"><div><strong>{{ listing.price }} <small>FCFA</small></strong><span>/ mois</span></div><button class="round-arrow" :aria-label="listing.origin === 'collected' ? 'Voir sur le site source' : 'Voir le logement'" @click="openListing(listing)"><ExternalLink v-if="listing.origin === 'collected'" :size="17" /><ArrowRight v-else :size="17" /></button></div></div>
            </article>
          </div><div v-else class="homepage-empty-listing"><Building2 :size="22" /><strong>Aucun logement publié pour le moment</strong><span>Les annonces apparaîtront ici après validation par l’administration.</span><button class="outline-button" @click="openCatalogue">Consulter le catalogue <ArrowRight :size="15" /></button></div>
        </div>
      </section>

      <section id="fonctionnement" class="feature-section section-padding">
        <div class="container feature-grid">
          <div class="feature-visual">
            <div class="map-card">
              <div class="map-toolbar"><span><MapPin :size="15" /> Abomey-Calavi</span></div>
               <div class="map-pattern"><span class="map-road road-a"></span><span class="map-road road-b"></span><span class="map-road road-c"></span><span class="map-water"></span><span class="campus-marker"><Building2 :size="13" /></span></div>
              <div class="map-legend"><span class="legend-dot"></span> Logements disponibles <span class="legend-campus"><Building2 :size="12" /> Campus</span></div>
            </div>
            <div class="feature-note"><span class="note-icon orange"><Compass :size="16" /></span><span><strong>Choisis ton campus</strong><small>et laisse-nous faire le reste.</small></span></div>
          </div>
          <div class="feature-copy"><div class="section-kicker">Une recherche plus intelligente</div><h2>Plus qu’une adresse,<br /><em>un vrai point de départ.</em></h2><p>FindCasa rassemble les offres fiables autour de ton campus pour que tu puisses comparer, visiter et réserver sans perdre ton temps.</p><div class="benefit-list"><div class="benefit"><span class="benefit-number">01</span><div><strong>Tout voir au même endroit</strong><span>Des offres publiées par des propriétaires et collectées sur le web, réunies dans une seule recherche.</span></div></div><div class="benefit"><span class="benefit-number">02</span><div><strong>Décider avec les bonnes infos</strong><span>Photos, vidéo, équipements et distance réelle : tu sais exactement où tu mets les pieds.</span></div></div><div class="benefit"><span class="benefit-number">03</span><div><strong>Réserver en toute confiance</strong><span>Chaque annonce est vérifiée et chaque échange reste simple et sécurisé.</span></div></div></div><a href="#annonces" class="outline-button" @click.prevent="scrollToSection('#annonces')">Explorer les logements <ArrowRight :size="16" /></a></div>
        </div>
      </section>

      <section id="proprietaires" class="owner-section section-padding">
        <div class="container owner-card"><div class="owner-content"><div class="section-kicker light">Pour les propriétaires</div><h2>Ton logement mérite<br /><em>les bons locataires.</em></h2><p>Publie ton bien en quelques minutes et touche les étudiants qui cherchent exactement ce que tu proposes.</p><div class="owner-points"><span><Check :size="15" /> Publication guidée</span><span><Check :size="15" /> Visibilité locale</span><span><Check :size="15" /> Gestion des demandes</span></div><button class="light-button" @click="openAuth('register')">Publier un logement <ArrowRight :size="16" /></button></div><div class="owner-image"><img src="https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea?auto=format&fit=crop&w=1100&q=85" alt="Salon d'un logement FindCasa" /><div class="owner-sticker"><Home :size="18" /><span><strong>Publication guidée</strong><small>et validation administrative</small></span></div></div></div>
      </section>

      <section class="steps-section section-padding">
        <div class="container"><div class="center-heading"><div class="section-kicker">Simple comme bonjour</div><h2>Ton prochain chez-toi<br /><em>commence ici.</em></h2></div><div class="steps-grid"><div class="step-card"><span class="step-icon"><Compass :size="21" /></span><span class="step-number">01</span><h3>Choisis ton campus</h3><p>Indique où tu étudies pour voir les logements les plus proches de toi.</p></div><div class="step-card"><span class="step-icon"><Search :size="21" /></span><span class="step-number">02</span><h3>Compare les offres</h3><p>Parcours les photos, les équipements, la distance et le budget qui te convient.</p></div><div class="step-card"><span class="step-icon"><MessageCircle :size="21" /></span><span class="step-number">03</span><h3>Échange et réserve</h3><p>Contacte le propriétaire, organise ta visite et réserve sans stress.</p></div></div></div>
      </section>

      <section class="cta-section"><div class="container cta-inner"><div><span class="section-kicker light">Prêt à commencer ?</span><h2>Ta prochaine adresse<br /><em>est peut-être ici.</em></h2></div><button class="light-button" @click="openCatalogue">Trouver mon logement <ArrowRight :size="17" /></button></div></section>
    </main>

    <footer class="site-footer"><div class="container footer-top"><div class="footer-brand"><button class="brand footer-brand-button" @click="scrollToSection('#accueil')"><span class="brand-mark"><Home :size="19" /></span><span>find<span>casa</span></span></button><p>La façon simple de trouver un logement étudiant vérifié au Bénin.</p></div><div class="footer-column"><strong>Navigation</strong><button @click="openCatalogue">Trouver un logement</button><button @click="scrollToSection('#fonctionnement')">Comment ça marche</button><button @click="openAuth('register')">Créer un compte</button></div><div class="footer-column"><strong>Informations</strong><button @click="openStaticPage('aide')">Centre d’aide</button><button @click="openStaticPage('contact')">Nous contacter</button><button @click="openStaticPage('conditions')">Conditions</button><button @click="openStaticPage('confidentialite')">Confidentialité</button></div></div><div class="container footer-bottom"><span>© 2026 FindCasa</span><span>Calavi Zogbadjè, Bénin <MapPin :size="14" /></span></div></footer>
  </div>
</template>
