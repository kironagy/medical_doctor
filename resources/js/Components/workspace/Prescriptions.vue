<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
        Prescriptions
      </h3>
    </div>

    <div class="space-y-2">
      <div v-for="rx in prescriptionsList" :key="rx.id" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5">
        <div class="flex items-start justify-between">
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 rounded-lg bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 flex items-center justify-center flex-shrink-0 mt-0.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
            </div>
            <div>
              <p class="text-sm font-medium text-slate-900 dark:text-white">{{ rx.title || rx.file_name || 'Prescription' }}</p>
              <p v-if="rx.desc" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ rx.desc }}</p>
              <p class="text-[11px] text-slate-400 mt-1">{{ new Date(rx.created_at).toLocaleDateString() }}</p>
            </div>
          </div>
          <a :href="rx.url" target="_blank" class="text-primary-600 dark:text-primary-400 hover:text-primary-700 p-1" title="Download">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
          </a>
        </div>
      </div>
      <div v-if="prescriptionsList.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <p class="text-sm text-slate-500 dark:text-slate-400">No prescriptions recorded</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { workspaceData } = useWorkspace()

const prescriptionsList = computed(() => {
  const data = workspaceData.value
  if (!data?.files) return []
  return data.files.filter(f => f.category === 'medications')
})
</script>
