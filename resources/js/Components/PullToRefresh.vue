<template>
  <div
    ref="rootRef"
    class="ptr-root"
    @touchstart="handleTouchStart"
    @touchmove="handleTouchMove"
    @touchend="handleTouchEnd"
    @touchcancel="handleTouchEnd"
  >
    <div
      class="ptr-indicator"
      :style="indicatorStyle"
      aria-hidden="true"
    >
      <div
        class="ptr-indicator-inner"
        :style="innerStyle"
      >
        <svg
          v-if="!isRefreshing"
          width="36"
          height="36"
          viewBox="0 0 36 36"
          class="ptr-icon"
        >
          <circle
            cx="18" cy="18" r="15"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            class="ptr-track"
          />
          <circle
            cx="18" cy="18" r="15"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            :stroke-dasharray="arcDash"
            class="ptr-arc"
            transform="rotate(-90 18 18)"
          />
          <g class="ptr-arrow-group" :style="{ transform: `rotate(${arrowRotation}deg)`, transformOrigin: 'center' }">
            <path
              d="M18 8 L18 24 M12 18 L18 24 L24 18"
              fill="none"
              stroke="currentColor"
              stroke-width="2.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </g>
        </svg>
        <svg
          v-else
          width="36"
          height="36"
          viewBox="0 0 36 36"
          class="ptr-spinner"
        >
          <circle
            cx="18" cy="18" r="15"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            class="ptr-track"
          />
          <circle
            cx="18" cy="18" r="15"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            stroke-dasharray="70 100"
            class="ptr-spinner-arc"
            transform="rotate(-90 18 18)"
          />
        </svg>
        <span
          v-if="thresholdReached && !isRefreshing"
          class="ptr-label"
        >{{ releaseLabel }}</span>
        <span
          v-else-if="isRefreshing"
          class="ptr-label"
        >{{ refreshingLabel }}</span>
      </div>
    </div>
    <div class="ptr-content" :style="contentStyle">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { usePullToRefresh } from '@/Composables/usePullToRefresh'

const RADIUS = 15
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

const props = defineProps({
  refresh: {
    type: Function,
    required: false,
    default: null,
  },
  threshold: {
    type: Number,
    default: 80,
  },
  releaseLabel: {
    type: String,
    default: 'Release to refresh',
  },
  refreshingLabel: {
    type: String,
    default: 'Refreshing',
  },
  color: {
    type: String,
    default: '#0d9488',
  },
  scrollContainer: {
    type: Object,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['refresh'])

const rootRef = ref(null)

const {
  pullDistance,
  pullProgress,
  isPulling,
  isRefreshing,
  thresholdReached,
  handleTouchStart,
  handleTouchMove,
  handleTouchEnd,
  cancel,
} = usePullToRefresh({
  onRefresh: async () => {
    if (props.refresh) {
      await props.refresh()
    } else {
      emit('refresh')
    }
  },
  threshold: props.threshold,
  scrollContainer: props.scrollContainer,
})

const indicatorStyle = computed(() => {
  const t = pullDistance.value
  return {
    transform: `translateY(${t}px)`,
    color: props.color,
  }
})

const contentStyle = computed(() => {
  const t = pullDistance.value
  return {
    transform: `translateY(${t}px)`,
    willChange: 'transform',
  }
})

const innerStyle = computed(() => {
  const p = pullProgress.value
  const opacity = Math.min(p * 2, 1)
  const scale = 0.4 + p * 0.6
  return {
    opacity,
    transform: `scale(${scale})`,
  }
})

const arcDash = computed(() => {
  const len = pullProgress.value * CIRCUMFERENCE
  return `${len} ${CIRCUMFERENCE}`
})

const arrowRotation = computed(() => {
  return pullProgress.value * 180
})
</script>

<style scoped>
.ptr-root {
  position: relative;
  min-height: 1px;
}

.ptr-indicator {
  position: absolute;
  left: 0;
  right: 0;
  top: -60px;
  height: 60px;
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 20;
  pointer-events: none;
  will-change: transform;
}

.ptr-indicator-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  will-change: transform, opacity;
}

.ptr-icon {
  display: block;
}

.ptr-spinner {
  display: block;
  animation: ptr-spin 0.8s linear infinite;
}

@keyframes ptr-spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.ptr-track {
  opacity: 0.15;
}

.ptr-arc {
  transition: stroke-dasharray 0.05s linear;
}

.ptr-arrow-group {
  transition: transform 0.15s ease-out;
}

.ptr-label {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  white-space: nowrap;
  user-select: none;
  margin-top: 2px;
}

.ptr-content {
  position: relative;
  z-index: 1;
  will-change: transform;
}
</style>
