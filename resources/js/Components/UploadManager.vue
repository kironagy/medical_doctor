<template>
    <div class="fixed z-50 bottom-4 end-4 md:bottom-4 md:end-4 max-md:bottom-[calc(5rem+var(--sab))]">
    <!-- Minimized badge -->
    <button
      v-if="minimized && activeUploads > 0"
      @click="minimized = false"
      class="flex items-center gap-2 bg-white dark:bg-slate-900 border dark:border-slate-700 rounded-full shadow-lg px-4 py-2.5 hover:shadow-xl transition-shadow select-none"
    >
      <span class="relative flex h-3 w-3">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75" />
        <span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500" />
      </span>
      <span class="text-sm font-medium text-slate-800 dark:text-slate-200">
        {{ activeUploads }} {{ activeUploads === 1 ? $t('upload_manager.upload') || 'upload' : $t('upload_manager.uploads') || 'uploads' }}
      </span>
    </button>

    <!-- Full panel -->
    <div
      v-show="!minimized && uploads.length > 0"
      class="bg-white dark:bg-slate-900 border dark:border-slate-700 rounded-xl shadow-2xl w-80 sm:w-96 max-h-[70vh] flex flex-col transition-shadow duration-200 select-none"
      :class="{ 'shadow-blue-500/10': uploadingCount > 0 }"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b dark:border-slate-800 shrink-0">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white flex items-center gap-2">
          <svg v-if="uploadingCount > 0" class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" v-show="!minimized">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          <svg v-else class="h-4 w-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {{ $t('upload_manager.title') || 'Uploads' }}
          <span class="text-xs text-slate-400 font-normal">({{ uploads.length }})</span>
        </h3>
        <div class="flex items-center gap-1">
          <button
            v-if="completedCount > 0"
            @click="clearCompleted"
            class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 px-2 py-1 rounded-md hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
          >
            {{ $t('upload_manager.clear_finished') || 'Clear' }}
          </button>
          <button
            @click="minimized = true"
            class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
            :title="$t('upload_manager.minimize') || 'Minimize'"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Body -->
      <div class="overflow-y-auto overscroll-contain p-3 space-y-2.5 flex-1">
        <div
          v-for="upload in uploads"
          :key="upload.id"
          class="rounded-xl border dark:border-slate-800 p-3 transition-colors"
          :class="upload.status === 'failed' ? 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800' : 'bg-white dark:bg-slate-900'"
        >
          <!-- Row: icon + name + action -->
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
              <svg v-if="upload.file?.type?.startsWith('video/')" class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              <svg v-else-if="upload.file?.type?.startsWith('image/')" class="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              <svg v-else class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
            </div>
            <div class="min-w-0 flex-1">
              <p v-if="upload.file" class="text-xs font-medium text-slate-900 dark:text-white truncate" :title="upload.file.name">
                {{ upload.file.name }}
              </p>
              <p v-else class="text-xs font-medium text-slate-900 dark:text-white truncate">
                {{ upload.metadata?.title || 'File' }}
              </p>
              <p class="text-[11px] text-slate-400">{{ formatSize(upload.totalBytes) }} <span v-if="upload.totalChunks > 0">· {{ upload.completedChunks?.size || 0 }}/{{ upload.totalChunks }}</span></p>
              <p v-if="patientName(upload.patientId)" class="text-[10px] text-slate-400 truncate">{{ patientName(upload.patientId) }}</p>
            </div>
            <button
              v-if="upload.status === 'uploading' && !upload.offline"
              @click="pauseUpload(upload.id)"
              class="shrink-0 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-amber-500 transition-colors"
              :title="$t('files.pause')"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </button>
            <button
              v-if="upload.status === 'uploading' && !upload.offline"
              @click="cancelUpload(upload.id)"
              class="shrink-0 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-red-500 transition-colors"
              :title="$t('upload_manager.cancel_upload') || 'Cancel'"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <button
              v-if="upload.status === 'paused'"
              @click="resumeUpload(upload.id)"
              class="shrink-0 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-amber-500 hover:text-amber-600 transition-colors"
              :title="$t('files.resume')"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </button>
            <button
              v-if="upload.status === 'paused'"
              @click="cancelUpload(upload.id)"
              class="shrink-0 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-red-500 transition-colors"
              :title="$t('upload_manager.cancel_upload') || 'Cancel'"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <button
              v-if="upload.status === 'failed' && !upload.offline"
              @click="retryUpload(upload.id)"
              class="shrink-0 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-red-400 hover:text-red-600 transition-colors"
              :title="$t('upload_manager.retry_upload') || 'Retry'"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </button>
            <button
              v-if="upload._debug?.hasReport"
              @click="upload._debug.downloadReport()"
              class="shrink-0 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-indigo-500 transition-colors"
              title="Download Upload Report"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </button>
            <div v-if="upload.status === 'completed'" class="shrink-0 p-1.5 text-green-500">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </div>

          <!-- Progress bar -->
          <div v-if="upload.status === 'uploading'" class="mb-1.5">
            <div class="bg-slate-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
              <div
                class="h-full rounded-full transition-all duration-300 ease-out"
                :class="upload.progress < 50 ? 'bg-blue-500' : upload.progress < 80 ? 'bg-blue-600' : 'bg-green-500'"
                :style="{ width: upload.progress + '%' }"
              />
            </div>
          </div>

          <!-- Status line -->
          <div class="flex items-center justify-between text-[11px]">
            <span v-if="upload.status === 'uploading'" class="text-slate-500">
              <template v-if="upload.speed > 0">
                {{ formatSize(upload.uploadedBytes) }} {{ $t('files.remaining_size') }}
                · {{ formatSpeed(upload.speed) }}
              </template>
              <template v-else>
                {{ formatSize(upload.uploadedBytes) }} / {{ formatSize(upload.totalBytes) }}
              </template>
            </span>
            <span v-else-if="upload.status === 'completed'" class="text-green-600 dark:text-green-400 font-medium">
              {{ $t('upload_manager.completed') || 'Completed' }}
            </span>
            <span v-else-if="upload.status === 'failed'" class="text-red-600 dark:text-red-400 font-medium truncate max-w-[200px]" :title="upload.error">
              {{ upload.error || ($t('upload_manager.failed') || 'Failed') }}
            </span>
            <span v-else-if="upload.status === 'paused'" class="text-amber-600 dark:text-amber-400 font-medium">
              {{ $t('upload_manager.paused') || 'Paused' }}
              · {{ upload.completedChunks?.size || 0 }}/{{ upload.totalChunks }}
            </span>
            <span v-else-if="upload.status === 'cancelled'" class="text-slate-400">
              {{ $t('upload_manager.cancelled') || 'Cancelled' }}
            </span>
            <span v-if="upload.status === 'uploading' && upload.speed > 0" class="text-slate-400">
              {{ formatETA(upload.uploadedBytes, upload.totalBytes, upload.speed) }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useUploads } from '@/Composables/useUploads'
import { useWorkspace } from '@/Composables/useWorkspace'

const { uploads, cancelUpload, pauseUpload, resumeUpload, retryUpload, clearCompleted, formatSize, formatSpeed } = useUploads()
const { patients } = useWorkspace()

function patientName(pid) {
  if (!pid) return ''
  const p = patients.value.find(p => p.id === pid)
  return p ? p.name : ''
}

const minimized = ref(false)

const uploadingCount = computed(() => uploads.value.filter(u => u.status === 'uploading').length)
const pausedCount = computed(() => uploads.value.filter(u => u.status === 'paused').length)
const completedCount = computed(() => uploads.value.filter(u => u.status === 'completed').length)
const activeUploads = computed(() => uploads.value.filter(u => u.status === 'uploading' || u.status === 'failed').length)

function formatETA(loaded, total, speed) {
  if (!speed || speed <= 0) return ''
  const remaining = (total - loaded) / speed
  if (remaining < 5) return '~' + Math.ceil(remaining) + 's'
  if (remaining < 60) return '~' + Math.round(remaining) + 's'
  const mins = Math.floor(remaining / 60)
  const secs = Math.round(remaining % 60)
  return `~${mins}m ${secs}s`
}
</script>
