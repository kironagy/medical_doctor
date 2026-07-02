<template>
  <div
    ref="playerContainer"
    class="video-player-wrapper relative bg-black rounded-lg overflow-hidden group min-h-[200px]"
    :class="{ 'video-player--fullscreen': isFullscreen }"
    @keydown="handleKeydown"
    tabindex="0"
  >
    <!-- Video element (video.js) -->
    <video
      ref="videoEl"
      class="video-js vjs-big-play-centered w-full object-contain"
      :poster="poster"
      preload="metadata"
    ></video>

    <!-- Loading spinner overlay (when buffering) -->
    <div
      v-if="isBuffering && hasStarted"
      class="absolute inset-0 flex items-center justify-center pointer-events-none z-10"
    >
      <div class="w-12 h-12 border-[3px] border-white/20 border-t-white rounded-full animate-spin" />
    </div>

    <!-- Big play button (custom, shown before play) -->
    <div
      v-if="!hasStarted"
      class="absolute inset-0 flex items-center justify-center cursor-pointer z-20"
      @click="play"
    >
      <div class="w-16 h-16 md:w-20 md:h-20 bg-primary-600/90 hover:bg-primary-600 rounded-full flex items-center justify-center transition-all hover:scale-110 active:scale-95 shadow-2xl">
        <svg class="w-7 h-7 md:w-9 md:h-9 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
      </div>
    </div>

    <!-- Controls overlay (bottom bar) -->
    <Transition name="controls-fade">
      <div
        v-if="hasStarted && (controlsVisible || !isPlaying || isFullscreen)"
        class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent pt-12 pb-3 px-4 z-30"
        @mouseenter="controlsVisible = true"
        @mouseleave="controlsVisible = isPlaying ? false : true"
      >
        <!-- Progress bar -->
        <div
          ref="progressRef"
          class="relative h-1.5 bg-white/20 rounded-full mb-4 cursor-pointer group/progress hover:h-2.5 transition-all duration-150"
          @click="seekTo"
          @mousemove.prevent="onProgressHover"
          @mouseenter="showSeekTooltip = true"
          @mouseleave="showSeekTooltip = false"
        >
          <!-- Buffer bar -->
          <div class="absolute inset-y-0 left-0 bg-white/30 rounded-full" :style="{ width: bufferPercent + '%' }" />
          <!-- Progress bar -->
          <div class="absolute inset-y-0 left-0 bg-primary-500 rounded-full" :style="{ width: progressPercent + '%' }" />
          <!-- Seek thumb -->
          <div
            class="absolute top-1/2 -translate-y-1/2 w-3.5 h-3.5 bg-primary-400 rounded-full shadow-md opacity-0 group-hover/progress:opacity-100 transition-opacity"
            :style="{ left: `calc(${progressPercent}% - 7px)` }"
          />
          <!-- Seek tooltip -->
          <div
            v-if="showSeekTooltip"
            class="absolute -top-8 -translate-x-1/2 bg-slate-900 text-white text-[11px] font-medium px-2 py-1 rounded shadow-lg whitespace-nowrap pointer-events-none"
            :style="{ left: seekTooltipLeft + '%' }"
          >
            {{ seekTooltipTime }}
          </div>
        </div>

        <!-- Control buttons row -->
        <div class="flex items-center justify-between gap-2 text-white">
          <div class="flex items-center gap-1.5 md:gap-3">
            <!-- Play/Pause -->
            <button @click="togglePlay" class="p-1.5 hover:bg-white/10 rounded-lg transition-colors" :title="isPlaying ? 'Pause (k)' : 'Play (k)'">
              <svg v-if="isPlaying" class="w-5 h-5 md:w-6 md:h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" /></svg>
              <svg v-else class="w-5 h-5 md:w-6 md:h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
            </button>

            <!-- Volume -->
            <div class="flex items-center gap-1 group/vol">
              <button @click="toggleMute" class="p-1.5 hover:bg-white/10 rounded-lg transition-colors" title="Mute (m)">
                <svg v-if="volume === 0 || isMuted" class="w-4 h-4 md:w-5 md:h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.796 8.796 0 0021 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 003.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z" /></svg>
                <svg v-else-if="volume < 0.5" class="w-4 h-4 md:w-5 md:h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M18.5 12c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM5 9v6h4l5 5V4L9 9H5z" /></svg>
                <svg v-else class="w-4 h-4 md:w-5 md:h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" /></svg>
              </button>
              <div class="w-0 overflow-hidden group-hover/vol:w-20 md:group-hover/vol:w-24 transition-all duration-200 flex items-center">
                <input
                  type="range"
                  min="0"
                  max="1"
                  step="0.05"
                  :value="volume"
                  @input="setVolume"
                  class="w-full h-1 bg-white/30 rounded-full appearance-none cursor-pointer [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-3 [&::-webkit-slider-thumb]:h-3 [&::-webkit-slider-thumb]:bg-white [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:shadow"
                />
              </div>
            </div>

            <!-- Time display -->
            <span class="text-[11px] md:text-xs font-medium text-white/80 whitespace-nowrap tabular-nums ms-1">
              {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
            </span>
          </div>

          <div class="flex items-center gap-1.5 md:gap-2">
            <!-- Playback speed -->
            <div class="relative group/speed">
              <button @click="cycleSpeed" class="px-2 py-1 text-[11px] md:text-xs font-medium bg-white/10 hover:bg-white/20 rounded-md transition-colors tabular-nums" title="Speed">
                {{ playbackSpeed }}x
              </button>
              <div class="absolute bottom-full right-0 mb-2 bg-slate-900 border border-slate-700 rounded-xl shadow-2xl py-1.5 min-w-[100px] opacity-0 invisible group-hover/speed:opacity-100 group-hover/speed:visible transition-all duration-150 z-40">
                <button
                  v-for="speed in speedOptions"
                  :key="speed"
                  @click="setSpeed(speed)"
                  class="w-full px-4 py-2 text-xs text-left transition-colors"
                  :class="speed === playbackSpeed ? 'text-primary-400 font-bold' : 'text-white/70 hover:text-white hover:bg-white/10'"
                >
                  {{ speed }}x
                </button>
              </div>
            </div>

            <!-- Picture-in-Picture -->
            <button
              v-if="supportsPiP"
              @click="togglePiP"
              class="p-1.5 hover:bg-white/10 rounded-lg transition-colors"
              title="Picture-in-Picture"
            >
              <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14 14h4v4h-4z" /></svg>
            </button>

            <!-- Fullscreen -->
            <button @click="toggleFullscreen" class="p-1.5 hover:bg-white/10 rounded-lg transition-colors" title="Fullscreen (f)">
              <svg v-if="isFullscreen" class="w-4 h-4 md:w-5 md:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25" /></svg>
              <svg v-else class="w-4 h-4 md:w-5 md:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import 'video.js/dist/video-js.css'
let videojs = null

const props = defineProps({
  src: { type: String, required: true },
  type: { type: String, default: 'video/mp4' },
  poster: { type: String, default: '' },
  autoplay: { type: Boolean, default: false },
})

const emit = defineEmits(['play', 'pause', 'seeked', 'timeupdate', 'error', 'ended'])

const videoEl = ref(null)
const playerContainer = ref(null)
const progressRef = ref(null)
let vjsPlayer = null

const isPlaying = ref(false)
const hasStarted = ref(false)
const isBuffering = ref(false)
const isFullscreen = ref(false)
const isMuted = ref(false)
const currentTime = ref(0)
const duration = ref(0)
const volume = ref(1)
const playbackSpeed = ref(1)
const controlsVisible = ref(true)
const showSeekTooltip = ref(false)
const seekTooltipTime = ref('0:00')
const seekTooltipLeft = ref(0)
const bufferPercent = ref(0)
const progressPercent = ref(0)

const supportsPiP = typeof document !== 'undefined' && 'pictureInPictureEnabled' in document
const speedOptions = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2]

