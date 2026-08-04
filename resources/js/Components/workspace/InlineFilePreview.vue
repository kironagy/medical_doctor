<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[100] flex flex-col bg-slate-900/95 backdrop-blur-sm pb-safe" @click.self="close">
        <div class="flex items-center justify-between px-4 py-3 pt-safe bg-slate-900/80 border-b border-slate-700/50">
          <div class="flex items-center gap-3 text-white min-w-0 max-w-[70%]">
            <div v-if="file" class="flex items-center gap-2 min-w-0">
              <svg v-if="file.mime_type?.startsWith('image/')" class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
              <svg v-else-if="file.mime_type?.startsWith('video/')" class="w-5 h-5 text-indigo-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
              <svg v-else-if="file.mime_type === 'application/pdf'" class="w-5 h-5 text-rose-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
              <svg v-else class="w-5 h-5 text-slate-400 dark:text-slate-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
              <span class="text-sm font-medium truncate text-white">{{ file.title || file.file_name || $t('file_preview.file') }}</span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="isZoomed && file?.mime_type?.startsWith('image/')" @click="toggleZoom" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" :title="$t('file_preview.zoom_out')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 8H7" /></svg>
            </button>
            <button @click="toggleFullscreen" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" :title="$t('file_preview.fullscreen')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
            </button>
            <a v-if="file?.url" :href="file.url" target="_blank" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" :title="$t('file_preview.download')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
            </a>
            <!-- Cache Offline (Phase 6) -->
            <button
              v-if="!isCached"
              @click="onCacheClick"
              class="p-2 text-amber-400 hover:text-amber-300 bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors"
              :title="$t('file_preview.cache_for_offline')"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
            </button>
            <button
              v-else
              :disabled="removingCache"
              @click="onRemoveCacheClick"
              class="p-2 text-slate-400 hover:text-rose-400 bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors disabled:opacity-60"
              title="Remove from cache"
            >
              <svg v-if="!removingCache" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
              <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            </button>
            <!-- Delete Button -->
            <button v-if="canDelete" :disabled="deleting" @click="confirmDelete" class="p-2 text-rose-400 hover:text-rose-300 bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors disabled:opacity-60 disabled:cursor-not-allowed" title="Delete">
              <svg v-if="!deleting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
              <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            </button>
            <button @click="close" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" :title="$t('file_preview.close')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
        </div>

        <div class="flex-1 flex items-center justify-center p-2 md:p-6 overflow-hidden relative group" @wheel="onWheel" @touchstart="onTouchStart" @touchmove="onTouchMove" @touchend="onTouchEnd">
          
          <!-- Prev Button (Right Arrow in RTL) -->
          <button v-if="hasPrev" @click.stop="prevFile" class="absolute right-2 md:right-6 top-1/2 -translate-y-1/2 p-3 bg-slate-800/50 hover:bg-slate-700/80 text-white rounded-full transition-all opacity-50 hover:opacity-100 focus:opacity-100 z-10" :title="$t('common.previous')">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
          </button>

          <!-- Next Button (Left Arrow in RTL) -->
          <button v-if="hasNext" @click.stop="nextFile" class="absolute left-2 md:left-6 top-1/2 -translate-y-1/2 p-3 bg-slate-800/50 hover:bg-slate-700/80 text-white rounded-full transition-all opacity-50 hover:opacity-100 focus:opacity-100 z-10" :title="$t('common.next')">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <div v-if="file?.mime_type?.startsWith('image/')"
            class="flex items-center justify-center w-full h-full overflow-hidden overscroll-none"
            :class="isZoomed ? (isDragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in'"
            @dblclick.self="toggleZoom()"
            @mousedown="onImageMouseDown"
            @mousemove="onImageMouseMove"
            @mouseup="onImageMouseUp"
            @mouseleave="onImageMouseUp"
          >
            <img
              :src="fileUrl"
              loading="lazy"
              decoding="async"
              class="transition-transform rounded-lg select-none"
              :class="isZoomed ? 'max-w-none pointer-events-none' : 'max-w-full max-h-full object-contain pointer-events-auto'"
              :style="imageStyle"
              @dblclick.stop="toggleZoom()"
              @error="e => {
                const uuid = file?.uuid;
                if (!uuid) return;
                const localUrl = '/_native/cache/files/' + uuid;
                if (e.target.src !== localUrl) {
                  e.target.src = localUrl;
                }
              }"
              ref="imageRef"
            />
          </div>
          <div v-else-if="file?.mime_type?.startsWith('video/')" class="w-full max-w-5xl mx-auto">
            <VideoPlayer
              :src="fileUrl"
              :type="file.mime_type"
              :poster="thumbnailPostUrl"
              class="rounded-lg overflow-hidden"
            />
          </div>
          <div v-else-if="file?.mime_type === 'application/pdf'" class="w-full h-full">
            <iframe
              :src="`${fileUrl}#view=FitH`"
              class="w-full h-full rounded-lg bg-white dark:bg-slate-900"
              style="min-height: 75vh;"
              loading="lazy"
            ></iframe>
          </div>
          <div v-else class="text-center p-8 max-w-md">
            <div class="w-20 h-20 mx-auto mb-5 bg-slate-800 dark:bg-slate-700 rounded-2xl flex items-center justify-center">
              <svg class="w-10 h-10 text-slate-400 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
            </div>
            <p class="text-white text-sm font-medium mb-1">{{ file?.file_name }}</p>
            <p class="text-slate-400 dark:text-slate-300 text-xs mb-1">{{ file?.mime_type }}</p>
            <p class="text-slate-500 dark:text-slate-400 text-xs mb-5">{{ formatBytes(file?.size) }}</p>
            <a :href="file?.url" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary-600 dark:bg-primary-500 hover:bg-primary-700 dark:hover:bg-primary-600 text-white rounded-lg text-sm font-medium transition-colors">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
              Download File
            </a>
          </div>
        </div>

        <div class="text-center py-2 text-[11px] text-slate-500 dark:text-slate-400" v-if="file">
          Press <kbd class="px-1 py-0.5 bg-slate-800 dark:bg-slate-700 rounded text-slate-300 dark:text-slate-400 text-[10px]">Esc</kbd> to close
        </div>
      </div>
    </Transition>
  </Teleport>

