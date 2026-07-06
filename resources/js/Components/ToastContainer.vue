<template>
  <Teleport to="body">
    <div class="fixed z-[300] pointer-events-none toast-position" :class="positionClass">
      <TransitionGroup name="toast" tag="div" class="flex flex-col gap-2 pointer-events-auto">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="flex items-start gap-3 px-4 py-3 rounded-xl shadow-xl border backdrop-blur-sm max-w-sm w-full transition-all"
          :class="toastClasses(t.type)"
          @mouseenter="clearAutoDismiss(t)"
          @mouseleave="resumeAutoDismiss(t)"
        >
          <!-- Icon -->
          <div class="flex-shrink-0 mt-0.5">
            <svg v-if="t.type === 'success'" class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            <svg v-else-if="t.type === 'error'" class="w-5 h-5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            <svg v-else-if="t.type === 'warning'" class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            <svg v-else class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          </div>
          <!-- Message -->
          <p class="text-sm font-medium text-slate-800 dark:text-slate-200 flex-1">{{ t.message }}</p>
          <!-- Close -->
          <button @click="removeToast(t.id)" class="flex-shrink-0 p-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors rounded-lg active:bg-slate-100 dark:active:bg-slate-800">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'
import { useToast } from '@/Composables/useToast'

const { toasts, removeToast } = useToast()

const autoDismissTimers = {}

const positionClass = computed(() => {
  return window.innerWidth < 768 ? 'bottom-4 inset-x-4 flex justify-center' : 'top-4 right-4'
})

function toastClasses(type) {
  switch (type) {
    case 'success': return 'bg-emerald-50 dark:bg-emerald-950 border-emerald-200 dark:border-emerald-800'
    case 'error': return 'bg-rose-50 dark:bg-rose-950 border-rose-200 dark:border-rose-800'
    case 'warning': return 'bg-amber-50 dark:bg-amber-950 border-amber-200 dark:border-amber-800'
    default: return 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700'
  }
}

function clearAutoDismiss(t) {
  if (autoDismissTimers[t.id]) {
    clearTimeout(autoDismissTimers[t.id])
    delete autoDismissTimers[t.id]
  }
}

function resumeAutoDismiss(t) {
  if (t.duration > 0 && !autoDismissTimers[t.id]) {
    autoDismissTimers[t.id] = setTimeout(() => removeToast(t.id), t.duration)
  }
}
</script>

<style scoped>
.toast-enter-active { transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-leave-active { transition: all 0.2s ease; }
.toast-enter-from { opacity: 0; transform: translateX(30px) scale(0.95); }
.toast-leave-to { opacity: 0; transform: translateX(30px) scale(0.95); }
@media (max-width: 767px) {
  .toast-position {
    bottom: calc(1rem + var(--sab, 0px));
  }
}
</style>
