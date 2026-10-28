<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import {
  ArrowRight,
  Bell,
  Building2,
  CalendarDays,
  Check,
  ChevronRight,
  Heart,
  Home,
  LayoutDashboard,
  LogOut,
  MessageCircle,
  Plus,
  Save,
  Search,
  Settings,
  ShieldCheck,
  Star,
  X,
} from "lucide-vue-next";
import api from "../services/api";
import NotificationPanel from "./NotificationPanel.vue";
import { statusLabel, statusTone } from "../config/statusLabels";
import { mediaUrl } from "../services/mediaUrl";
import { getEcho } from "../services/realtime";
import { onNotificationSync, syncAllNotificationsRead, syncNotificationRead } from "../services/notificationSync";
import AdminModerationPage from "./AdminModerationPage.vue";
import AdminSourcesPage from "./AdminSourcesPage.vue";
import AdminManagementPage from "./AdminManagementPage.vue";
import OwnerListingPage from "./OwnerListingPage.vue";
import CataloguePage from "./CataloguePage.vue";
import DetailPage from "./DetailPage.vue";
const props = defineProps({
  role: { type: String, default: "student" },
  user: { type: Object, default: null },
  initialNav: { type: String, default: "Vue d’ensemble" },
});
const rawEmit = defineEmits([
  "logout",
  "open-catalogue",
  "open-publish",
  "open-listing",
  "open-admin-moderation",
  "open-admin-sources",
  "open-admin-management",
  "user-updated",
]);
const activeNav = ref(props.initialNav);
const loading = ref(false);
const errorMessage = ref("");
const reservations = ref([]);
const properties = ref([]);
const favorites = ref([]);
const conversations = ref([]);
const activeConversation = ref(null);
const messages = ref([]);
const messageBody = ref("");
const reviews = ref([]);
const reviewDrafts = reactive({});
const externalRequests = ref([]);
const notifications = ref([]);
const unreadNotifications = ref(0);
let stopNotificationSync;
const adminStats = ref({});
const adminPending = ref({
  owners: 0,
  properties: 0,
  media: 0,
  reviews: 0,
  reports: 0,
});
const newRooms = reactive({});
const roomMessage = ref("");
const deleteConfirmation = ref(null);
const institutions = ref([]);
const profileMessage = ref("");
const verificationMessage = ref("");
const savingProfile = ref(false);
const profileForm = ref({
  name: "",
  phone: "",
  institution_id: "",
  current_password: "",
  password: "",
  password_confirmation: "",
});
const publishingPropertyId = ref(null);
const selectedDashboardListing = ref(null);

function emit(event, ...args) {
  if (event === "open-catalogue") {
    activeNav.value = "Trouver un logement";
    return;
  }
  if (event === "open-publish") {
    publishingPropertyId.value = args[0]?.id || null;
    activeNav.value = "Publier un logement";
    return;
  }
  const adminViews = {
    "open-admin-moderation": "À modérer",
    "open-admin-sources": "Sources de collecte",
    "open-admin-management": "Gestion",
  };
  if (adminViews[event]) {
    activeNav.value = adminViews[event];
    return;
  }
  rawEmit(event, ...args);
}

const info = computed(() => {
  const labels = {
    student: "Étudiant",
    owner: "Propriétaire",
    admin: "Administrateur",
  };
  const name = props.user?.name || labels[props.role] || "Utilisateur";
  return {
    name,
    label: labels[props.role] || "Utilisateur",
    initials: name
      .split(" ")
      .filter(Boolean)
      .map((part) => part[0])
      .join("")
      .slice(0, 2)
      .toUpperCase(),
    campus: props.user?.institution?.name || "FindCasa",
  };
});

const navItems = computed(() =>
  props.role === "student"
      ? [
          "Vue d’ensemble",
          "Trouver un logement",
          "Mes favoris",
        "Mes réservations",
        "Mes avis",
        "Messages",
        "Notifications",
      ]
    : props.role === "owner"
      ? [
        "Vue d’ensemble",
          "Publier un logement",
          "Mes logements",
          "Demandes reçues",
          "Messages",
          "Notifications",
        ]
      : [
          "Vue d’ensemble",
          "À modérer",
          "Sources de collecte",
          "Gestion",
          "Notifications",
        ],
);
const reviewedReservationIds = computed(
  () => new Set(reviews.value.map((review) => review.reservation_id)),
);
const reviewableReservations = computed(() =>
  reservations.value.filter(
    (reservation) =>
      reservation.status === "completed" &&
      !reviewedReservationIds.value.has(reservation.id),
  ),
);
const unreadMessages = computed(() =>
  conversations.value.reduce(
    (total, conversation) =>
      total + Number(conversation.unread_messages_count || 0),
    0,
  ),
);
const activeReservations = computed(() =>
  reservations.value.filter((reservation) =>
    ["pending", "accepted", "confirmed", "rented"].includes(reservation.status),
  ),
);
const availableRooms = computed(() =>
  properties.value.reduce(
    (total, property) =>
      total +
      (property.rooms || []).filter((room) => room.status === "available")
        .length,
    0,
  ),
);
const pendingOwnerRequests = computed(() =>
  reservations.value.filter((reservation) => reservation.status === "pending"),
);
const recentFavorites = computed(() => favorites.value.slice(0, 3));
const recentReservations = computed(() => reservations.value.slice(0, 3));
const recentProperties = computed(() => properties.value.slice(0, 3));
const activeAdminComponent = computed(
  () =>
    ({
      "À modérer": AdminModerationPage,
      "Sources de collecte": AdminSourcesPage,
      Gestion: AdminManagementPage,
    })[activeNav.value] || null,
);