</template>

<script setup>
import { ref, watch, computed, watchEffect } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useNativeBridge } from '@/Composables/useNativeBridge'
import VideoPlayer from '@/Components/VideoPlayer.vue'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import BaseButton from '@/Components/BaseButton.vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const { detectNative } = useNativeBridge()

const {
  showPreview: show,
  previewFile: file,
  previewSiblings,
  closePreview: close,
  canDelete,
  removeFileLocally,
  cachedFiles,
  checkCacheStatus,
  cacheForOffline,
  removeFromCache,
} = useWorkspace()

const siblings = computed(() => {
  if (!previewSiblings.value) return []
  return previewSiblings.value.filter(r => r.mime_type?.startsWith('image/') || r.mime_type?.startsWith('video/'))
})

const currentIndex = computed(() => {
  if (!file.value || !siblings.value.length) return -1
  return siblings.value.findIndex(r => r.uuid === file.value.uuid)
})

const hasNext = computed(() => currentIndex.value !== -1 && currentIndex.value < siblings.value.length - 1)
const hasPrev = computed(() => currentIndex.value > 0)

const isLoadingMore = ref(false)

async function nextFile() {
  if (currentIndex.value >= siblings.value.length - 2 && loadMoreSiblings.value && !isLoadingMore.value) {
    isLoadingMore.value = true
    const newFiles = await loadMoreSiblings.value(Math.ceil(siblings.value.length / 6)) // approximate page
    if (newFiles && newFiles.length > 0) {
      previewSiblings.value = [...previewSiblings.value, ...newFiles]
    }
    isLoadingMore.value = false
  }

  if (hasNext.value) file.value = siblings.value[currentIndex.value + 1]
}

function prevFile() {
  if (hasPrev.value) file.value = siblings.value[currentIndex.value - 1]
}

import { onMounted, onUnmounted } from 'vue'

