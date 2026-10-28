<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { CheckCircle2, CircleAlert, X } from 'lucide-vue-next'
import { subscribeToasts } from '../services/toast'

const items = ref([])
let unsubscribe

function remove(id) { items.value = items.value.filter((item) => item.id !== id) }

onMounted(() => {
  unsubscribe = subscribeToasts((item) => {
    items.value.push(item)
    window.setTimeout(() => remove(item.id), 4200)
  })
})
onBeforeUnmount(() => unsubscribe?.())
</script>

<template><div class="toast-stack" aria-live="polite"><article v-for="item in items" :key="item.id" class="app-toast" :class="item.type"><CheckCircle2 v-if="item.type === 'success'" :size="18" /><CircleAlert v-else :size="18" /><span>{{ item.message }}</span><button aria-label="Fermer" @click="remove(item.id)"><X :size="14" /></button></article></div></template>
