<template>
  <div>
    <!-- Dropzone (Hidden if Read-Only) -->
    <div 
      v-if="canEdit"
      class="border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-colors mb-6 select-none"
      :class="isDragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-300 dark:border-slate-700 hover:border-primary-400 dark:hover:border-primary-500 bg-slate-50 dark:bg-slate-900/50 hover:bg-slate-100 dark:hover:bg-slate-800'"
      @click="openFileDialog"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="handleDrop"
    >
      <input type="file" multiple ref="fileInput" class="sr-only" @change="handleFileSelect">
      <div class="mx-auto h-12 w-12 text-slate-400 mb-3 flex items-center justify-center pointer-events-none">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
      </div>
      <h3 class="text-sm font-medium text-slate-900 dark:text-white mb-1 pointer-events-none">{{ $t('files.click_to_upload') }}</h3>
      <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 pointer-events-none">{{ $t('files.upload_hint') }}</p>
      <BaseButton variant="outline" size="sm" @click.stop="openFileDialog">{{ $t('files.select_files') }}</BaseButton>
    </div>

    <!-- File Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div v-for="file in files" :key="file.id" 
           @click="isMobile ? (activeSheetFile = file) : undefined"
           class="group relative border dark:border-slate-800 rounded-xl p-2 hover:shadow-md transition-shadow bg-white dark:bg-slate-900 cursor-pointer md:cursor-default">
        <!-- Thumbnail -->
        <div :class="isMobile ? '' : 'pointer-events-none'" class="aspect-square bg-slate-100 dark:bg-slate-800 rounded-lg mb-2 overflow-hidden flex items-center justify-center">
          <img v-if="file.thumbnail_url" :src="file.thumbnail_url" class="object-cover w-full h-full" @error="e => e.target.style.display='none'">
          <img v-else-if="file.mime_type?.startsWith('image/')" :src="file.url" class="object-cover w-full h-full">
          <div v-else-if="file.mime_type?.startsWith('video/')" class="text-slate-400 flex flex-col items-center">
            <svg class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
            <span v-if="['queued', 'processing'].includes(file.upload_status)" class="text-[10px] font-bold uppercase tracking-wider text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full flex items-center">
              <svg class="animate-spin -ms-1 me-1.5 h-3 w-3 text-amber-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
              {{ file.upload_status }}
            </span>
            <span v-else-if="file.upload_status === 'failed'" class="text-[10px] font-bold uppercase tracking-wider text-rose-700 bg-rose-100 px-2 py-0.5 rounded-full">
              {{ $t('files.failed') }}
            </span>
          </div>
          <div v-else class="text-slate-400 font-bold uppercase text-lg">
            {{ file.extension || 'FILE' }}
          </div>
        </div>
        <!-- Info -->
        <div class="px-1 mt-1 flex flex-col">
          <p class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate" :title="file.title || file.name">{{ file.title || file.name }}</p>
          <p v-if="file.desc" class="text-[10px] text-slate-500 dark:text-slate-400 truncate" :title="file.desc">{{ file.desc }}</p>
          <p class="text-[10px] text-slate-400">{{ formatSize(file.size) }}</p>
        </div>
        <!-- Desktop Overlay -->
        <FileActions
          v-if="!isMobile"
          :file="file"
          :canEdit="canEdit"
          mode="overlay"
          :categories="categories"
          @preview="(f) => $emit('preview', f)"
          @file-updated="$emit('uploaded')"
          @file-moved="$emit('uploaded')"
          @file-deleted="$emit('uploaded')"
        />
      </div>
    </div>

    <!-- Mobile Bottom Sheet -->
    <FileActions
      v-if="activeSheetFile"
      :file="activeSheetFile"
      :canEdit="canEdit"
      mode="sheet"
      :categories="categories"
      @preview="(f) => { activeSheetFile = null; $emit('preview', f) }"
      @file-updated="emitRefresh"
      @file-moved="emitRefresh"
      @file-deleted="emitRefresh"
      @close="activeSheetFile = null"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'
import BaseButton from '@/Components/BaseButton.vue'
import FileActions from '@/Components/workspace/FileActions.vue'
import { useUploads } from '@/Composables/useUploads'
import { useNativeBridge } from '@/Composables/useNativeBridge'

const props = defineProps({
  patientId: String,
  category: String,
  files: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: true },
  categories: { type: Array, default: () => [] },
})

const emit = defineEmits(['preview', 'uploaded'])

const { uploadFile } = useUploads()
const { isCameraAvailable, isFilePickerAvailable, pickFiles, takePhoto } = useNativeBridge()

const isMobile = ref(typeof window !== 'undefined' && window.innerWidth < 768)
if (typeof window !== 'undefined') {
  window.addEventListener('resize', () => {
    isMobile.value = window.innerWidth < 768
  })
}

const isDragging = ref(false)
const fileInput = ref(null)
const activeSheetFile = ref(null)

// ---------- Dropzone ----------
const openFileDialog = async (source = 'files') => {
  if (!props.canEdit) return

  if (source === 'camera' && isCameraAvailable()) {
    const photo = await takePhoto()
    if (photo) {
      handleNativeFile(photo)
    }
    return
  }

  if (source !== 'camera' && isFilePickerAvailable()) {
    const files = await pickFiles({ multiple: true, accept: '*/*' })
    if (files && files.length > 0) {
      for (const file of files) {
        handleNativeFile(file)
      }
      return
    }
  }

  fileInput.value?.click()
}

const handleNativeFile = async (nativeFile) => {
  let file
  if (nativeFile instanceof File) {
    file = nativeFile
  } else if (nativeFile.uri) {
    try {
      const response = await fetch(nativeFile.uri)
      const blob = await response.blob()
      file = new File([blob], nativeFile.name || 'file', { type: nativeFile.type || blob.type })
    } catch (e) {
      console.warn('[Native] Failed to read native file:', e)
      return
    }
  } else {
    return
  }

  const metadata = { category: props.category }
  const uploadJob = uploadFile(file, props.patientId, metadata)
  const checkCompletion = () => {
    if (uploadJob.status === 'completed') {
      emit('uploaded')
    } else if (uploadJob.status !== 'uploading') {
      console.warn('Upload failed:', uploadJob.error)
    } else {
      setTimeout(checkCompletion, 100)
    }
  }
  checkCompletion()
}

const handleDrop = (e) => {
  if (!props.canEdit) return
  isDragging.value = false
  handleFiles(Array.from(e.dataTransfer.files))
}

const handleFileSelect = (e) => {
  if (!props.canEdit) return
  handleFiles(Array.from(e.target.files))
  e.target.value = null
}

const handleFiles = (selectedFiles) => {
  for (const file of selectedFiles) {
    const metadata = { category: props.category }
    const uploadJob = uploadFile(file, props.patientId, metadata)
    const checkCompletion = () => {
      if (uploadJob.status === 'completed') {
        emit('uploaded')
      } else if (uploadJob.status !== 'uploading') {
        console.warn('Upload failed:', uploadJob.error)
      } else {
        setTimeout(checkCompletion, 100)
      }
    }
    checkCompletion()
  }
}

function emitRefresh() {
  activeSheetFile.value = null
  emit('uploaded')
}

function formatSize(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

defineExpose({ triggerUpload: openFileDialog })
</script>