const handleKeydown = (e) => {
  if (!show.value) return
  if (e.key === 'ArrowLeft') nextFile() // Next because RTL
  if (e.key === 'ArrowRight') prevFile() // Prev because RTL
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

const signedUrl = ref('')
const signedThumbnailUrl = ref('')

const fileUrl = computed(() => {
  return signedUrl.value || file.value?.url || ''
})

const thumbnailPostUrl = computed(() => {
  return signedThumbnailUrl.value || file.value?.thumbnail_url || ''
})

// ---------------------------------------------------------------
//  Phase 6 — Offline File Cache
// ---------------------------------------------------------------
const isCached = computed(() => !!(file.value?.uuid && cachedFiles.value[file.value.uuid]))
const caching = ref(false)
const removingCache = ref(false)

async function onCacheClick() {
  if (!file.value?.uuid || caching.value) return
  caching.value = true
  const result = await cacheForOffline(file.value.uuid)
  if (!result.success) {
    toast.error(result.message || 'Failed to cache file')
  }
  caching.value = false
}

async function onRemoveCacheClick() {
  if (!file.value?.uuid || removingCache.value) return
  removingCache.value = true
  await removeFromCache(file.value.uuid)
  removingCache.value = false
}

// Detect cache status on mount (if we've seen this file before the cache
// list might be stale — re-check once when the preview opens).
watchEffect(() => {
  if (file.value?.uuid) {
    checkCacheStatus(file.value.uuid)
  }
})

// ---------------------------------------------------------------
//  Signed URL
// ---------------------------------------------------------------
const fetchSignedUrls = async () => {
  if (!file.value?.uuid) return

  if (detectNative()) {
    const uuid = file.value.uuid
    const isImage = file.value.mime_type?.startsWith('image/')
    // Raw binary streamed through the NativePHP WebView bridge does not
    // reliably reach the <img> element (confirmed on-device: identical
    // 200 OK + correct content-length on every retry, image never
    // renders). Base64/JSON survives the same bridge intact, so fetch
    // images that way. Video keeps the direct streamed URL (range/seek
    // support, avoids loading a large file fully into memory).
    if (isImage) {
      try {
        const res = await axios.get(`/_native/cache/files/${uuid}/base64`)
        signedUrl.value = `data:${res.data.mime};base64,${res.data.data}`
        return
      } catch (e) {
        console.warn('Failed to fetch base64 image, falling back to direct URL', e)
      }
    }
    signedUrl.value = `/_native/cache/files/${uuid}`
    return
  }

  try {
    const res = await axios.get(`/api/v1/files/${file.value.uuid}/signed-url`)
    signedUrl.value = res.data.url
  } catch (e) {
    // Fall back to local cache URL if the file is cached
    if (cachedFiles.value[file.value.uuid]) {
      signedUrl.value = cachedFileUrl(file.value.uuid)
    } else {
      console.warn('Failed to fetch signed URL, using direct URL', e)
    }
  }
}

function cachedFileUrl(uuid) {
  // Build the local PHP server URL (same origin).
  return `/_native/cache/files/${uuid}`
}

watch(file, async (newFile) => {
  signedUrl.value = ''
  signedThumbnailUrl.value = ''
  if (newFile) {
    await fetchSignedUrls()
  }
}, { immediate: true })

const deleting = ref(false)

const dialog = useDialog()
const toast = useToast()

async function confirmDelete() {
   if (deleting.value) return
   const confirmed = await dialog.confirm({
     title: t('file_preview.delete_confirm_title'),
     message: t('file_preview.delete_confirm_message', { filename: file.value?.title || file.value?.file_name }),
     confirmText: t('file_preview.delete_confirm_button'),
     style: 'danger',
   })
  if (!confirmed) return
  deleting.value = true
  try {
    await axios.delete(`/api/v1/files/${file.value.uuid}`)
    removeFileLocally(file.value.uuid)
    close()
    toast.success(t('file_preview.delete_success'))
  } catch (e) {
    console.error('Delete failed:', e)
    toast.error(e.response?.data?.message || 'Failed to delete file')
  } finally {
    deleting.value = false
  }
}

const scale = ref(1)
const isZoomed = computed(() => scale.value > 1)
const isDragging = ref(false)
const dragStartX = ref(0)
const dragStartY = ref(0)
const currentPanX = ref(0)
const currentPanY = ref(0)
const panX = ref(0)
const panY = ref(0)
const imageRef = ref(null)

const imageStyle = computed(() => {
  return {
    transform: `translate(${panX.value}px, ${panY.value}px) scale(${scale.value})`,
    transitionDuration: isDragging.value || initialPinchDistance.value > 0 ? '0s' : '0.2s',
    transformOrigin: 'center center'
  }
})

function toggleZoom() {
  if (scale.value > 1) {
    scale.value = 1
    panX.value = 0
    panY.value = 0
    currentPanX.value = 0
    currentPanY.value = 0
  } else {
    scale.value = 2
  }
}

const touchStartX = ref(0)
const initialPinchDistance = ref(0)
const initialScale = ref(1)

function onTouchStart(e) {
  if (e.touches.length === 2) {
    initialPinchDistance.value = Math.hypot(
      e.touches[0].clientX - e.touches[1].clientX,
      e.touches[0].clientY - e.touches[1].clientY
    )
    initialScale.value = scale.value
  } else if (e.touches.length === 1) {
    if (scale.value > 1) {
      isDragging.value = true
      dragStartX.value = e.touches[0].clientX
      dragStartY.value = e.touches[0].clientY
      currentPanX.value = panX.value
      currentPanY.value = panY.value
    } else {
      touchStartX.value = e.touches[0].clientX
    }
  }
}

function onTouchMove(e) {
  if (e.touches.length === 2 && initialPinchDistance.value > 0) {
    const dist = Math.hypot(
      e.touches[0].clientX - e.touches[1].clientX,
      e.touches[0].clientY - e.touches[1].clientY
    )
    const zoomRatio = dist / initialPinchDistance.value
    const newScale = Math.max(1, Math.min(10, initialScale.value * zoomRatio))
    scale.value = newScale
    if (newScale === 1) {
      panX.value = 0
      panY.value = 0
      currentPanX.value = 0
      currentPanY.value = 0
    }
  } else if (e.touches.length === 1 && scale.value > 1 && isDragging.value) {
    panX.value = currentPanX.value + (e.touches[0].clientX - dragStartX.value)
    panY.value = currentPanY.value + (e.touches[0].clientY - dragStartY.value)
  }
}

function onTouchEnd(e) {
  if (e.touches.length < 2) {
    initialPinchDistance.value = 0
  }
  if (e.touches.length === 0) {
    if (isDragging.value) {
      isDragging.value = false
      currentPanX.value = panX.value
      currentPanY.value = panY.value
    }
    if (scale.value === 1 && touchStartX.value) {
      const touchEndX = e.changedTouches[0].clientX
      const diff = touchStartX.value - touchEndX
      if (Math.abs(diff) > 50) {
        if (diff > 0) nextFile()
        else prevFile()
      }
      touchStartX.value = 0
    }
  }
}

function onImageMouseDown(e) {
  if (scale.value === 1) return
  e.preventDefault()
  isDragging.value = true
  dragStartX.value = e.clientX
  dragStartY.value = e.clientY
  currentPanX.value = panX.value
  currentPanY.value = panY.value
}

function onImageMouseMove(e) {
  if (!isDragging.value || scale.value === 1) return
  const dx = e.clientX - dragStartX.value
  const dy = e.clientY - dragStartY.value
  panX.value = currentPanX.value + dx
  panY.value = currentPanY.value + dy
}

function onImageMouseUp(e) {
  if (!isDragging.value) return
  isDragging.value = false
  currentPanX.value = panX.value
  currentPanY.value = panY.value
}

function onWheel(e) {
  if (!file.value?.mime_type?.startsWith('image/')) return
  e.preventDefault()
  
  if (e.ctrlKey) {
    // pinch to zoom
    const zoomDelta = e.deltaY > 0 ? 0.9 : 1.1
    const newScale = Math.max(1, Math.min(10, scale.value * zoomDelta))
    scale.value = newScale
    if (newScale === 1) {
      panX.value = 0
      panY.value = 0
      currentPanX.value = 0
      currentPanY.value = 0
    }
  } else if (scale.value > 1) {
    // pan
    panX.value -= e.deltaX
    panY.value -= e.deltaY
    currentPanX.value = panX.value
    currentPanY.value = panY.value
  }
}

function toggleFullscreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen?.()
  } else {
    document.exitFullscreen?.()
  }
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

watch(show, (val) => {
  if (!val) {
    scale.value = 1
    panX.value = 0
    panY.value = 0
    currentPanX.value = 0
    currentPanY.value = 0
  }
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
