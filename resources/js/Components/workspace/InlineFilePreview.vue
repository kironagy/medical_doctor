<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[100] flex flex-col bg-slate-900/95 backdrop-blur-sm" @click.self="close">
        <div class="flex items-center justify-between px-4 py-3 bg-slate-900/80 border-b border-slate-700/50">
          <div class="flex items-center gap-3 text-white min-w-0 max-w-[70%]">
            <div v-if="file" class="flex items-center gap-2 min-w-0">
              <svg v-if="file.mime_type?.startsWith('image/')" class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
              <svg v-else-if="file.mime_type?.startsWith('video/')" class="w-5 h-5 text-indigo-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
              <svg v-else-if="file.mime_type === 'application/pdf'" class="w-5 h-5 text-rose-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
              <svg v-else class="w-5 h-5 text-slate-400 dark:text-slate-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
              <span class="text-sm font-medium truncate text-white">{{ file.title || file.file_name || 'File' }}</span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="isZoomed && file?.mime_type?.startsWith('image/')" @click="toggleZoom" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" title="Zoom Out">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 8H7" /></svg>
            </button>
            <button @click="toggleFullscreen" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" title="Fullscreen">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
            </button>
            <a v-if="file?.url" :href="file.url" target="_blank" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" title="Download">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
            </a>
            <button @click="close" class="p-2 text-slate-300 dark:text-slate-400 hover:text-white bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition-colors" title="Close (Esc)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
        </div>

        <div class="flex-1 flex items-center justify-center p-2 md:p-6 overflow-hidden relative" @wheel="onWheel">
          <div v-if="file?.mime_type?.startsWith('image/')"
            class="flex items-center justify-center w-full h-full overflow-auto cursor-zoom-in"
            :class="{ 'cursor-zoom-out': isZoomed }"
            @click="toggleZoom"
          >
            <img
              :src="file.url"
              class="transition-transform duration-200 rounded-lg"
              :class="isZoomed ? 'max-w-none' : 'max-w-full max-h-full object-contain'"
              :style="isZoomed ? { transform: 'scale(2)', transformOrigin: zoomOrigin } : {}"
              @mousemove="onImageMouseMove"
              ref="imageRef"
            />
          </div>
          <div v-else-if="file?.mime_type?.startsWith('video/')" class="w-full max-w-4xl">
            <video
              :src="file.url"
              controls
              preload="none"
              :poster="file.thumbnail_url"
              class="w-full rounded-lg"
              style="max-height: 80vh;"
            >
              <source :src="file.url" :type="file.mime_type" />
            </video>
          </div>
          <div v-else-if="file?.mime_type === 'application/pdf'" class="w-full h-full">
            <iframe
              :src="`${file.url}#view=FitH`"
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
import { ref, watch, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { showPreview: show, previewFile: file, closePreview: close } = useWorkspace()
const isZoomed = ref(false)
const zoomOrigin = ref('center center')
const imageRef = ref(null)

function toggleZoom() {
  isZoomed.value = !isZoomed.value
}

function onImageMouseMove(e) {
  if (!isZoomed.value || !imageRef.value) return
  const rect = imageRef.value.getBoundingClientRect()
  const x = ((e.clientX - rect.left) / rect.width) * 100
  const y = ((e.clientY - rect.top) / rect.height) * 100
  zoomOrigin.value = `${x}% ${y}%`
}

function onWheel(e) {
  if (file.value?.mime_type?.startsWith('image/') && isZoomed.value) {
    e.preventDefault()
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
    isZoomed.value = false
  }
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
