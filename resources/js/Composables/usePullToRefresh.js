import { ref, computed, onUnmounted, shallowRef } from 'vue'

const THRESHOLD = 65
const MAX_PULL = 140
const DAMPING = 0.4
const SNAP_DURATION = 220

export function usePullToRefresh(options = {}) {
  const {
    onRefresh = null,
    threshold = THRESHOLD,
    maxPull = MAX_PULL,
    damping = DAMPING,
    scrollContainer = null,
  } = options

  const pullDistance = ref(0)
  const isPulling = shallowRef(false)
  const isRefreshing = shallowRef(false)
  const thresholdReached = computed(() => pullDistance.value >= threshold)
  const pullProgress = computed(() => Math.min(pullDistance.value / threshold, 1))

  let touchStartY = 0
  let isTouching = false
  let snapRaf = null
  let hapticTriggered = false

  function getScrollTop() {
    const el = scrollContainer?.value
    if (el) return el.scrollTop
    return (document.scrollingElement || document.documentElement)?.scrollTop ?? 0
  }

  function dampen(dy) {
    return dy / (1 + dy / maxPull)
  }

  function setPull(v) {
    pullDistance.value = v
  }

  function triggerHaptic() {
    try {
      if (window.native?.haptic?.impact) {
        window.native.haptic.impact('medium')
        return
      }
      if (navigator.vibrate) navigator.vibrate(10)
    } catch (_) {}
  }

  function handleTouchStart(e) {
    if (isRefreshing.value) return
    if (isPulling.value) {
      cancelSnap()
    }
    if (getScrollTop() > 0) return
    touchStartY = e.touches[0].clientY
    isTouching = true
  }

  function handleTouchMove(e) {
    if (!isTouching || isRefreshing.value) return
    const dy = (e.touches[0].clientY - touchStartY) * damping
    if (dy > 0) {
      e.preventDefault()
      isPulling.value = true
      setPull(dampen(dy))
      if (pullDistance.value >= threshold && !hapticTriggered) {
        hapticTriggered = true
        triggerHaptic()
      }
    } else {
      if (pullDistance.value !== 0) setPull(0)
    }
  }

  function handleTouchEnd() {
    if (!isTouching) return
    isTouching = false
    hapticTriggered = false
    if (thresholdReached.value) {
      isRefreshing.value = true
      isPulling.value = false
      invokeRefresh()
    } else {
      snapBack()
    }
  }

  function snapBack() {
    const start = pullDistance.value
    if (start === 0) {
      isPulling.value = false
      return
    }
    cancelSnap()
    const t0 = performance.now()
    function step(now) {
      const p = Math.min((now - t0) / SNAP_DURATION, 1)
      const ease = 1 - (1 - p) ** 3
      pullDistance.value = start * (1 - ease)
      if (p < 1) {
        snapRaf = requestAnimationFrame(step)
      } else {
        pullDistance.value = 0
        isPulling.value = false
      }
    }
    snapRaf = requestAnimationFrame(step)
  }

  async function invokeRefresh() {
    try {
      if (typeof navigator !== 'undefined' && !navigator.onLine) {
        try {
          const { useToast } = await import('./useToast')
          useToast().warning('لا يوجد اتصال بالإنترنت - وضع عدم الاتصال')
        } catch (_) {
          console.warn('Offline mode: pull to refresh aborted')
        }
        return
      }
      if (onRefresh) await onRefresh()
    } catch (_) {
    } finally {
      await nextSnapBack()
    }
  }

  function nextSnapBack() {
    return new Promise((resolve) => {
      const start = pullDistance.value
      if (start === 0) {
        isRefreshing.value = false
        resolve()
        return
      }
      cancelSnap()
      const t0 = performance.now()
      function step(now) {
        const p = Math.min((now - t0) / SNAP_DURATION, 1)
        const ease = 1 - (1 - p) ** 3
        pullDistance.value = start * (1 - ease)
        if (p < 1) {
          snapRaf = requestAnimationFrame(step)
        } else {
          pullDistance.value = 0
          isRefreshing.value = false
          resolve()
        }
      }
      snapRaf = requestAnimationFrame(step)
    })
  }

  function cancelSnap() {
    if (snapRaf) {
      cancelAnimationFrame(snapRaf)
      snapRaf = null
    }
  }

  function cancel() {
    cancelSnap()
    pullDistance.value = 0
    isPulling.value = false
    isRefreshing.value = false
    isTouching = false
  }

  onUnmounted(cancel)

  return {
    pullDistance,
    pullProgress,
    isPulling,
    isRefreshing,
    thresholdReached,
    handleTouchStart,
    handleTouchMove,
    handleTouchEnd,
    cancel,
  }
}
