<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  ChevronDown,
  ExternalLink,
  Heart,
  Home,
  List,
  Map,
  MapPin,
  Search,
  SlidersHorizontal,
  Sparkles,
} from "lucide-vue-next";
import api from "../services/api";
import { propertyTypes } from "../config/propertyTypes";
import CatalogueMap from "./CatalogueMap.vue";
import { mediaUrl as resolveMediaUrl } from "../services/mediaUrl";

const emit = defineEmits(["go-home", "go-dashboard", "open-listing"]);
defineProps({
  showDashboardLink: { type: Boolean, default: false },
  embedded: { type: Boolean, default: false },
});
const activeView = ref("list");
const searchQuery = ref("");
const maxBudget = ref(45000);
const selectedPropertyType = ref("");
const selectedDistrict = ref("");
const maxDistance = ref(15);
const selectedAmenityIds = ref([]);
const selectedCampusId = ref("");
const selectedCampus = ref("Choisir un campus");
const campusOptions = ref([]);
const favorites = ref([]);
const properties = ref([]);
const loading = ref(false);
const errorMessage = ref("");
const sort = ref("newest");
const pagination = ref({ currentPage: 1, lastPage: 1, total: 0 });
const filterOptions = ref({ districts: [], amenities: [] });
const comparedIds = ref([]);
const comparisonOpen = ref(false);
const isAuthenticated = ref(Boolean(localStorage.getItem("findcasa_token")));
let searchTimer;

const listings = computed(() =>
  properties.value.map((property) => {
    const rent = Number(
      property.minimum_rent || property.rooms?.[0]?.rent || 0,
    );
    const localMediaUrl = property.media?.[0]?.url || null;
    return {
      ...property,
      image:
        property.origin === "collected"
          ? property.external_image_url
          : resolveMediaUrl(localMediaUrl),
      price: rent,
      formattedPrice: rent
        ? new Intl.NumberFormat("fr-FR").format(rent)
        : "Prix à préciser",
      distance:
        property.distance_km !== undefined
          ? `${property.distance_km} km du campus`
          : "Distance indisponible",
      features: (property.amenities || []).map((item) => item.name),
    };
  }),
);
const comparedListings = computed(() =>
  listings.value.filter((listing) => comparedIds.value.includes(listing.id)),
);

async function loadProperties(page = 1) {
  loading.value = true;
  errorMessage.value = "";
  try {
    const response = await api.get("/properties", {
      params: {
        q: searchQuery.value || undefined,
        max_price: Math.min(Number(maxBudget.value) || 0, 45000),
        type: selectedPropertyType.value || undefined,
        district: selectedDistrict.value || undefined,
        max_distance: selectedCampusId.value ? maxDistance.value : undefined,
        amenity_ids: selectedAmenityIds.value.length
          ? selectedAmenityIds.value
          : undefined,
        campus_id: selectedCampusId.value || undefined,
        sort: sort.value,
        page,
      },
    });
    properties.value = response.data.data || [];
    pagination.value = {
      currentPage: response.data.current_page || 1,
      lastPage: response.data.last_page || 1,
      total: response.data.total || 0,
    };
  } catch (error) {
    errorMessage.value =
      error.response?.data?.message ||
      "Les logements sont momentanément indisponibles.";
  } finally {
    loading.value = false;
  }
}

async function loadFilterOptions() {
  try {
    filterOptions.value = (await api.get("/property-filters")).data.data || {
      districts: [],
      amenities: [],
    };
  } catch {
    filterOptions.value = { districts: [], amenities: [] };
  }
}

async function loadCampuses() {
  try {
    const response = await api.get("/institutions");
    campusOptions.value = (response.data.data || []).flatMap((institution) =>
      (institution.campuses || []).map((campus) => ({
        id: campus.id,
        label: `${institution.name} — ${campus.name}`,
      })),
    );
  } catch {
    campusOptions.value = [];
  }
}

async function loadFavorites() {
  if (!isAuthenticated.value) return;
  try {
    favorites.value = (await api.get("/favorites")).data.data.map(
      (favorite) => favorite.property_id,
    );
  } catch {
    favorites.value = [];
  }
}

async function toggleFavorite(id) {
  const listing = listings.value.find((item) => item.id === id);
  if (listing?.origin === "collected") return;
  if (!isAuthenticated.value) {
    window.location.href = "/connexion";
    return;
  }
  const saved = favorites.value.includes(id);
  try {
    if (saved) await api.delete(`/properties/${id}/favorite`);
    else await api.post(`/properties/${id}/favorite`);
    favorites.value = saved
      ? favorites.value.filter((item) => item !== id)
      : [...favorites.value, id];
  } catch {
    errorMessage.value = "Impossible de modifier ce favori.";
  }
}

