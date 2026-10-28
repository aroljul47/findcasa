<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Bell, Check, X } from 'lucide-vue-next'
import api from '../services/api'
import { getEcho } from '../services/realtime'
import { onNotificationSync, syncAllNotificationsRead, syncNotificationRead } from '../services/notificationSync'

const emit = defineEmits(['unread-change', 'open-list', 'select'])
const props = defineProps({ userId: { type: Number, required: true } })
const open = ref(false)
const notifications = ref([])
const loading = ref(false)
const error = ref('')
let stopSync

const unreadCount = () => notifications.value.filter((item) => !item.read_at).length

async function load() {
  loading.value = true
  try {
    const response = await api.get('/notifications')
    notifications.value = response.data.data?.data || response.data.data || []
    emit('unread-change', unreadCount())
    error.value = ''
  } catch { error.value = 'Notifications indisponibles.' } finally { loading.value = false }
}

async function markAsRead(notification) {
  if (!notification.read_at) {
    await api.post(`/notifications/${notification.id}/read`)
    notification.read_at = new Date().toISOString()
    emit('unread-change', unreadCount())
    syncNotificationRead(notification.id)
  }
  emit('select', notification)
}

async function markAllAsRead() {
  if (!unreadCount()) return
  await api.post('/notifications/read-all')
  const now = new Date().toISOString()
  notifications.value.forEach((notification) => { notification.read_at ||= now })
  emit('unread-change', 0)
  syncAllNotificationsRead()
}

function togglePanel() {
  open.value = !open.value
  emit('open-list')
}

onMounted(() => {
  load()
  getEcho()?.private(`App.Models.User.${props.userId}`).notification((notification) => {
    notifications.value = [{ id: notification.id || `${Date.now()}`, data: notification, read_at: null, created_at: new Date().toISOString() }, ...notifications.value]
    emit('unread-change', unreadCount())
  })
  stopSync = onNotificationSync(({ id, all }) => {
    const now = new Date().toISOString()
    if (all) notifications.value.forEach((notification) => { notification.read_at ||= now })
    else {
      const item = notifications.value.find((notification) => String(notification.id) === String(id))
      if (item) item.read_at ||= now
    }
    emit('unread-change', unreadCount())
  })
})
onBeforeUnmount(() => stopSync?.())
</script>

<template>
  <div class="notification-panel-wrap"><button class="dashboard-notification" aria-label="Notifications" @click="togglePanel"><Bell :size="18" /><b v-if="unreadCount()">{{ unreadCount() > 99 ? '99+' : unreadCount() }}</b></button><div v-if="open" class="notification-panel"><div class="notification-panel-heading"><strong>Notifications</strong><span><button v-if="unreadCount()" class="notification-read-all" @click="markAllAsRead">Tout marquer comme lu</button><button aria-label="Fermer" @click="open = false"><X :size="15" /></button></span></div><div v-if="loading && !notifications.length" class="notification-panel-state">Chargement...</div><div v-else-if="error" class="notification-panel-state error">{{ error }}</div><div v-else-if="!notifications.length" class="notification-panel-state"><Bell :size="21" /><span>Aucune notification</span></div><button v-for="notification in notifications" v-else :key="notification.id" class="notification-item" :class="{ unread: !notification.read_at }" @click="markAsRead(notification)"><span class="notification-check"><Check :size="13" /></span><span><strong>{{ notification.data?.title || 'FindCasa' }}</strong><small>{{ notification.data?.message || 'Nouvelle activité sur ton compte.' }}</small><em>{{ new Date(notification.created_at).toLocaleString('fr-FR') }}</em></span></button></div></div>
</template>