let hideControlsTimer = null
let isMounted = false

function formatTime(seconds) {
  if (!seconds || !isFinite(seconds)) return '0:00'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = Math.floor(seconds % 60)
  if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
  return `${m}:${String(s).padStart(2, '0')}`
}

async function initPlayer() {
  if (!videoEl.value) return
  if (vjsPlayer) {
    vjsPlayer.dispose()
    vjsPlayer = null
  }

  if (!videojs) {
    videojs = (await import('video.js')).default
  }
  if (!isMounted || !videoEl.value) return

  vjsPlayer = videojs(videoEl.value, {
    controls: false,
    autoplay: props.autoplay,
    preload: 'metadata',
    fluid: false,
    html5: {
      nativeAudioTracks: false,
      nativeVideoTracks: false,
      hls: { overrideNative: true },
    },
    sources: [{ src: props.src, type: props.type }],
    poster: props.poster || undefined,
  })

  vjsPlayer.ready(() => {
    duration.value = vjsPlayer.duration() || 0
  })

  vjsPlayer.on('play', () => {
    isPlaying.value = true
    hasStarted.value = true
    controlsVisible.value = true
    emit('play')
  })

  vjsPlayer.on('pause', () => {
    isPlaying.value = false
    controlsVisible.value = true
    emit('pause')
  })

  vjsPlayer.on('timeupdate', () => {
    currentTime.value = vjsPlayer.currentTime()
    duration.value = vjsPlayer.duration() || 0
    progressPercent.value = duration.value > 0 ? (currentTime.value / duration.value) * 100 : 0
    emit('timeupdate', currentTime.value)
  })

  vjsPlayer.on('seeked', () => {
    emit('seeked', currentTime.value)
  })

  vjsPlayer.on('waiting', () => {
    isBuffering.value = true
  })

  vjsPlayer.on('canplay', () => {
    isBuffering.value = false
  })

  vjsPlayer.on('playing', () => {
    isBuffering.value = false
  })

  vjsPlayer.on('progress', () => {
    const ranges = vjsPlayer.buffered()
    if (ranges.length > 0) {
      const bufferedEnd = ranges.end(ranges.length - 1)
      bufferPercent.value = duration.value > 0 ? (bufferedEnd / duration.value) * 100 : 0
    }
  })

  vjsPlayer.on('volumechange', () => {
    volume.value = vjsPlayer.volume()
    isMuted.value = vjsPlayer.muted()
  })

  vjsPlayer.on('error', (e) => {
    emit('error', e)
  })

  vjsPlayer.on('ended', () => {
    isPlaying.value = false
    emit('ended')
  })
}