function restoreSearch() {
  try {
    const saved = JSON.parse(sessionStorage.getItem("findcasa_search") || "{}");
    selectedCampus.value = saved.campus || selectedCampus.value;
    selectedCampusId.value = saved.campusId || "";
    maxBudget.value = saved.budget
      ? Math.min(Number(saved.budget), 45000)
      : 45000;
    selectedPropertyType.value = propertyTypes.includes(saved.propertyType)
      ? saved.propertyType
      : "";
  } catch {
    sessionStorage.removeItem("findcasa_search");
  }
}

function changeCampus() {
  selectedCampus.value =
    campusOptions.value.find(
      (item) => String(item.id) === String(selectedCampusId.value),
    )?.label || "Choisir un campus";
  sessionStorage.setItem(
    "findcasa_search",
    JSON.stringify({
      campus: selectedCampus.value,
      campusId: selectedCampusId.value,
      propertyType: selectedPropertyType.value,
      budget: maxBudget.value,
    }),
  );
  loadProperties(1);
}

function toggleAmenity(id) {
  selectedAmenityIds.value = selectedAmenityIds.value.includes(id)
    ? selectedAmenityIds.value.filter((item) => item !== id)
    : [...selectedAmenityIds.value, id];
}

function toggleCompare(id) {
  if (comparedIds.value.includes(id))
    comparedIds.value = comparedIds.value.filter((item) => item !== id);
  else if (comparedIds.value.length < 3) comparedIds.value.push(id);
}

function resetFilters() {
  searchQuery.value = "";
  maxBudget.value = 45000;
  selectedPropertyType.value = "";
  selectedDistrict.value = "";
  maxDistance.value = 15;
  selectedAmenityIds.value = [];
}

onMounted(() => {
  restoreSearch();
  loadProperties();
  loadCampuses();
  loadFavorites();
  loadFilterOptions();
});
onBeforeUnmount(() => clearTimeout(searchTimer));
watch(
  [
    selectedPropertyType,
    selectedDistrict,
    maxDistance,
    selectedAmenityIds,
    maxBudget,
    sort,
  ],
  () => loadProperties(1),
  { deep: true },
);
watch(searchQuery, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadProperties(1), 350);
});
</script>

