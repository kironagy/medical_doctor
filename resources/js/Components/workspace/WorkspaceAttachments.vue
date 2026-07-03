<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
        Attachments (All Categories)
      </h3>
      <div class="flex items-center gap-2">
        <div class="flex gap-1">
          <button
            v-for="cat in categories"
            :key="cat.id"
            @click="selectedCategory = cat.id"
            class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-colors"
            :class="selectedCategory === cat.id
              ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400'
              : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700'"
          >{{ cat.name }}</button>
        </div>
        <button v-if="canEdit" @click="openUpload" class="p-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" title="Upload">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
        </button>
      </div>
    </div>

    <FileManager
      ref="fileManagerRef"
      :patientId="patientUuid"
      :category="selectedCategory"
      :files="filteredFiles"
      :canEdit="canEdit"
      @uploaded="reloadFiles"
      @preview="handlePreview"
    />

    <UnifiedMediaViewer :show="!!activeMedia" :file="activeMedia" @close="activeMedia = null" />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import FileManager from '@/Components/FileManager.vue'
import UnifiedMediaViewer from '@/Components/UnifiedMediaViewer.vue'

const { workspaceData, selectedPatient, canEdit } = useWorkspace()


const patientUuid = computed(() => selectedPatient.value?.uuid || '')

const fileManagerRef = ref(null)
const activeMedia = ref(null)
const selectedCategory = ref('medical_history')

const categories = [
  { id: 'medical_history', name: 'History' },
  { id: 'pre_op', name: 'Pre-Op' },
  { id: 'post_op', name: 'Post-Op' },
  { id: 'operation_sheet', name: 'Operation' },
  { id: 'medications', name: 'Medications' },
  { id: 'notes', name: 'Notes' },
]

const filteredFiles = computed(() => {
  const data = workspaceData.value
  if (!data?.files) return []
  return data.files.filter(f => f.category === selectedCategory.value)
})

function openUpload() {
  fileManagerRef.value?.triggerUpload()
}

function handlePreview(file) {
  activeMedia.value = file
}

function reloadFiles() {
  if (selectedPatient.value?.uuid) {
    const { selectPatient } = useWorkspace()
    selectPatient(selectedPatient.value.uuid)
  }
}
</script>
