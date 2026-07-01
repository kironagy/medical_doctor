<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
        Pre-Operation Notes
      </h3>
      <span class="text-xs text-slate-400">Auto-saves</span>
    </div>

    <div class="mb-4">
      <RichTextEditor
        :patientId="patientId"
        category="pre_op"
        :notes="filteredNotes"
        :disabled="!canEditComputed"
        @saved="reload"
      />
    </div>

    <div v-if="canEditComputed" class="flex items-center gap-2 px-3 py-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-xs text-amber-700 dark:text-amber-400">
      <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      <span>Notes are auto-saved. Use @mentions to reference doctors or patients.</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useWorkspace } from '@/Composables/useWorkspace'
import RichTextEditor from '@/Components/RichTextEditor.vue'

const { workspaceData, selectedPatient, canEdit } = useWorkspace()

const canEditComputed = computed(() => canEdit.value)
const patientId = computed(() => selectedPatient.value?.id || null)

const filteredNotes = computed(() => {
  const data = workspaceData.value
  if (!data?.notes) return []
  return data.notes.filter(n => n.category === 'pre_op')
})

function reload() {
  if (selectedPatient.value?.uuid) {
    const { selectPatient } = useWorkspace()
    selectPatient(selectedPatient.value.uuid)
  }
}
</script>