async function loadDashboard() {
  loading.value = true;
  errorMessage.value = "";
  if (props.user && !props.user.email_verified_at && props.role !== "admin") {
    loading.value = false;
    return;
  }
  try {
    if (props.role === "student") {
      const reservationResponse = await api.get("/reservations", {
        timeout: 30000,
      });
      const favoriteResponse = await api.get("/favorites", { timeout: 30000 });
      const conversationResponse = await api.get("/conversations", {
        timeout: 30000,
      });
      const reviewResponse = await api.get("/reviews/mine", { timeout: 30000 });
      const externalResponse = await api.get("/external-contact-requests", {
        timeout: 30000,
      });
      reservations.value = reservationResponse.data.data || [];
      reservations.value
        .filter((reservation) => reservation.status === "completed")
        .forEach((reservation) => {
          reviewDrafts[reservation.id] ||= { rating: 5, comment: "" };
        });
      favorites.value = favoriteResponse.data.data || [];
      conversations.value = conversationResponse.data.data || [];
      reviews.value = reviewResponse.data.data || [];
      externalRequests.value = externalResponse.data.data || [];
    } else if (props.role === "owner") {
      const propertyResponse = await api.get("/owner/properties", {
        timeout: 30000,
      });
      const reservationResponse = await api.get("/owner/reservations", {
        timeout: 30000,
      });
      properties.value = propertyResponse.data.data || [];
      properties.value.forEach((property) => {
        newRooms[property.id] = {
          name: `Chambre ${property.rooms.length + 1}`,
          rent: 45000,
          deposit: 0,
          advance: 0,
          additional_fees: 0,
        };
      });
      reservations.value = reservationResponse.data.data || [];
      try {
        conversations.value =
          (await api.get("/conversations", { timeout: 30000 })).data.data || [];
      } catch {
        conversations.value = [];
      }
    } else if (props.role === "admin") {
      const statisticsResponse = await api.get("/admin/statistics", {
        timeout: 30000,
      });
      adminStats.value = statisticsResponse.data.data || {};
      const [
        ownersResponse,
        propertiesResponse,
        mediaResponse,
        reviewsResponse,
        reportsResponse,
      ] = await Promise.all([
        api.get("/admin/owners/pending", { timeout: 30000 }),
        api.get("/admin/properties/pending", { timeout: 30000 }),
        api.get("/admin/media/pending", { timeout: 30000 }),
        api.get("/admin/reviews/pending", { timeout: 30000 }),
        api.get("/admin/reports/pending", { timeout: 30000 }),
      ]);
      const total = (response) =>
        response.data.data?.total ?? response.data.data?.length ?? 0;
      adminPending.value = {
        owners: total(ownersResponse),
        properties: total(propertiesResponse),
        media: total(mediaResponse),
        reviews: total(reviewsResponse),
        reports: total(reportsResponse),
      };
    }
  } catch (error) {
    errorMessage.value =
      error.response?.data?.message || "Impossible de charger cet espace.";
  } finally {
    loading.value = false;
  }
}

async function leavePublishing() {
  publishingPropertyId.value = null;
  activeNav.value = "Mes logements";
  await loadDashboard();
}

async function handlePropertyPublished() {
  await loadDashboard();
}

async function resendVerification() {
  verificationMessage.value = "";
  try {
    verificationMessage.value = (
      await api.post("/auth/email/verification-notification")
    ).data.message;
  } catch (error) {
    verificationMessage.value =
      error.response?.data?.message || "Impossible d’envoyer le lien.";
  }
}

async function loadNotifications() {
  try {
    const response = await api.get("/notifications");
    notifications.value = response.data.data?.data || response.data.data || [];
    unreadNotifications.value = notifications.value.filter(
      (item) => !item.read_at,
    ).length;
  } catch {
    /* Notification errors must not block the dashboard. */
  }
}

async function readNotification(notification) {
  if (!notification.read_at) {
    await api.post(`/notifications/${notification.id}/read`);
    notification.read_at = new Date().toISOString();
    unreadNotifications.value = Math.max(0, unreadNotifications.value - 1);
    syncNotificationRead(notification.id);
  }
  routeNotification(notification);
}

function routeNotification(notification) {
  if (notification.data?.conversation_id) {
    activeNav.value = "Messages";
    refreshConversations(notification.data.conversation_id);
  } else if (notification.data?.report_id && props.role === "admin") {
    activeNav.value = "À modérer";
  } else if (notification.data?.review_id) {
    activeNav.value = props.role === "admin" ? "À modérer" : "Mes avis";
  } else if (notification.data?.source_id && props.role === "admin") {
    activeNav.value = "Sources de collecte";
  } else if ((notification.data?.owner_id || notification.data?.media_id) && props.role === "admin") {
    activeNav.value = "À modérer";
  } else if (notification.data?.reservation_id) {
    activeNav.value =
      props.role === "owner" ? "Demandes reçues" : "Mes réservations";
  } else if (notification.data?.property_id) {
    if (props.role === "owner") activeNav.value = "Mes logements";
    else if (props.role === "admin") activeNav.value = "À modérer";
    else activeNav.value = "Trouver un logement";
  }
}

async function markAllNotificationsAsRead() {
  if (!unreadNotifications.value) return;
  await api.post("/notifications/read-all");
  const now = new Date().toISOString();
  notifications.value.forEach((notification) => {
    notification.read_at ||= now;
  });
  unreadNotifications.value = 0;
  syncAllNotificationsRead();
}

async function refreshConversations(openConversationId = null) {
  const response = await api.get("/conversations");
  conversations.value = response.data.data || [];
  const targetId = openConversationId || activeConversation.value?.id;
  if (targetId) {
    const target = conversations.value.find(
      (item) => item.id === Number(targetId),
    );
    if (target) await openConversation(target);
  }
}

async function openProfile() {
  activeNav.value = "Mon profil";
  profileMessage.value = "";
  try {
    const [profileResponse, institutionResponse] = await Promise.all([
      api.get("/profile"),
      api.get("/institutions"),
    ]);
    const profile = profileResponse.data.data;
    institutions.value = institutionResponse.data.data || [];
    profileForm.value = {
      name: profile.name || "",
      phone: profile.phone || "",
      institution_id: profile.institution_id || "",
      current_password: "",
      password: "",
      password_confirmation: "",
    };
  } catch (error) {
    profileMessage.value =
      error.response?.data?.message || "Impossible de charger le profil.";
  }
}

async function saveProfile() {
  savingProfile.value = true;
  profileMessage.value = "";
  try {
    const payload = {
      name: profileForm.value.name,
      phone: profileForm.value.phone,
      institution_id: profileForm.value.institution_id || null,
    };
    if (profileForm.value.password)
      Object.assign(payload, {
        current_password: profileForm.value.current_password,
        password: profileForm.value.password,
        password_confirmation: profileForm.value.password_confirmation,
      });
    const response = await api.patch("/profile", payload);
    profileMessage.value = response.data.message;
    profileForm.value.current_password = "";
    profileForm.value.password = "";
    profileForm.value.password_confirmation = "";
    emit("user-updated", response.data.data);
  } catch (error) {
    profileMessage.value =
      error.response?.data?.message || "Impossible de modifier le profil.";
  } finally {
    savingProfile.value = false;
  }
}

function setNav(item) {
  if (item === "Trouver un logement") selectedDashboardListing.value = null;
  if (item === "Mon profil") openProfile();
  else {
    activeNav.value = item;
    if (item === "Notifications") loadNotifications();
    if (item === "Messages") refreshConversations();
  }
}

function openStudentListing(listing) {
  selectedDashboardListing.value = listing;
  activeNav.value = "Trouver un logement";
  window.scrollTo({ top: 0, behavior: "smooth" });
}

async function openConversation(conversation) {
  activeConversation.value = conversation;
  messages.value =
    (await api.get(`/conversations/${conversation.id}/messages`)).data.data ||
    [];
  conversation.unread_messages_count = 0;
}

