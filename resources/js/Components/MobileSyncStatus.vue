<template>
  <div v-if="visible" class="ptr-offline-banner" :class="type">
    <svg v-if="type === 'offline'" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m-2.829-2.829a5 5 0 000-7.07m-4.243 4.243a1 1 0 010-1.414M3 3l18 18"/></svg>
    <svg v-else-if="type === 'syncing'" class="w-4 h-4 flex-shrink-0 ptr-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
    <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-xs font-medium">{{ message }}</span>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  syncManager: { type: Object, default: null },
})

const visible = ref(false)
const type = ref('offline')
const message = ref('')

let timeout = null

function show(msg, t, duration = 4000) {
  message.value = msg
  type.value = t
  visible.value = true
  if (timeout) clearTimeout(timeout)
  timeout = setTimeout(() => {
    visible.value = false
  }, duration)
}

function hide() {
  visible.value = false
}

function handleEvent(event, data) {
  switch (event) {
    case 'offline':
      show("You're offline. Displaying local data.", 'offline', 0)
      break
    case 'online':
      show('Back online. Syncing...', 'syncing', 2000)
      break
    case 'sync-start':
      show('Syncing with server...', 'syncing', 0)
      break
    case 'sync-complete':
      if (data?.counts) {
        const total = Object.values(data.counts).reduce((a, b) => a + b, 0)
        show(`Sync complete. ${total} updates.`, 'success', 3000)
      } else {
        show('Sync complete.', 'success', 3000)
      }
      break
    case 'sync-error':
      show('Sync failed. Will retry.', 'offline', 5000)
      break
    case 'ready':
      if (!data?.isOnline) {
        show("You're offline. Displaying local data.", 'offline', 0)
      }
      break
  }
}

onMounted(() => {
  if (props.syncManager?.onSyncEvent) {
    props.syncManager.onSyncEvent(handleEvent)
  }
})

onUnmounted(() => {
  if (timeout) clearTimeout(timeout)
})
</script>

<style scoped>
.ptr-offline-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 10000;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 16px;
  padding-top: calc(8px + env(safe-area-inset-top, 0px));
  font-size: 12px;
  font-weight: 500;
  animation: ptr-slide-down 0.3s ease-out;
  backdrop-filter: blur(8px);
}
.ptr-offline-banner.offline {
  background: rgba(239, 68, 68, 0.95);
  color: white;
}
.ptr-offline-banner.syncing {
  background: rgba(15, 118, 110, 0.95);
  color: white;
}
.ptr-offline-banner.success {
  background: rgba(16, 185, 129, 0.95);
  color: white;
}
@keyframes ptr-slide-down {
  from { transform: translateY(-100%); }
  to { transform: translateY(0); }
}
@keyframes ptr-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.ptr-spin {
  animation: ptr-spin 1s linear infinite;
}
</style>
