<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
        Recurring Visits
      </h3>
      <button v-if="canEditComputed" @click="showAddForm = !showAddForm" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
        Add Visit
      </button>
    </div>

    <div v-if="showAddForm" class="mb-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-4">
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div>
          <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Visit Type</label>
          <input type="text" v-model="newVisit.visitType" placeholder="e.g. Follow-up" class="w-full px-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Date</label>
          <input type="date" v-model="newVisit.date" class="w-full px-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
        </div>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Reason</label>
        <textarea v-model="newVisit.reason" rows="2" placeholder="Reason for visit..." class="w-full px-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white"></textarea>
      </div>
      <div class="flex justify-end gap-2">
        <button @click="showAddForm = false" class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg">Cancel</button>
        <button @click="saveVisit" class="px-3 py-1.5 text-xs font-medium bg-primary-600 text-white rounded-lg hover:bg-primary-700">Save</button>
      </div>
    </div>

    <div class="space-y-2">
      <div v-for="visit in visitsList" :key="visit.id" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 flex items-center justify-center">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
            </div>
            <div>
              <p class="text-sm font-medium text-slate-900 dark:text-white">{{ visit.visit_type || 'Visit' }}</p>
              <p class="text-xs text-slate-500 dark:text-slate-400">{{ visit.reason || '—' }}</p>
            </div>
          </div>
          <div class="text-right">
            <p class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ new Date(visit.visit_date || visit.created_at).toLocaleDateString() }}</p>
            <p v-if="visit.next_visit_date" class="text-[10px] text-primary-600 dark:text-primary-400">Next: {{ new Date(visit.next_visit_date).toLocaleDateString() }}</p>
          </div>
        </div>
      </div>
      <div v-if="visitsList.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <p class="text-sm text-slate-500 dark:text-slate-400">No visits recorded</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import axios from 'axios'

const { workspaceData, selectedPatient, canEdit } = useWorkspace()

const canEditComputed = computed(() => canEdit.value)
const showAddForm = ref(false)
const newVisit = ref({ visitType: '', date: '', reason: '' })

const visitsList = computed(() => {
  return workspaceData.value?.visits || []
})

async function saveVisit() {
  if (!selectedPatient.value || !newVisit.value.visitType) return
  try {
    await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/visits`, {
      visit_type: newVisit.value.visitType,
      visit_date: newVisit.value.date,
      reason: newVisit.value.reason,
    })
    showAddForm.value = false
    newVisit.value = { visitType: '', date: '', reason: '' }
    const { selectPatient } = useWorkspace()
    selectPatient(selectedPatient.value.uuid)
  } catch (e) {
    console.error('Failed to save visit', e)
  }
}
</script>