async function sendMessage() {
  if (!activeConversation.value || !messageBody.value.trim()) return;
  const response = await api.post(
    `/conversations/${activeConversation.value.id}/messages`,
    { body: messageBody.value },
  );
  messages.value.push(response.data.data);
  messageBody.value = "";
}

async function respondReservation(reservation, status) {
  const response = await api.patch(`/owner/reservations/${reservation.id}`, {
    status,
  });
  reservation.status = response.data.data.status;
  if (status === "accepted" && response.data.conversation_id) {
    activeNav.value = "Messages";
    await refreshConversations(response.data.conversation_id);
  }
}

async function confirmReservation(reservation) {
  const response = await api.post(`/reservations/${reservation.id}/confirm`);
  reservation.status = "confirmed";
  activeNav.value = "Messages";
  await refreshConversations(response.data.conversation_id);
}

async function cancelReservation(reservation) {
  const response = await api.post(`/reservations/${reservation.id}/cancel`);
  reservation.status = response.data.data.status;
  reservation.history = response.data.data.history || reservation.history;
}

async function markReservationRented(reservation) {
  const response = await api.post(
    `/owner/reservations/${reservation.id}/rented`,
  );
  reservation.status = response.data.data.status;
  reservation.room.status = "rented";
}

async function completeReservation(reservation) {
  const response = await api.post(
    `/owner/reservations/${reservation.id}/complete`,
  );
  reservation.status = response.data.data.status;
  reservation.room.status = "available";
}

async function submitReview(reservation) {
  const draft = reviewDrafts[reservation.id];
  if (!draft?.rating) return;
  try {
    const response = await api.post("/reviews", {
      reservation_id: reservation.id,
      rating: Number(draft.rating),
      comment: draft.comment || null,
    });
    reviews.value.unshift({
      ...response.data.data,
      property: reservation.room?.property,
    });
    delete reviewDrafts[reservation.id];
  } catch (error) {
    errorMessage.value =
      error.response?.data?.message || "Impossible d’envoyer cet avis.";
  }
}

async function updateRoom(property, room) {
  roomMessage.value = "";
  try {
    const response = await api.patch(`/owner/rooms/${room.id}`, {
      name: room.name,
      rent: Number(room.rent),
      deposit: Number(room.deposit || 0),
      advance: Number(room.advance || 0),
      additional_fees: Number(room.additional_fees || 0),
      status: room.status,
    });
    Object.assign(room, response.data.data);
    if (property.publication_status === "published")
      property.publication_status = "pending";
    roomMessage.value = "Chambre mise à jour.";
  } catch (error) {
    roomMessage.value =
      error.response?.data?.message || "Impossible de modifier la chambre.";
  }
}

async function addRoom(property) {
  roomMessage.value = "";
  try {
    const response = await api.post(
      `/owner/properties/${property.id}/rooms`,
      newRooms[property.id],
    );
    property.rooms.push(response.data.data);
    newRooms[property.id] = {
      name: `Chambre ${property.rooms.length + 1}`,
      rent: 45000,
      deposit: 0,
      advance: 0,
      additional_fees: 0,
    };
    roomMessage.value = "Chambre ajoutée.";
  } catch (error) {
    roomMessage.value =
      error.response?.data?.message || "Impossible d’ajouter la chambre.";
  }
}

async function deleteRoom(property, room) {
  try {
    await api.delete(`/owner/rooms/${room.id}`);
    property.rooms = property.rooms.filter((item) => item.id !== room.id);
    roomMessage.value = "Chambre supprimée.";
  } catch (error) {
    roomMessage.value =
      error.response?.data?.message || "Impossible de supprimer la chambre.";
  }
}

async function deleteProperty(property) {
  try {
    await api.delete(`/owner/properties/${property.id}`);
    properties.value = properties.value.filter(
      (item) => item.id !== property.id,
    );
  } catch (error) {
    roomMessage.value =
      error.response?.data?.message || "Impossible de supprimer cette annonce.";
  }
}

function askDeleteRoom(property, room) {
  deleteConfirmation.value = {
    type: "room",
    property,
    room,
    title: `Supprimer ${room.name} ?`,
    message:
      "Cette action est définitive si aucune réservation active ne bloque la suppression.",
  };
}
function askDeleteProperty(property) {
  deleteConfirmation.value = {
    type: "property",
    property,
    title: `Supprimer « ${property.title} » ?`,
    message:
      "Le logement, ses chambres et ses médias seront supprimés définitivement.",
  };
}
async function confirmDeletion() {
  const confirmation = deleteConfirmation.value;
  deleteConfirmation.value = null;
  if (confirmation?.type === "room")
    await deleteRoom(confirmation.property, confirmation.room);
  if (confirmation?.type === "property")
    await deleteProperty(confirmation.property);
}

function titleFor(reservation) {
  return reservation.room?.property?.title || "Logement";
}
function money(value) {
  return value === null || value === undefined
    ? "Non renseigné"
    : `${Number(value).toLocaleString("fr-FR")} FCFA`;
}
function imageUrl(property) {
  return mediaUrl(property?.media?.[0]?.url);
}

watch(() => [props.role, props.user?.id], loadDashboard);
watch(
  () => props.initialNav,
  (value) => {
    activeNav.value = value || "Vue d’ensemble";
  },
);
watch(
  () => props.user,
  (user) => {
    if (user)
      profileForm.value = {
        ...profileForm.value,
        name: user.name || "",
        phone: user.phone || "",
        institution_id: user.institution_id || "",
      };
  },
  { immediate: true },
);
onMounted(loadDashboard);
onMounted(() => {
  const channel = getEcho()?.private(`App.Models.User.${props.user?.id}`);
  channel?.listen(".message.sent", async (event) => {
    await refreshConversations(event.message?.conversation_id);
  });
  channel?.notification((notification) => {
    notifications.value = [
      {
        id: notification.id || `${Date.now()}`,
        data: notification,
        read_at: null,
        created_at: new Date().toISOString(),
      },
      ...notifications.value,
    ];
    unreadNotifications.value += 1;
  });
  stopNotificationSync = onNotificationSync(({ id, all }) => {
    const now = new Date().toISOString();
    if (all) notifications.value.forEach((notification) => { notification.read_at ||= now; });
    else {
      const item = notifications.value.find((notification) => String(notification.id) === String(id));
      if (item) item.read_at ||= now;
    }
    unreadNotifications.value = notifications.value.filter((notification) => !notification.read_at).length;
  });
});
onBeforeUnmount(() => stopNotificationSync?.());
</script>

