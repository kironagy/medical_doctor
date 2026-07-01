<template>
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-[100]" @click="close" @contextmenu.prevent="close">
      <div
        ref="menuRef"
        class="absolute bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl py-1.5 min-w-[200px] overflow-hidden"
        :style="{ top: `${pos.y}px`, left: `${pos.x}px` }"
      >
        <button @click="action('select')" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
          Open Patient
        </button>
        <button @click="action('edit')" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
          Edit Details
        </button>
        <button @click="action('share')" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
          Share Patient
        </button>
        <hr class="my-1 border-slate-100 dark:border-slate-700" />
        <button @click="action('archive')" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-left">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
          Archive Patient
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, nextTick } from 'vue'

const props = defineProps({
  patient: Object,
})

const emit = defineEmits(['select'])

const visible = ref(false)
const pos = ref({ x: 0, y: 0 })
const menuRef = ref(null)

function open(event) {
  pos.value = { x: event.clientX, y: event.clientY }
  visible.value = true
  nextTick(() => {
    if (!menuRef.value) return
    const rect = menuRef.value.getBoundingClientRect()
    if (rect.right > window.innerWidth) {
      pos.value.x = window.innerWidth - rect.width - 10
    }
    if (rect.bottom > window.innerHeight) {
      pos.value.y = window.innerHeight - rect.height - 10
    }
  })
}

function close() {
  visible.value = false
}

function action(type) {
  close()
  emit('select', { type, patient: props.patient })
}

defineExpose({ open })
</script>