function togglePlay() {
  if (!vjsPlayer) return
  if (vjsPlayer.paused()) {
    vjsPlayer.play()
  } else {
    vjsPlayer.pause()
  }
}

function play() {
  vjsPlayer?.play()
}

function seekTo(e) {
  if (!progressRef.value || !vjsPlayer) return
  const rect = progressRef.value.getBoundingClientRect()
  const x = (e.clientX - rect.left) / rect.width
  vjsPlayer.currentTime(x * duration.value)
}

function onProgressHover(e) {
  if (!progressRef.value || !vjsPlayer) return
  const rect = progressRef.value.getBoundingClientRect()
  const x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
  seekTooltipLeft.value = x * 100
  seekTooltipTime.value = formatTime(x * (duration.value || 0))
}

function setVolume(e) {
  const val = parseFloat(e.target.value)
  vjsPlayer.volume(val)
  if (val > 0 && isMuted.value) {
    vjsPlayer.muted(false)
  }
}

function toggleMute() {
  vjsPlayer.muted(!vjsPlayer.muted())
}

function cycleSpeed() {
  const idx = speedOptions.indexOf(playbackSpeed.value)
  const next = speedOptions[(idx + 1) % speedOptions.length]
  setSpeed(next)
}

function setSpeed(speed) {
  playbackSpeed.value = speed
  if (vjsPlayer) {
    vjsPlayer.playbackRate(speed)
  }
}

function toggleFullscreen() {
  if (!playerContainer.value) return
  if (!document.fullscreenElement) {
    playerContainer.value.requestFullscreen?.()
    isFullscreen.value = true
  } else {
    document.exitFullscreen?.()
    isFullscreen.value = false
  }
}

function togglePiP() {
  if (!vjsPlayer) return
  const videoTag = vjsPlayer.el().querySelector('video')
  if (!videoTag) return
  if (document.pictureInPictureElement) {
    document.exitPictureInPicture()
  } else {
    videoTag.requestPictureInPicture()
  }
}