<template>
  <div class="dashboard-shell">
    <aside class="dashboard-sidebar">
      <button class="dashboard-logo" @click="emit('open-catalogue')">
        <span class="brand-mark"><Home :size="18" /></span
        ><span>find<span>casa</span></span></button
      ><button class="dashboard-profile" type="button" @click="openProfile">
        <span class="dashboard-avatar">{{ info.initials }}</span
        ><span class="dashboard-profile-copy"
          ><strong>{{ info.name }}</strong
          ><small>{{ info.label }}</small></span
        ><ChevronRight :size="14" />
      </button>
      <nav class="dashboard-nav">
        <span class="dashboard-nav-label">Menu</span
        ><button
          v-for="item in navItems"
          :key="item"
          :class="{ active: activeNav === item }"
          @click="setNav(item)"
        >
          <LayoutDashboard v-if="item === 'Vue d’ensemble'" :size="16" /><Search
            v-else-if="item === 'Trouver un logement'"
            :size="16"
          /><Heart
            v-else-if="item === 'Mes favoris'"
            :size="16"
          /><CalendarDays
            v-else-if="
              item === 'Mes réservations' || item === 'Demandes reçues'
            "
            :size="16"
          /><Star v-else-if="item === 'Mes avis'" :size="16" /><Plus
            v-else-if="item === 'Publier un logement'"
            :size="16"
          /><Building2
            v-else-if="
              item === 'Mes logements' ||
              item === 'À modérer' ||
              item === 'Sources de collecte'
            "
            :size="16"
          /><Settings v-else-if="item === 'Gestion'" :size="16" /><Bell
            v-else-if="item === 'Notifications'"
            :size="16"
          /><MessageCircle v-else :size="16" />{{ item
          }}<b
            v-if="item === 'Messages' && unreadMessages"
            class="dashboard-nav-badge"
            >{{ unreadMessages > 99 ? "99+" : unreadMessages }}</b
          ><b
            v-if="item === 'Notifications' && unreadNotifications"
            class="dashboard-nav-badge"
            >{{ unreadNotifications > 99 ? "99+" : unreadNotifications }}</b
          >
        </button>
      </nav>
      <div class="dashboard-sidebar-bottom">
        <button
          :class="{ active: activeNav === 'Mon profil' }"
          @click="openProfile"
        >
          <Settings :size="16" /> Mon profil</button
        ><button class="logout-button" @click="emit('logout')">
          <LogOut :size="16" /> Se déconnecter
        </button>
      </div>
    </aside>
    <main class="dashboard-main">
      <header class="dashboard-header">
        <div>
          <span class="dashboard-breadcrumb">{{ info.campus }}</span>
          <h1>Bonjour, {{ info.name.split(" ")[0] }}</h1>
        </div>
        <div class="dashboard-header-actions">
          <NotificationPanel
            :user-id="user.id"
            @unread-change="unreadNotifications = $event"
            @open-list="activeNav = 'Notifications'"
            @select="routeNotification"
          /><button
            class="dashboard-header-avatar"
            type="button"
            aria-label="Ouvrir mon profil"
            @click="openProfile"
          >
            {{ info.initials }}
          </button>
        </div>
      </header>
      <div
        v-if="user && !user.email_verified_at && role !== 'admin'"
        class="email-verification-banner"
      >
        <div>
          <strong>Vérifie ton adresse email</strong>
          <p>
            Un lien a été envoyé à {{ user.email }}. La publication, les
            favoris, messages et réservations seront disponibles après
            vérification.
          </p>
          <span v-if="verificationMessage">{{ verificationMessage }}</span>
        </div>
        <button @click="resendVerification">Renvoyer le lien</button>
      </div>
      <div v-if="errorMessage" class="dashboard-error">{{ errorMessage }}</div>
      <div v-if="loading" class="dashboard-loading">
        <span class="state-spinner"></span
        ><strong>Chargement de ton espace...</strong>
      </div>

      <section
        v-else-if="activeNav === 'Vue d’ensemble' && role === 'student'"
        class="dashboard-content overview-dashboard"
      >
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Espace étudiant</span>
            <h2>Trouve et suis<br /><em>ton logement.</em></h2>
            <p>Favoris, demandes et échanges sont réunis dans ton espace.</p>
          </div>
          <button class="dashboard-primary" @click="emit('open-catalogue')">
            <Search :size="16" /> Trouver un logement
          </button>
        </div>
        <div class="dashboard-stats overview-stats">
          <button @click="activeNav = 'Mes favoris'">
            <span class="dashboard-stat-icon orange"><Heart :size="19" /></span>
            <div>
              <strong>{{ favorites.length }}</strong
              ><small>Logements favoris</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="activeNav = 'Mes réservations'">
            <span class="dashboard-stat-icon teal"
              ><CalendarDays :size="19"
            /></span>
            <div>
              <strong>{{ activeReservations.length }}</strong
              ><small>Demandes actives</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="activeNav = 'Messages'">
            <span class="dashboard-stat-icon yellow"
              ><MessageCircle :size="19"
            /></span>
            <div>
              <strong>{{ unreadMessages }}</strong
              ><small>Messages non lus</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="activeNav = 'Mes avis'">
            <span class="dashboard-stat-icon green"><Star :size="19" /></span>
            <div>
              <strong>{{ reviews.length }}</strong
              ><small>Avis envoyés</small>
            </div>
            <ArrowRight :size="16" />
          </button>
        </div>
        <div class="overview-grid">
          <section class="dashboard-panel overview-panel">
            <header>
              <div>
                <h3>Mes favoris récents</h3>
                <p>Les logements que tu souhaites suivre.</p>
              </div>
              <button @click="activeNav = 'Mes favoris'">
                Voir tout <ArrowRight :size="14" />
              </button>
            </header>
            <div v-if="!recentFavorites.length" class="overview-empty">
              <Heart :size="22" /><span>Aucun favori pour le moment.</span>
            </div>
            <article
              v-for="favorite in recentFavorites"
              v-else
              :key="favorite.id"
              class="overview-listing-row"
            >
              <img
                v-if="imageUrl(favorite.property)"
                :src="imageUrl(favorite.property)"
                :alt="favorite.property.title"
              /><span v-else class="overview-image-placeholder"
                ><Building2 :size="18"
              /></span>
              <div>
                <strong>{{ favorite.property.title }}</strong
                ><small
                  >{{ favorite.property.district }} ·
                  {{ favorite.property.type }}</small
                >
              </div>
            </article>
          </section>
          <section class="dashboard-panel overview-panel">
            <header>
              <div>
                <h3>Dernières demandes</h3>
                <p>Suis rapidement leurs changements.</p>
              </div>
              <button @click="activeNav = 'Mes réservations'">
                Voir tout <ArrowRight :size="14" />
              </button>
            </header>
            <div v-if="!recentReservations.length" class="overview-empty">
              <CalendarDays :size="22" /><span
                >Aucune demande enregistrée.</span
              >
            </div>
            <article
              v-for="reservation in recentReservations"
              v-else
              :key="reservation.id"
              class="overview-activity-row"
            >
              <span class="overview-activity-icon"
                ><CalendarDays :size="16"
              /></span>
              <div>
                <strong>{{ titleFor(reservation) }}</strong
                ><small
                  >{{ reservation.room?.name }} ·
                  {{ reservation.start_date }}</small
                >
              </div>
              <span
                class="status-pill"
                :class="statusTone(reservation.status)"
                >{{ statusLabel(reservation.status) }}</span
              >
            </article>
          </section>
        </div>
      </section>

      <section
        v-else-if="activeNav === 'Vue d’ensemble' && role === 'owner'"
        class="dashboard-content overview-dashboard"
      >
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Espace propriétaire</span>
            <h2>Gère tes biens<br /><em>et tes locataires.</em></h2>
            <p>Surveille la disponibilité, les demandes et les échanges.</p>
          </div>
          <button class="dashboard-primary" @click="emit('open-publish')">
            <Plus :size="16" /> Publier un logement
          </button>
        </div>
        <div class="dashboard-stats overview-stats">
          <button @click="activeNav = 'Mes logements'">
            <span class="dashboard-stat-icon orange"
              ><Building2 :size="19"
            /></span>
            <div>
              <strong>{{ properties.length }}</strong
              ><small>Logements enregistrés</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="activeNav = 'Mes logements'">
            <span class="dashboard-stat-icon green"><Check :size="19" /></span>
            <div>
              <strong>{{ availableRooms }}</strong
              ><small>Chambres disponibles</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="activeNav = 'Demandes reçues'">
            <span class="dashboard-stat-icon teal"
              ><CalendarDays :size="19"
            /></span>
            <div>
              <strong>{{ pendingOwnerRequests.length }}</strong
              ><small>Demandes à traiter</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="activeNav = 'Messages'">
            <span class="dashboard-stat-icon yellow"
              ><MessageCircle :size="19"
            /></span>
            <div>
              <strong>{{ unreadMessages }}</strong
              ><small>Messages non lus</small>
            </div>
            <ArrowRight :size="16" />
          </button>
        </div>
        <div class="overview-grid">
          <section class="dashboard-panel overview-panel">
            <header>
              <div>
                <h3>Mes logements récents</h3>
                <p>État actuel de tes dernières annonces.</p>
              </div>
              <button @click="activeNav = 'Mes logements'">
                Gérer <ArrowRight :size="14" />
              </button>
            </header>
            <div v-if="!recentProperties.length" class="overview-empty">
              <Building2 :size="22" /><span>Aucun logement enregistré.</span>
            </div>
            <article
              v-for="property in recentProperties"
              v-else
              :key="property.id"
              class="overview-activity-row"
            >
              <span class="overview-activity-icon"
                ><Building2 :size="16"
              /></span>
              <div>
                <strong>{{ property.title }}</strong
                ><small
                  >{{ property.rooms?.length || 0 }} chambre(s) ·
                  {{ property.district || "Quartier non renseigné" }}</small
                >
              </div>
              <span
                class="status-pill"
                :class="statusTone(property.publication_status)"
                >{{ statusLabel(property.publication_status) }}</span
              >
            </article>
          </section>
          <section class="dashboard-panel overview-panel">
            <header>
              <div>
                <h3>Demandes récentes</h3>
                <p>Les étudiants en attente de ta réponse.</p>
              </div>
              <button @click="activeNav = 'Demandes reçues'">
                Traiter <ArrowRight :size="14" />
              </button>
            </header>
            <div v-if="!recentReservations.length" class="overview-empty">
              <CalendarDays :size="22" /><span>Aucune demande reçue.</span>
            </div>
            <article
              v-for="reservation in recentReservations"
              v-else
              :key="reservation.id"
              class="overview-activity-row"
            >
              <span class="overview-avatar">{{
                reservation.student?.name?.slice(0, 2).toUpperCase()
              }}</span>
              <div>
                <strong>{{ reservation.student?.name || "Étudiant" }}</strong
                ><small
                  >{{ titleFor(reservation) }} ·
                  {{ reservation.room?.name }}</small
                >
              </div>
              <span
                class="status-pill"
                :class="statusTone(reservation.status)"
                >{{ statusLabel(reservation.status) }}</span
              >
            </article>
          </section>
        </div>
      </section>

      <section
        v-else-if="activeNav === 'Vue d’ensemble' && role === 'admin'"
        class="dashboard-content overview-dashboard"
      >
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Administration</span>
            <h2>Pilote toute<br /><em>la plateforme.</em></h2>
            <p>
              Utilisateurs, annonces, modération et collecte en un coup d’œil.
            </p>
          </div>
          <button
            class="dashboard-primary"
            @click="emit('open-admin-moderation')"
          >
            <ShieldCheck :size="16" /> Ouvrir la modération
          </button>
        </div>
        <div class="dashboard-stats overview-stats">
          <button @click="emit('open-admin-management')">
            <span class="dashboard-stat-icon orange"><Users :size="19" /></span>
            <div>
              <strong>{{ adminStats.users || 0 }}</strong
              ><small>Utilisateurs inscrits</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="emit('open-admin-management')">
            <span class="dashboard-stat-icon green"
              ><Building2 :size="19"
            /></span>
            <div>
              <strong>{{ adminStats.published_properties || 0 }}</strong
              ><small>Logements publiés</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="emit('open-admin-moderation')">
            <span class="dashboard-stat-icon teal"
              ><ShieldCheck :size="19"
            /></span>
            <div>
              <strong>{{
                adminPending.owners +
                adminPending.properties +
                adminPending.media +
                adminPending.reviews +
                adminPending.reports
              }}</strong
              ><small>Éléments à modérer</small>
            </div>
            <ArrowRight :size="16" /></button
          ><button @click="emit('open-admin-management')">
            <span class="dashboard-stat-icon yellow"
              ><CalendarDays :size="19"
            /></span>
            <div>
              <strong>{{ adminStats.active_rentals || 0 }}</strong
              ><small>Locations actives</small>
            </div>
            <ArrowRight :size="16" />
          </button>
        </div>
        <div class="overview-grid admin-overview-grid">
          <section class="dashboard-panel overview-panel">
            <header>
              <div>
                <h3>File de modération</h3>
                <p>Éléments qui requièrent une décision.</p>
              </div>
              <button @click="emit('open-admin-moderation')">
                Examiner <ArrowRight :size="14" />
              </button>
            </header>
            <div class="moderation-overview-list">
              <button @click="emit('open-admin-moderation')">
                <Users :size="16" /><span>Propriétaires</span
                ><strong>{{ adminPending.owners }}</strong></button
              ><button @click="emit('open-admin-moderation')">
                <Building2 :size="16" /><span>Annonces</span
                ><strong>{{ adminPending.properties }}</strong></button
              ><button @click="emit('open-admin-moderation')">
                <ShieldCheck :size="16" /><span>Médias</span
                ><strong>{{ adminPending.media }}</strong></button
              ><button @click="emit('open-admin-moderation')">
                <Star :size="16" /><span>Avis</span
                ><strong>{{ adminPending.reviews }}</strong></button
              ><button @click="emit('open-admin-moderation')">
                <MessageCircle :size="16" /><span>Signalements</span
                ><strong>{{ adminPending.reports }}</strong>
              </button>
            </div>
          </section>
          <section class="dashboard-panel overview-panel">
            <header>
              <div>
                <h3>Indicateurs opérationnels</h3>
                <p>Situation actuelle de FindCasa.</p>
              </div>
              <button @click="emit('open-admin-management')">
                Statistiques <ArrowRight :size="14" />
              </button>
            </header>
            <div class="admin-operation-metrics">
              <div>
                <span>Étudiants</span
                ><strong>{{ adminStats.students || 0 }}</strong>
              </div>
              <div>
                <span>Propriétaires vérifiés</span
                ><strong>{{ adminStats.verified_owners || 0 }}</strong>
              </div>
              <div>
                <span>Chambres disponibles</span
                ><strong>{{ adminStats.available_rooms || 0 }}</strong>
              </div>
              <div>
                <span>Loyer moyen</span
                ><strong
                  >{{
                    Number(adminStats.average_rent || 0).toLocaleString("fr-FR")
                  }}
                  FCFA</strong
                >
              </div>
              <div>
                <span>Annonces collectées</span
                ><strong>{{ adminStats.collected_share || 0 }} %</strong>
              </div>
              <div>
                <span>Comptes suspendus</span
                ><strong>{{ adminStats.suspended_users || 0 }}</strong>
              </div>
            </div>
            <button
              class="overview-source-link"
              @click="emit('open-admin-sources')"
            >
              Superviser les sources de collecte <ArrowRight :size="14" />
            </button>
          </section>
        </div>
      </section>

      <section
        v-if="role === 'student' && activeNav === 'Trouver un logement' && !selectedDashboardListing"
        class="dashboard-content dashboard-catalogue-content"
      >
        <CataloguePage
          embedded
          @open-listing="openStudentListing"
        />
      </section>

      <section
        v-if="role === 'student' && activeNav === 'Trouver un logement' && selectedDashboardListing"
        class="dashboard-content dashboard-detail-content"
      >
        <DetailPage
          embedded
          :listing="selectedDashboardListing"
          @back-to-catalogue="selectedDashboardListing = null"
        />
      </section>

      <section
        v-else-if="activeNav === 'Mes favoris'"
        class="dashboard-content"
      >
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Étudiant</span>
            <h2>Mes logements<br /><em>favoris.</em></h2>
          </div>
          <button class="dashboard-primary" @click="emit('open-catalogue')">
            Explorer
          </button>
        </div>
        <div
          v-if="!favorites.length"
          class="dashboard-empty-card dashboard-empty-wide"
        >
          <Heart :size="24" /><strong>Aucun favori</strong>
          <p>Ajoute des logements depuis le catalogue.</p>
        </div>
        <div v-else class="dashboard-favorite-grid">
          <article
            v-for="favorite in favorites"
            :key="favorite.id"
            class="dashboard-favorite"
          >
            <img
              v-if="imageUrl(favorite.property)"
              :src="imageUrl(favorite.property)"
              :alt="favorite.property.title"
            />
            <div>
              <h4>{{ favorite.property.title }}</h4>
              <p>
                {{ favorite.property.district }} · {{ favorite.property.type }}
              </p>
            </div>
            <button @click="emit('open-catalogue')">
              <ArrowRight :size="15" />
            </button>
          </article>
        </div>
      </section>

      <section
        v-else-if="
          activeNav === 'Mes réservations' || activeNav === 'Demandes reçues'
        "
        class="dashboard-content"
      >
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Réservations</span>
            <h2>{{ activeNav }}</h2>
          </div>
        </div>
        <div
          v-if="!reservations.length && !externalRequests.length"
          class="dashboard-empty-card dashboard-empty-wide"
        >
          <CalendarDays :size="24" /><strong>Aucune réservation</strong>
        </div>
        <div v-else class="reservation-request-grid">
          <article
            v-for="reservation in reservations"
            :key="reservation.id"
            class="dashboard-panel reservation-request-card"
          >
            <header>
              <div>
                <span class="reservation-property-label">{{
                  titleFor(reservation)
                }}</span>
                <h3>
                  {{
                    role === "owner"
                      ? reservation.student?.name
                      : reservation.room?.name || "Chambre"
                  }}
                </h3>
              </div>
              <span
                class="status-pill"
                :class="statusTone(reservation.status)"
                >{{ statusLabel(reservation.status) }}</span
              >
            </header>
            <div class="reservation-room-summary">
              <div>
                <span>Chambre</span
                ><strong>{{
                  reservation.room?.name || "Non renseignée"
                }}</strong>
              </div>
              <div>
                <span>Loyer</span
                ><strong>{{ money(reservation.room?.rent) }}</strong>
              </div>
              <div>
                <span>Caution</span
                ><strong>{{ money(reservation.room?.deposit) }}</strong>
              </div>
              <div>
                <span>Avance</span
                ><strong>{{ money(reservation.room?.advance) }}</strong>
              </div>
              <div>
                <span>Frais</span
                ><strong>{{ money(reservation.room?.additional_fees) }}</strong>
              </div>
              <div>
                <span>Période</span
                ><strong
                  >{{ reservation.start_date }} ·
                  {{ reservation.duration_months }} mois</strong
                >
              </div>
            </div>
            <blockquote v-if="reservation.message">
              <strong>Message de l’étudiant</strong>
              <p>{{ reservation.message }}</p>
            </blockquote>
            <blockquote v-if="reservation.owner_message">
              <strong>Réponse du propriétaire</strong>
              <p>{{ reservation.owner_message }}</p>
            </blockquote>
            <div v-if="reservation.history?.length" class="reservation-history">
              <span v-for="history in reservation.history" :key="history.id"
                >{{ statusLabel(history.to_status) }} ·
                {{ new Date(history.created_at).toLocaleString("fr-FR") }}</span
              >
            </div>
            <footer>
              <div
                v-if="role === 'owner' && reservation.status === 'pending'"
                class="dashboard-row-actions"
              >
                <button @click="respondReservation(reservation, 'accepted')">
                  <Check :size="13" /> Accepter et discuter</button
                ><button @click="respondReservation(reservation, 'rejected')">
                  <X :size="13" /> Refuser
                </button>
              </div>
              <button
                v-if="role === 'student' && reservation.status === 'pending'"
                class="dashboard-inline-action danger-action"
                @click="cancelReservation(reservation)"
              >
                Annuler la demande</button
              ><button
                v-else-if="
                  role === 'student' && reservation.status === 'accepted'
                "
                class="dashboard-primary"
                @click="confirmReservation(reservation)"
              >
                Confirmer la réservation</button
              ><button
                v-if="role === 'owner' && reservation.status === 'confirmed'"
                class="dashboard-primary"
                @click="markReservationRented(reservation)"
              >
                Confirmer l’installation</button
              ><button
                v-if="role === 'owner' && reservation.status === 'rented'"
                class="dashboard-primary"
                @click="completeReservation(reservation)"
              >
                Terminer la location</button
              ><button
                v-if="
                  ['accepted', 'confirmed', 'rented'].includes(
                    reservation.status,
                  )
                "
                class="dashboard-inline-action"
                @click="
                  activeNav = 'Messages';
                  refreshConversations();
                "
              >
                <MessageCircle :size="14" /> Ouvrir la messagerie
              </button>
            </footer>
          </article>
          <article
            v-for="request in externalRequests"
            :key="`external-${request.id}`"
            class="dashboard-panel reservation-request-card"
          >
            <header>
              <div>
                <span class="reservation-property-label"
                  >Annonce collectée</span
                >
                <h3>{{ request.property?.title }}</h3>
              </div>
              <span class="status-pill warning"
                >Mise en relation en attente</span
              >
            </header>
            <p class="external-request-note">
              FindCasa doit d’abord confirmer le propriétaire externe avant de
              permettre la réservation.
            </p>
          </article>
        </div>
      </section>

      <section
        v-else-if="activeNav === 'Mes logements'"
        class="dashboard-content"
      >
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Propriétaire</span>
            <h2>Mes logements.</h2>
            <p>Modifie chaque chambre et sa disponibilité séparément.</p>
          </div>
          <button class="dashboard-primary" @click="emit('open-publish')">
            <Plus :size="15" /> Nouvelle annonce
          </button>
        </div>
        <p v-if="roomMessage" class="profile-message">{{ roomMessage }}</p>
        <div
          v-if="!properties.length"
          class="dashboard-empty-card dashboard-empty-wide"
        >
          <Building2 :size="24" /><strong>Aucun logement</strong>
        </div>
        <div v-else class="owner-property-list">
          <article
            v-for="property in properties"
            :key="property.id"
            class="dashboard-panel owner-property-card"
          >
            <header>
              <div>
                <span
                  class="status-pill"
                  :class="statusTone(property.publication_status)"
                  >{{ statusLabel(property.publication_status) }}</span
                >
                <h3>{{ property.title }}</h3>
                <p>{{ property.district }} · {{ property.type }}</p>
              </div>
              <div class="owner-property-actions">
                <button @click="emit('open-publish', property)">
                  <Settings :size="14" /> Modifier l’annonce</button
                ><button class="danger" @click="askDeleteProperty(property)">
                  <X :size="14" /> Supprimer
                </button>
              </div>
            </header>
            <div class="owner-room-table">
              <div class="owner-room-head">
                <span>Nom</span><span>Loyer</span><span>Caution</span
                ><span>Avance</span><span>Frais</span><span>Statut</span
                ><span></span>
              </div>
              <div
                v-for="room in property.rooms"
                :key="room.id"
                class="owner-room-row"
              >
                <input v-model="room.name" maxlength="100" /><input
                  v-model.number="room.rent"
                  type="number"
                  min="0"
                  max="45000"
                /><input
                  v-model.number="room.deposit"
                  type="number"
                  min="0"
                /><input
                  v-model.number="room.advance"
                  type="number"
                  min="0"
                /><input
                  v-model.number="room.additional_fees"
                  type="number"
                  min="0"
                /><select v-model="room.status">
                  <option value="available">Disponible</option>
                  <option value="unavailable">Indisponible</option>
                  <option value="reserved">Réservée</option>
                  <option value="rented">Louée</option></select
                ><span
                  ><button @click="updateRoom(property, room)">
                    <Save :size="13" /></button
                  ><button
                    class="danger"
                    @click="askDeleteRoom(property, room)"
                  >
                    <X :size="13" /></button
                ></span>
              </div>
            </div>
            <form
              v-if="newRooms[property.id]"
              class="new-room-form"
              @submit.prevent="addRoom(property)"
            >
              <input
                v-model="newRooms[property.id].name"
                placeholder="Nom de la chambre"
                required
              /><input
                v-model.number="newRooms[property.id].rent"
                type="number"
                min="0"
                max="45000"
                placeholder="Loyer"
                required
              /><input
                v-model.number="newRooms[property.id].deposit"
                type="number"
                min="0"
                placeholder="Caution"
              /><input
                v-model.number="newRooms[property.id].advance"
                type="number"
                min="0"
                placeholder="Avance"
              /><button type="submit">
                <Plus :size="13" /> Ajouter une chambre
              </button>
            </form>
          </article>
        </div>
      </section>

      <section v-else-if="activeNav === 'Messages'" class="dashboard-content">
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Messagerie</span>
            <h2>Mes conversations.</h2>
          </div>
        </div>
        <div class="messages-layout">
          <div class="conversation-list dashboard-panel">
            <div v-if="!conversations.length" class="dashboard-empty-card">
              <MessageCircle :size="22" /><strong>Aucune conversation</strong>
            </div>
            <button
              v-for="conversation in conversations"
              v-else
              :key="conversation.id"
              class="conversation"
              :class="{ active: activeConversation?.id === conversation.id }"
              @click="openConversation(conversation)"
            >
              <span class="conversation-avatar">{{
                (role === "student"
                  ? conversation.owner?.name
                  : conversation.student?.name
                )
                  ?.slice(0, 2)
                  .toUpperCase()
              }}</span>
              <div>
                <strong>{{
                  role === "student"
                    ? conversation.owner?.name
                    : conversation.student?.name
                }}</strong
                ><small>{{ conversation.property?.title }}</small>
              </div>
            </button>
          </div>
          <div class="chat-panel dashboard-panel">
            <div v-if="!activeConversation" class="dashboard-empty-card">
              <MessageCircle :size="23" /><strong
                >Sélectionne une conversation</strong
              >
            </div>
            <template v-else
              ><div class="chat-heading">
                <strong>{{ activeConversation.property?.title }}</strong>
              </div>
              <div class="chat-messages">
                <div
                  v-for="message in messages"
                  :key="message.id"
                  class="chat-bubble"
                  :class="message.sender_id === user?.id ? 'sent' : 'received'"
                >
                  {{ message.body }}
                </div>
              </div>
              <form class="chat-composer" @submit.prevent="sendMessage">
                <input
                  v-model="messageBody"
                  placeholder="Écrire un message..."
                /><button type="submit"><ArrowRight :size="16" /></button></form
            ></template>
          </div>
        </div>
      </section>
      <section v-if="activeNav === 'Mes avis'" class="dashboard-content">
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Étudiant</span>
            <h2>Mes avis.</h2>
            <p>Tu peux noter un logement après une location terminée.</p>
          </div>
        </div>
        <div v-if="reviewableReservations.length" class="review-compose-grid">
          <form
            v-for="reservation in reviewableReservations"
            :key="reservation.id"
            class="dashboard-panel review-compose-card"
            @submit.prevent="submitReview(reservation)"
          >
            <strong>{{ titleFor(reservation) }}</strong>
            <p>Location terminée · {{ reservation.room?.name }}</p>
            <label
              ><span>Note</span
              ><select v-model="reviewDrafts[reservation.id].rating">
                <option v-for="rating in 5" :key="rating" :value="rating">
                  {{ rating }}/5
                </option>
              </select></label
            ><label
              ><span>Commentaire</span
              ><textarea
                v-model="reviewDrafts[reservation.id].comment"
                rows="3"
                maxlength="2000"
                placeholder="Partage ton expérience..."
              ></textarea></label
            ><button class="dashboard-primary" type="submit">
              <Star :size="14" /> Envoyer l’avis
            </button>
          </form>
        </div>
        <div
          v-if="!reviews.length && !reviewableReservations.length"
          class="dashboard-empty-card dashboard-empty-wide"
        >
          <Star :size="24" /><strong>Aucun avis</strong>
          <p>Un avis devient disponible après une location terminée.</p>
        </div>
        <div v-else-if="reviews.length" class="dashboard-panel review-list">
          <article
            v-for="review in reviews"
            :key="review.id"
            class="review-row"
          >
            <div>
              <strong>{{ review.property?.title || "Logement" }}</strong
              ><span class="review-stars"
                >{{ "★".repeat(review.rating)
                }}{{ "☆".repeat(5 - review.rating) }}</span
              >
              <p>{{ review.comment || "Aucun commentaire." }}</p>
            </div>
            <span class="status-pill" :class="statusTone(review.status)">{{
              statusLabel(review.status)
            }}</span>
          </article>
        </div>
      </section>

      <section
        v-if="role === 'owner' && activeNav === 'Publier un logement'"
        class="dashboard-content dashboard-publish-content"
      >
        <OwnerListingPage
          embedded
          :property-id="publishingPropertyId"
          @back-to-dashboard="leavePublishing"
          @published="handlePropertyPublished"
        />
      </section>

      <section
        v-if="role === 'admin' && activeAdminComponent"
        class="dashboard-content dashboard-admin-content"
      >
        <component
          :is="activeAdminComponent"
          @back="activeNav = 'Vue d’ensemble'"
        />
      </section>

      <section v-if="activeNav === 'Notifications'" class="dashboard-content">
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Activité du compte</span>
            <h2>Mes notifications.</h2>
            <p>
              Retrouve ici les messages et changements importants liés à ton
              compte.
            </p>
          </div>
          <button
            v-if="unreadNotifications"
            class="dashboard-inline-action"
            @click="markAllNotificationsAsRead"
          >
            Tout marquer comme lu
          </button>
        </div>
        <div
          v-if="!notifications.length"
          class="dashboard-empty-card dashboard-empty-wide"
        >
          <Bell :size="24" /><strong>Aucune notification</strong>
          <p>Tu seras informé des messages et des changements importants.</p>
        </div>
        <div v-else class="dashboard-panel notification-list">
          <button
            v-for="notification in notifications"
            :key="notification.id"
            class="dashboard-notification-row"
            :class="{ unread: !notification.read_at }"
            @click="readNotification(notification)"
          >
            <span class="notification-row-icon"><Bell :size="16" /></span
            ><span
              ><strong>{{ notification.data?.title || "FindCasa" }}</strong
              ><small>{{
                notification.data?.message ||
                "Nouvelle activité sur ton compte."
              }}</small
              ><em>{{
                new Date(notification.created_at).toLocaleString("fr-FR")
              }}</em></span
            ><b v-if="!notification.read_at"></b>
          </button>
        </div>
      </section>

      <section v-if="activeNav === 'Mon profil'" class="dashboard-content">
        <div class="dashboard-welcome">
          <div>
            <span class="section-kicker">Compte</span>
            <h2>Mon profil.</h2>
            <p>Modifie les informations liées à ton compte.</p>
          </div>
        </div>
        <form
          class="dashboard-panel profile-form"
          @submit.prevent="saveProfile"
        >
          <div class="profile-form-grid">
            <label
              ><span>Nom complet</span
              ><input
                v-model="profileForm.name"
                type="text"
                maxlength="200"
                required /></label
            ><label
              ><span>Téléphone</span
              ><input
                v-model="profileForm.phone"
                type="tel"
                maxlength="30" /></label
            ><label v-if="role === 'student'"
              ><span>Établissement</span
              ><select v-model="profileForm.institution_id">
                <option value="">Aucun établissement</option>
                <option
                  v-for="institution in institutions"
                  :key="institution.id"
                  :value="institution.id"
                >
                  {{ institution.name }}
                </option>
              </select></label
            >
          </div>
          <div class="profile-password-section">
            <h3>Changer le mot de passe</h3>
            <p>
              Laisse ces champs vides pour conserver ton mot de passe actuel.
            </p>
            <div class="profile-form-grid">
              <label
                ><span>Mot de passe actuel</span
                ><input
                  v-model="profileForm.current_password"
                  type="password"
                  maxlength="128" /></label
              ><label
                ><span>Nouveau mot de passe</span
                ><input
                  v-model="profileForm.password"
                  type="password"
                  minlength="8"
                  maxlength="128" /></label
              ><label
                ><span>Confirmation</span
                ><input
                  v-model="profileForm.password_confirmation"
                  type="password"
                  minlength="8"
                  maxlength="128"
              /></label>
            </div>
          </div>
          <p v-if="profileMessage" class="profile-message">
            {{ profileMessage }}
          </p>
          <button
            class="dashboard-primary"
            type="submit"
            :disabled="savingProfile"
          >
            <Save :size="15" />
            {{
              savingProfile
                ? "Enregistrement..."
                : "Enregistrer les modifications"
            }}
          </button>
        </form>
      </section>
    </main>
    <div
      v-if="deleteConfirmation"
      class="confirmation-overlay"
      @click.self="deleteConfirmation = null"
    >
      <section class="confirmation-dialog">
        <h2>{{ deleteConfirmation.title }}</h2>
        <p>{{ deleteConfirmation.message }}</p>
        <div>
          <button @click="deleteConfirmation = null">Annuler</button
          ><button class="danger" @click="confirmDeletion">Supprimer</button>
        </div>
      </section>
    </div>
  </div>
</template>