<template>
  <div class="catalogue-shell" :class="{ embedded }">
    <header v-if="!embedded" class="catalogue-header">
      <div class="container catalogue-nav">
        <button class="back-brand" @click="emit('go-home')"><span class="brand-mark"><Home :size="18" /></span><span>find<span>casa</span></span></button>
        <label class="catalogue-campus"
          ><MapPin :size="15" /><span>Campus de référence</span
          ><select v-model="selectedCampusId" @change="changeCampus">
            <option value="">Choisir un campus</option>
            <option
              v-for="campus in campusOptions"
              :key="campus.id"
              :value="campus.id"
            >
              {{ campus.label }}
            </option></select
          ><ChevronDown :size="14"
        /></label>
        <div class="catalogue-user">
          <span v-if="isAuthenticated" class="catalogue-favorite-count"
            ><Heart :size="17" /> {{ favorites.length }}</span
          ><a v-if="!isAuthenticated" class="catalogue-login" href="/connexion">Se connecter <ArrowRight :size="14" /></a>
        </div>
      </div>
    </header>

    <main class="catalogue-main container">
      <div v-if="!embedded" class="catalogue-breadcrumb">
        <button v-if="showDashboardLink" @click="emit('go-dashboard')">
          <ArrowLeft :size="14" /> Mon espace</button
        ><span v-if="showDashboardLink">/</span
        ><button @click="emit('go-home')">Accueil</button><span>/</span
        ><strong>Trouver un logement</strong>
      </div>
      <div class="catalogue-heading">
        <div class="catalogue-heading-copy">
          <div class="section-kicker">Catalogue FindCasa</div>
          <h1>Les logements qui<br /><em>t’attendent.</em></h1>
          <p>
            {{ pagination.total }} logement{{
              pagination.total > 1 ? "s" : ""
            }}
            disponible{{ pagination.total > 1 ? "s" : "" }}
          </p>
        </div>
        <div class="catalogue-heading-tools">
          <label class="catalogue-search"
            ><Search :size="17" /><input
              v-model="searchQuery"
              placeholder="Rechercher un quartier, une résidence..."
          /></label>
          <div class="catalogue-heading-note">
            <Sparkles :size="16" /><span
              ><strong>Données en temps réel</strong
              ><small>Les annonces viennent de la plateforme.</small></span
            >
          </div>
        </div>
      </div>

      <div class="catalogue-toolbar">
        <label class="catalogue-sort"
          >Trier par
          <select v-model="sort">
            <option value="newest">Plus récentes</option>
            <option value="price_asc">Prix croissant</option>
            <option value="price_desc">Prix décroissant</option>
            <option value="oldest">Plus anciennes</option>
          </select></label
        >
        <div class="catalogue-view-switch">
          <button
            :class="{ active: activeView === 'list' }"
            @click="activeView = 'list'"
          >
            <List :size="16" /> Liste</button
          ><button
            :class="{ active: activeView === 'map' }"
            @click="activeView = 'map'"
          >
            <Map :size="16" /> Carte
          </button>
        </div>
      </div>

      <div class="catalogue-layout">
        <aside class="filter-panel">
          <div class="filter-panel-title">
            <strong>Affiner la recherche</strong
            ><SlidersHorizontal :size="16" />
          </div>
          <div v-if="embedded" class="catalogue-control">
            <span>Établissement et campus</span
            ><label class="catalogue-type-select"
              ><MapPin :size="14" /><select
                v-model="selectedCampusId"
                @change="changeCampus"
              >
                <option value="">Tous les établissements</option>
                <option
                  v-for="campus in campusOptions"
                  :key="campus.id"
                  :value="campus.id"
                >
                  {{ campus.label }}
                </option></select
              ><ChevronDown :size="14" /></label
            ><small class="budget-limit"
              >Le campus sélectionné sert au calcul de la distance.</small
            >
          </div>
          <div class="catalogue-control">
            <span>Budget maximum</span
            ><label class="catalogue-budget-input"
              ><input
                v-model.number="maxBudget"
                type="number"
                min="0"
                max="45000"
                step="1000"
              /><span>FCFA</span></label
            ><small class="budget-limit">Maximum autorisé : 45 000 FCFA</small>
          </div>
          <div class="catalogue-control">
            <span>Type de logement</span
            ><label class="catalogue-type-select"
              ><Building2 :size="14" /><select v-model="selectedPropertyType">
                <option value="">Tous les types</option>
                <option v-for="type in propertyTypes" :key="type" :value="type">
                  {{ type }}
                </option></select
              ><ChevronDown :size="14"
            /></label>
          </div>
          <div class="catalogue-control">
            <span>Quartier</span
            ><label class="catalogue-type-select"
              ><MapPin :size="14" /><select v-model="selectedDistrict">
                <option value="">Tous les quartiers</option>
                <option
                  v-for="district in filterOptions.districts"
                  :key="district"
                  :value="district"
                >
                  {{ district }}
                </option></select
              ><ChevronDown :size="14"
            /></label>
          </div>
          <div class="catalogue-control">
            <span>Distance maximale</span
            ><label class="catalogue-budget-input"
              ><input
                v-model.number="maxDistance"
                type="number"
                min="1"
                max="15"
                step="1"
                :disabled="!selectedCampusId"
              /><span>km</span></label
            ><small class="budget-limit"
              >Choisis un campus pour filtrer la distance.</small
            >
          </div>
          <div class="catalogue-control">
            <span>Équipements obligatoires</span>
            <div class="catalogue-amenity-filter">
              <button
                v-for="amenity in filterOptions.amenities"
                :key="amenity.id"
                :class="{ active: selectedAmenityIds.includes(amenity.id) }"
                @click="toggleAmenity(amenity.id)"
              >
                {{ amenity.name }}
              </button>
            </div>
          </div>
          <button class="catalogue-reset" @click="resetFilters">
            Réinitialiser les filtres
          </button>
        </aside>

        <section class="catalogue-results">
          <div v-if="loading" class="catalogue-state">
            <span class="state-spinner"></span
            ><strong>Chargement des logements...</strong>
          </div>
          <div v-else-if="errorMessage" class="catalogue-state error-state">
            <Search :size="26" /><strong>{{ errorMessage }}</strong
            ><button @click="loadProperties(pagination.currentPage)">
              Réessayer
            </button>
          </div>
          <div v-else-if="!listings.length" class="catalogue-state">
            <Building2 :size="28" />
            <h2>Aucun logement publié</h2>
            <p>Modifie les filtres ou reviens plus tard.</p>
          </div>
          <CatalogueMap
            v-else-if="activeView === 'map'"
            :listings="listings"
            @open-listing="emit('open-listing', $event)"
          />
          <div v-else class="catalogue-cards">
            <article
              v-for="listing in listings"
              :key="listing.id"
              class="catalogue-card"
              :class="{ external: listing.origin === 'collected' }"
            >
              <div class="catalogue-card-image">
                <img
                  v-if="listing.image"
                  :src="listing.image"
                  :alt="listing.title"
                />
                <div v-else class="catalogue-image-empty">
                  <ExternalLink
                    v-if="listing.origin === 'collected'"
                    :size="22"
                  /><Building2 v-else :size="22" /><span>{{
                    listing.origin === "collected"
                      ? "Voir les médias sur le site source"
                      : "Média indisponible"
                  }}</span>
                </div>
                <span class="catalogue-verified">{{
                  listing.origin === "collected"
                    ? listing.external_source?.name || "Source externe"
                    : "Vérifié"
                }}</span
                ><button
                  v-if="listing.origin !== 'collected'"
                  class="catalogue-heart"
                  :class="{ liked: favorites.includes(listing.id) }"
                  :aria-label="
                    favorites.includes(listing.id)
                      ? 'Retirer des favoris'
                      : 'Ajouter aux favoris'
                  "
                  @click="toggleFavorite(listing.id)"
                >
                  <Heart
                    :size="17"
                    :fill="
                      favorites.includes(listing.id) ? 'currentColor' : 'none'
                    "
                  />
                </button>
              </div>
              <div class="catalogue-card-body">
                <div class="card-meta">
                  <span>{{ listing.type }}</span
                  ><span>{{ listing.distance }}</span>
                </div>
                <h2>{{ listing.title }}</h2>
                <p class="card-location">
                  <MapPin :size="14" /> {{ listing.district }}
                </p>
                <div class="card-features">
                  <span
                    v-for="feature in listing.features.slice(0, 4)"
                    :key="feature"
                    >{{ feature }}</span
                  >
                </div>
                <label
                  v-if="listing.origin !== 'collected'"
                  class="compare-checkbox"
                  ><input
                    type="checkbox"
                    :checked="comparedIds.includes(listing.id)"
                    :disabled="
                      !comparedIds.includes(listing.id) &&
                      comparedIds.length >= 3
                    "
                    @change="toggleCompare(listing.id)"
                  />
                  Comparer</label
                >
                <p v-else class="external-source-notice">
                  Cette offre est consultée uniquement sur le site d’origine.
                </p>
                <div class="card-bottom">
                  <div>
                    <strong
                      >{{ listing.formattedPrice }}
                      <small v-if="listing.price">FCFA</small></strong
                    ><span v-if="listing.price">/ mois</span>
                  </div>
                  <button
                    class="card-detail-button"
                    @click="emit('open-listing', listing)"
                  >
                    {{
                      listing.origin === "collected"
                        ? "Voir sur le site source"
                        : "Voir le logement"
                    }}
                    <ExternalLink
                      v-if="listing.origin === 'collected'"
                      :size="15"
                    /><ArrowRight v-else :size="15" />
                  </button>
                </div>
              </div>
            </article>
          </div>

          <nav
            v-if="pagination.lastPage > 1"
            class="catalogue-pagination"
            aria-label="Pagination"
          >
            <button
              :disabled="pagination.currentPage === 1"
              @click="loadProperties(pagination.currentPage - 1)"
            >
              Précédent</button
            ><span
              >Page {{ pagination.currentPage }} sur
              {{ pagination.lastPage }}</span
            ><button
              :disabled="pagination.currentPage === pagination.lastPage"
              @click="loadProperties(pagination.currentPage + 1)"
            >
              Suivant
            </button>
          </nav>
        </section>
      </div>
    </main>
    <button
      v-if="comparedIds.length >= 2"
      class="comparison-launcher"
      @click="comparisonOpen = true"
    >
      Comparer {{ comparedIds.length }} logements
    </button>
    <div
      v-if="comparisonOpen"
      class="comparison-overlay"
      @click.self="comparisonOpen = false"
    >
      <section class="comparison-panel">
        <header>
          <div>
            <span class="section-kicker">Comparaison</span>
            <h2>Compare tes choix.</h2>
          </div>
          <button @click="comparisonOpen = false">Fermer</button>
        </header>
        <div class="comparison-grid">
          <article v-for="listing in comparedListings" :key="listing.id">
            <img
              v-if="listing.image"
              :src="listing.image"
              :alt="listing.title"
            />
            <div v-else class="catalogue-image-empty">
              <Building2 :size="22" />
            </div>
            <h3>{{ listing.title }}</h3>
            <dl>
              <div>
                <dt>Loyer</dt>
                <dd>{{ listing.formattedPrice }} FCFA</dd>
              </div>
              <div>
                <dt>Distance</dt>
                <dd>{{ listing.distance }}</dd>
              </div>
              <div>
                <dt>Type</dt>
                <dd>{{ listing.type }}</dd>
              </div>
              <div>
                <dt>Quartier</dt>
                <dd>{{ listing.district }}</dd>
              </div>
              <div>
                <dt>Équipements</dt>
                <dd>{{ listing.features.join(", ") || "Non renseignés" }}</dd>
              </div>
            </dl>
            <button
              class="dashboard-primary"
              @click="emit('open-listing', listing)"
            >
              Voir le logement
            </button>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>
