<template>
  <Teleport to="body">
    <Transition name="backdrop">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[500] bg-slate-900/60 backdrop-blur-sm"
        @click="handleBackdropClick"
      />
    </Transition>

    <Transition :name="transitionName" appear>
      <div
        v-if="modelValue"
        ref="dialogRef"
        class="fixed inset-0 z-[501] flex"
        :class="containerClasses"
        role="dialog"
        :aria-modal="true"
        @keydown="trapFocus"
      >
        <div
          class="relative bg-white dark:bg-slate-900 shadow-2xl border border-slate-200 dark:border-slate-700 flex flex-col overflow-hidden pb-[30px] min-h-[200px]"
          :class="dialogClasses"
        >
          <div
            v-if="$slots.header || title"
            class="sticky top-0 z-10 flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-t-2xl"
            :class="isMobile ? 'px-4 py-3' : ''"
          >
            <slot name="header">
              <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white">
                {{ title }}
              </h3>
            </slot>
            <button
              @click="close"
              class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500"
              :aria-label="$t('common.close')"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div
            class="overflow-y-auto overscroll-contain flex-1 min-h-0"
            :class="isMobile ? 'p-4' : 'p-5'"
          >
            <slot />
          </div>

          <div
            v-if="$slots.footer"
            class="sticky bottom-0 z-10 px-5 py-4 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900"
            :class="footerClasses"
          >
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  modelValue: Boolean,
  title: String,
  size: { type: String, default: 'md' },
  persistent: Boolean,
  mobileFullScreen: Boolean,
})

const emit = defineEmits(['update:modelValue', 'close'])
const dialogRef = ref(null)
const isMobile = ref(false)
let previousActiveElement = null
let focusableElements = null
let mobileQuery = null
let mobileHandler = null

if (typeof window !== 'undefined') {
  mobileQuery = window.matchMedia('(max-width: 767px)')
  isMobile.value = mobileQuery.matches
  mobileHandler = (e) => { isMobile.value = e.matches }
  mobileQuery.addEventListener('change', mobileHandler)
}

const containerClasses = computed(() => {
  if (isMobile.value) return 'items-end justify-center p-0'
  return 'items-start justify-center pt-5 md:pt-6'
})

const dialogClasses = computed(() => {
  if (isMobile.value) {
    if (props.mobileFullScreen) {
      return 'w-full h-full border-b-0 rounded-none'
    }
    return 'w-full max-h-[85vh] rounded-t-2xl border-b-0 '
  }
  const sizes = {
    sm: 'max-w-sm',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
    full: 'w-[calc(100%-2rem)] mx-4',
  }
  return `w-full ${sizes[props.size] || sizes.md} max-h-[85vh] rounded-2xl`
})

const footerClasses = computed(() => {
  if (isMobile.value) return 'px-4 py-3 pb-[env(safe-area-inset-bottom,1rem)]'
  return 'px-5 py-4'
})

const transitionName = computed(() => {
  if (isMobile.value) return 'drawer'
  return 'scale'
})

function handleBackdropClick() {
  if (!props.persistent) {
    close()
  }
}

function close() {
  emit('update:modelValue', false)
  emit('close')
}

function getScrollbarWidth() {
  return window.innerWidth - document.documentElement.clientWidth
}

function getLockState() {
  if (typeof window === 'undefined') return null
  if (!window.__dialogLock) {
    window.__dialogLock = { count: 0, scrollBarWidth: 0, scrollY: 0 }
  }
  return window.__dialogLock
}

function lockBody() {
  const state = getLockState()
  if (!state) return
  state.count++
  if (state.count === 1) {
    const scrollY = window.scrollY
    state.scrollY = scrollY
    state.scrollBarWidth = getScrollbarWidth()
    const body = document.body
    body.style.position = 'fixed'
    body.style.top = `-${scrollY}px`
    body.style.left = '0'
    body.style.right = '0'
    body.style.overflow = 'hidden'
    if (state.scrollBarWidth > 0) {
      body.style.paddingRight = `${state.scrollBarWidth}px`
    }
  }
}

function unlockBody() {
  const state = getLockState()
  if (!state) return
  state.count = Math.max(0, state.count - 1)
  if (state.count === 0) {
    const scrollY = state.scrollY
    const body = document.body
    body.style.position = ''
    body.style.top = ''
    body.style.left = ''
    body.style.right = ''
    body.style.overflow = ''
    body.style.paddingRight = ''
    window.scrollTo(0, scrollY)
  }
}

function saveFocus() {
  try { previousActiveElement = document.activeElement } catch (e) { /* noop */ }
}

function restoreFocus() {
  if (previousActiveElement && previousActiveElement.focus) {
    nextTick(() => previousActiveElement.focus())
  }
}

function trapFocus(e) {
  if (e.key === 'Escape') { close(); return }
  if (e.key !== 'Tab' || !dialogRef.value) return

  if (!focusableElements || focusableElements.length === 0) {
    focusableElements = Array.from(dialogRef.value.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    ))
  }

  if (!focusableElements.length) return

  const first = focusableElements[0]
  const last = focusableElements[focusableElements.length - 1]

  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

watch(() => props.modelValue, (val) => {
  focusableElements = null
  if (val) {
    saveFocus()
    lockBody()
    nextTick(() => {
      if (!dialogRef.value) return
      const firstFocusable = dialogRef.value.querySelector(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      )
      firstFocusable?.focus()
    })
  } else {
    unlockBody()
    restoreFocus()
  }
})

onUnmounted(() => {
  unlockBody()
  if (mobileQuery && mobileHandler) {
    mobileQuery.removeEventListener('change', mobileHandler)
  }
})
</script>

<style scoped>
.backdrop-enter-active,
.backdrop-leave-active {
  transition: opacity 0.25s ease;
}
.backdrop-enter-from,
.backdrop-leave-to {
  opacity: 0;
}

.scale-enter-active,
.scale-leave-active {
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.scale-enter-from,
.scale-leave-to {
  opacity: 0;
  transform: scale(0.92) translateY(10px);
}

.drawer-enter-active,
.drawer-leave-active {
  transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.drawer-enter-from,
.drawer-leave-to {
  opacity: 0;
  transform: translateY(100%);
}
</style>
