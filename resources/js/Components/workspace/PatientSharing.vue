<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
        Patient Sharing
      </h3>
      <button v-if="canShareComputed" @click="showSharePanel = !showSharePanel" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
        Share
      </button>
    </div>

    <div v-if="showSharePanel" class="mb-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-4">
      <div class="mb-3">
        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Search Doctor</label>
        <div class="relative">
          <input
            type="text"
            v-model="searchQuery"
            @input="debouncedSearch"
            placeholder="Search doctor by name..."
            class="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white"
          />
          <svg class="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </div>
        <div v-if="searchResults.length > 0" class="mt-1 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
          <button v-for="doc in searchResults" :key="doc.id" @click="selectDoctor(doc)"
            class="w-full flex items-center justify-between px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 text-left text-xs"
          >
            <div>
              <p class="font-medium text-slate-900 dark:text-white">{{ doc.name }}</p>
              <p class="text-slate-500">{{ doc.specialization || 'General' }} • {{ doc.code }}</p>
            </div>
            <span class="px-2 py-0.5 bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400 rounded text-[10px] font-medium">Select</span>
          </button>
        </div>
      </div>
      <div v-if="selectedTargetDoctor" class="flex items-center justify-between mb-3 px-3 py-2 bg-primary-50 dark:bg-primary-900/20 rounded-lg">
        <span class="text-xs font-medium text-slate-900 dark:text-white">{{ selectedTargetDoctor.name }}</span>
        <button @click="selectedTargetDoctor = null" class="text-[10px] text-primary-600 dark:text-primary-400">Change</button>
      </div>
      <div v-if="selectedTargetDoctor" class="mb-3">
        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Access Level</label>
        <div class="flex gap-2">
          <button @click="accessLevel = 'read'"
            class="flex-1 px-3 py-2 text-xs rounded-lg border transition-colors"
            :class="accessLevel === 'read' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400' : 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400'"
          >Read Only</button>
          <button @click="accessLevel = 'read_write'"
            class="flex-1 px-3 py-2 text-xs rounded-lg border transition-colors"
            :class="accessLevel === 'read_write' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400' : 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400'"
          >Read & Write</button>
        </div>
      </div>
      <div class="flex justify-end gap-2">
        <button @click="showSharePanel = false" class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg">Cancel</button>
        <button @click="sharePatient" :disabled="!selectedTargetDoctor" class="px-3 py-1.5 text-xs font-medium bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
          Share Access
        </button>
      </div>
    </div>

    <div class="space-y-2">
      <div v-for="share in sharesList" :key="share.id" class="flex items-center justify-between bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-bold">
            {{ share.doctor?.name?.charAt(0) || 'D' }}
          </div>
          <div>
            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ share.doctor?.name || 'Doctor' }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">
              {{ share.access_level === 'read_write' ? 'Read & Write' : 'Read Only' }}
            </p>
          </div>
        </div>
        <button @click="revokeShare(share)" class="text-[11px] font-medium text-rose-600 dark:text-rose-400 hover:text-rose-700 px-2 py-1 rounded hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors">Revoke</button>
      </div>
      <div v-if="sharesList.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <p class="text-sm text-slate-500 dark:text-slate-400">Patient not shared with anyone</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import axios from 'axios'

const dialog = useDialog()
const toast = useToast()

const { workspaceData, selectedPatient, canShare } = useWorkspace()

const canShareComputed = computed(() => canShare.value)
const showSharePanel = ref(false)
const searchQuery = ref('')
const searchResults = ref([])
const selectedTargetDoctor = ref(null)
const accessLevel = ref('read')

const sharesList = computed(() => {
  return workspaceData.value?.shares || []
})

let searchTimeout = null

function debouncedSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (searchQuery.value.length < 2) {
    searchResults.value = []
    return
  }
  searchTimeout = setTimeout(async () => {
    try {
      const res = await axios.get('/api/v1/doctors/search', { params: { q: searchQuery.value } })
      searchResults.value = res.data
    } catch (e) {
      console.error(e)
    }
  }, 300)
}

function selectDoctor(doc) {
  selectedTargetDoctor.value = doc
  searchQuery.value = ''
  searchResults.value = []
}

async function sharePatient() {
  if (!selectedTargetDoctor.value || !selectedPatient.value) return
  try {
    await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/shares`, {
      doctor_id: selectedTargetDoctor.value.id,
      access_level: accessLevel.value,
    })
    selectedTargetDoctor.value = null
    showSharePanel.value = false
    const { selectPatient } = useWorkspace()
    selectPatient(selectedPatient.value.uuid)
    toast.success('Patient shared successfully')
  } catch (e) {
    console.error('Share failed', e)
    toast.error('Failed to share patient')
  }
}

async function revokeShare(share) {
  if (!selectedPatient.value) return
  const confirmed = await dialog.confirm({
    title: 'Revoke Access',
    message: `Revoke ${share.doctor?.name || 'doctor'}'s access to this patient?`,
    confirmText: 'Revoke',
    style: 'warning',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/patients/${selectedPatient.value.uuid}/shares/${share.id}`)
    const { selectPatient } = useWorkspace()
    selectPatient(selectedPatient.value.uuid)
    toast.success('Access revoked')
  } catch (e) {
    console.error('Revoke failed', e)
    toast.error('Failed to revoke access')
  }
}
</script>
