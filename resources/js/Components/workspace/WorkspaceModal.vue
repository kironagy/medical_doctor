<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-[100] flex"
      :class="computedClasses"
      role="dialog"
      :aria-modal="true"
    >
      <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="close"></div>

      <Transition :name="transitionName" appear>
        <div
          v-if="modelValue"
          ref="contentRef"
          class="relative bg-white dark:bg-slate-900 shadow-2xl border border-slate-200 dark:border-slate-700"
          :class="computedSize"
          @keydown="trapFocus"
        >
          <div v-if="title" class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800" :class="isMobile ? 'px-4 py-3' : ''">
            <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white">{{ title }}</h3>
            <button @click="close" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="overflow-y-auto overscroll-contain" :class="contentPadding">
            <slot />
          </div>
        </div>
      </Transition>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  modelValue: Boolean,
  title: String,
  size: { type: String, default: 'md' },
  position: { type: String, default: 'center' },
  persistent: Boolean,
})

const emit = defineEmits(['update:modelValue', 'close'])
const contentRef = ref(null)
const isMobile = ref(typeof window !== 'undefined' && window.innerWidth < 768)
let previousActiveElement = null
let resizeHandler = null

function updateMobile() {
  isMobile.value = window.innerWidth < 768
}

const effectivePosition = computed(() => {
  if (isMobile.value) return 'drawer'
  return props.position
})

const computedClasses = computed(() => {
  if (isMobile.value) return 'items-end justify-center p-0'
  if (props.position === 'slideover') return 'justify-end items-stretch'
  if (props.position === 'drawer') return 'items-end justify-center'
  return 'items-center justify-center p-4'
})

const computedSize = computed(() => {
  if (isMobile.value) return 'w-full max-h-[90vh] rounded-t-2xl border-b-0'
  if (props.position === 'slideover') return 'h-full max-h-full w-full max-w-lg'
  const sizes = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl', full: 'max-w-full' }
  return `w-full ${sizes[props.size] || sizes.md}`
})

const transitionName = computed(() => {
  if (isMobile.value || props.position === 'drawer') return 'drawer'
  if (props.position === 'slideover') return 'slide-right'
  return 'scale'
})

const contentPadding = computed(() => {
  if (isMobile.value) return 'p-4'
  return props.title ? 'p-5' : 'p-5'
})

function close() {
  if (!props.persistent) {
    emit('update:modelValue', false)
    emit('close')
  }
}

function lockBody() {
  document.body.style.overflow = 'hidden'
  document.body.style.touchAction = 'none'
}

function unlockBody() {
  document.body.style.overflow = ''
  document.body.style.touchAction = ''
}

function saveFocus() {
  previousActiveElement = document.activeElement
}

function restoreFocus() {
  if (previousActiveElement && previousActiveElement.focus) {
    nextTick(() => previousActiveElement.focus())
  }
}

function trapFocus(e) {
  if (e.key === 'Escape') { close(); return }
  if (e.key !== 'Tab' || !contentRef.value) return
  const focusable = contentRef.value.querySelectorAll(
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
  )
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

watch(() => props.modelValue, (val) => {
  if (val) {
    saveFocus()
    lockBody()
    nextTick(() => {
      const firstFocusable = contentRef.value?.querySelector(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      )
      firstFocusable?.focus()
    })
  } else {
    unlockBody()
    restoreFocus()
  }
})

if (typeof window !== 'undefined') {
  resizeHandler = () => updateMobile()
  window.addEventListener('resize', resizeHandler)
}

onUnmounted(() => {
  unlockBody()
  if (resizeHandler) window.removeEventListener('resize', resizeHandler)
})
</script>

<style scoped>
.drawer-enter-active, .drawer-leave-active { transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.drawer-enter-from, .drawer-leave-to { transform: translateY(100%); opacity: 0; }

.scale-enter-active, .scale-leave-active { transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
.scale-enter-from, .scale-leave-to { opacity: 0; transform: scale(0.92) translateY(10px); }

.slide-right-enter-active, .slide-right-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-right-enter-from, .slide-right-leave-to { opacity: 0; transform: translateX(40px); }
</style>