function handleKeydown(e) {
  switch (e.key) {
    case ' ':
    case 'k':
      e.preventDefault()
      togglePlay()
      break
    case 'ArrowLeft':
      e.preventDefault()
      if (vjsPlayer) vjsPlayer.currentTime(Math.max(0, vjsPlayer.currentTime() - 10))
      break
    case 'ArrowRight':
      e.preventDefault()
      if (vjsPlayer) vjsPlayer.currentTime(Math.min(duration.value, vjsPlayer.currentTime() + 10))
      break
    case 'ArrowUp':
      e.preventDefault()
      if (vjsPlayer) vjsPlayer.volume(Math.min(1, vjsPlayer.volume() + 0.1))
      break
    case 'ArrowDown':
      e.preventDefault()
      if (vjsPlayer) vjsPlayer.volume(Math.max(0, vjsPlayer.volume() - 0.1))
      break
    case 'f':
      e.preventDefault()
      toggleFullscreen()
      break
    case 'm':
      e.preventDefault()
      toggleMute()
      break
  }
}

let lastTap = 0
function handleDoubleTap(e) {
  const now = Date.now()
  const diff = now - lastTap
  lastTap = now
  if (diff < 300 && e.target.closest('.video-player-wrapper')) {
    const rect = e.target.closest('.video-player-wrapper').getBoundingClientRect()
    const x = e.clientX - rect.left
    const third = rect.width / 3
    if (vjsPlayer) {
      if (x < third) {
        vjsPlayer.currentTime(Math.max(0, vjsPlayer.currentTime() - 10))
      } else if (x > rect.width - third) {
        vjsPlayer.currentTime(Math.min(duration.value, vjsPlayer.currentTime() + 10))
      }
    }
  }
}

function showControls() {
  controlsVisible.value = true
  clearTimeout(hideControlsTimer)
  if (isPlaying.value) {
    hideControlsTimer = setTimeout(() => {
      controlsVisible.value = false
    }, 3000)
  }
}

watch(() => props.src, () => {
  nextTick(() => initPlayer())
})

onMounted(() => {
  isMounted = true
  initPlayer()
  document.addEventListener('dblclick', handleDoubleTap)
  if (playerContainer.value) {
    playerContainer.value.addEventListener('mousemove', showControls)
  }
})

onBeforeUnmount(() => {
  isMounted = false
  if (vjsPlayer) {
    vjsPlayer.dispose()
    vjsPlayer = null
  }
  document.removeEventListener('dblclick', handleDoubleTap)
  if (playerContainer.value) {
    playerContainer.value.removeEventListener('mousemove', showControls)
  }
  clearTimeout(hideControlsTimer)
})
</script>

<style scoped>
.video-player-wrapper {
  outline: none;
  max-height: 85vh;
}

.video-player-wrapper:fullscreen {
  max-width: 100vw;
  max-height: 100vh;
}

.video-player-wrapper:fullscreen .video-js {
  height: 100vh;
  width: 100vw;
}

/* Remove default video.js big play button (we use our own) */
.video-player-wrapper :deep(.vjs-big-play-button) {
  display: none !important;
}

/* Hide video.js default control bar (we use our own) */
.video-player-wrapper :deep(.vjs-control-bar) {
  display: none !important;
}

/* Allow video.js poster to fill */
.video-player-wrapper :deep(.vjs-poster) {
  background-size: cover;
}

/* Controls fade transition */
.controls-fade-enter-active,
.controls-fade-leave-active {
  transition: opacity 0.3s ease;
}
.controls-fade-enter-from,
.controls-fade-leave-to {
  opacity: 0;
}

/* Custom volume slider */
input[type='range'] {
  -webkit-appearance: none;
  appearance: none;
  background: transparent;
  cursor: pointer;
}
input[type='range']::-webkit-slider-runnable-track {
  height: 4px;
  border-radius: 2px;
  background: rgba(255, 255, 255, 0.3);
}
input[type='range']::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: white;
  margin-top: -4px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
input[type='range']::-moz-range-track {
  height: 4px;
  border-radius: 2px;
  background: rgba(255, 255, 255, 0.3);
}
input[type='range']::-moz-range-thumb {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: white;
  border: none;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
</style>
