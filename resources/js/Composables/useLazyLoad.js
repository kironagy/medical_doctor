import { ref, onMounted, onUnmounted } from 'vue'

export function useLazyLoad(callback, options = {}) {
  const targetRef = ref(null)
  const isVisible = ref(false)
  const hasLoaded = ref(false)
  let observer = null

  const { threshold = 0.1, rootMargin = '100px' } = options

  function setupObserver() {
    if (!targetRef.value || hasLoaded.value) return
    observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          isVisible.value = true
          if (!hasLoaded.value) {
            hasLoaded.value = true
            if (callback) callback()
          }
          if (observer) observer.disconnect()
        }
      },
      { threshold, rootMargin }
    )
    observer.observe(targetRef.value)
  }

  onMounted(() => {
    if (targetRef.value) setupObserver()
  })

  onUnmounted(() => {
    if (observer) observer.disconnect()
  })

  return { targetRef, isVisible, hasLoaded }
}
