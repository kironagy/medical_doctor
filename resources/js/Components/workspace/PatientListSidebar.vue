<template>
  <aside
    class="flex flex-col h-full bg-white dark:bg-slate-900 border-l border-slate-200 dark:border-slate-800 relative"
  >
    <!-- Add Patient Button at the top (Desktop Only) -->
    <div v-if="!isMobile" class="p-3 pb-2 flex-shrink-0">
      <button
        @click="$emit('add-patient')"
        class="w-full flex items-center justify-center gap-2 py-2.5 text-sm font-bold text-white bg-primary-600 hover:bg-primary-700 rounded-lg transition-colors shadow-sm"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
        {{ $t('patients.new') }}
      </button>
    </div>

    <!-- Search Box -->
    <div class="px-3 pb-3 border-b border-slate-100 dark:border-slate-800 flex-shrink-0" :class="isMobile ? 'pt-14' : 'pt-3'">
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          :placeholder="$t('patients.search_short')"
          class="w-full ps-3 pe-10 py-2.5 text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-900 dark:text-white placeholder:text-slate-400 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-start font-medium"
        />
        <svg class="absolute end-3 top-3 w-4.5 h-4.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
      </div>
    </div>

    <!-- Patients List -->
    <div class="flex-1 overflow-y-auto overscroll-contain p-2 space-y-1">
      <div v-for="patient in filteredPatients" :key="patient.uuid">
        <button
          @click="selectAndClose(patient.uuid)"
          class="w-full text-start p-3.5 rounded-xl border transition-all"
          :class="selectedPatientId === patient.uuid
            ? 'bg-teal-50 dark:bg-teal-950/20 border-primary-500 dark:border-primary-400 shadow-sm'
            : 'bg-white dark:bg-slate-900 border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/50'"
        >
          <div class="flex items-center justify-between gap-2 mb-2">
            <!-- Code Badge (Far Right in RTL) -->
            <span class="text-xs px-2.5 py-0.5 rounded-lg font-bold bg-teal-50 dark:bg-teal-950/50 border border-teal-200 dark:border-teal-800 text-primary-700 dark:text-primary-400 flex-shrink-0">
              #{{ patient.code }}
            </span>

            <!-- Sync Status Badge -->
            <span v-if="patient.sync_status && patient.sync_status !== 'synced'" class="text-[10px] px-1.5 py-0.5 rounded-md font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 border border-amber-300 dark:border-amber-700 flex-shrink-0 me-1">
              ⏳
            </span>
            
            <!-- Name (Far Left in RTL, so it floats left) -->
            <p class="text-sm font-bold text-slate-900 dark:text-white truncate text-left">
              {{ patient.name }}
            </p>
          </div>

          <!-- Phone (Aligned to Left in RTL) -->
          <div class="flex items-center justify-end gap-1.5 text-xs text-slate-500 dark:text-slate-400" dir="ltr">
            <span class="truncate font-semibold">{{ patient.phone || '—' }}</span>
            <svg class="w-3.5 h-3.5 text-primary-600 dark:text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
          </div>
        </button>
      </div>

      <div v-if="filteredPatients.length === 0" class="flex flex-col items-center justify-center h-40 text-slate-400 dark:text-slate-500 px-4">
        <svg class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
        <p class="text-sm">{{ $t('patients.no_patients_short') }}</p>
      </div>
    </div>

    <!-- Floating Action Button for Mobile -->
    <button
      v-if="isMobile"
      @click="$emit('add-patient')"
      class="absolute bottom-24 left-6 z-50 w-12 h-12 bg-primary-600 hover:bg-primary-700 active:scale-95 text-white rounded-full flex items-center justify-center shadow-lg transition-transform"
    >
      <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
    </button>

    <!-- Pagination & Settings Button at the bottom -->
    <div class="border-t border-slate-100 dark:border-slate-800 p-3 bg-white dark:bg-slate-900 space-y-2.5 flex-shrink-0">
      <!-- Pagination -->
      <div v-if="patientsMeta && patientsMeta.last_page > 1" class="flex items-center justify-between gap-2 text-xs font-bold">
        <button
          @click="refreshPatientList(patientsMeta.current_page - 1)"
          :disabled="patientsMeta.current_page <= 1"
          class="px-3 py-1.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-800 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors"
        >
          {{ $t('common.previous') }}
        </button>
        <span class="text-slate-500">{{ patientsMeta.current_page }} / {{ patientsMeta.last_page }}</span>
        <button
          @click="refreshPatientList(patientsMeta.current_page + 1)"
          :disabled="patientsMeta.current_page >= patientsMeta.last_page"
          class="px-3 py-1.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-800 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors"
        >
          {{ $t('common.next') }}
        </button>
      </div>

      <!-- Settings Button -->
      <button
        @click="openSettings()"
        class="w-full flex items-center justify-center gap-2 py-2.5 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg text-sm transition-colors"
      >
        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
        {{ $t('nav.settings') }}
      </button>
    </div>
  </aside>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { useWorkspace } from '@/Composables/useWorkspace'
import { computed } from 'vue'

const props = defineProps({
  user: Object,
  mobileOpen: Boolean,
})

const emit = defineEmits(['close', 'add-patient'])

import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import { useI18n } from 'vue-i18n'

const dialog = useDialog()
const toast = useToast()
const { t, locale } = useI18n()
const isRtl = computed(() => locale.value === 'ar')

const {
  searchQuery,
  filteredPatients,
  selectedPatientId,
  selectPatient,
  patients,
  archivedPatients,
  showArchived,
  fetchArchivedPatients,
  restorePatient,
  forceDeletePatient,
  isMobile,
  navigateTo,
  patientsMeta,
  archivedPatientsMeta,
  refreshPatientList,
  loadingPatients,
  loadingArchived,
  openSettings,
} = useWorkspace()

function toggleArchived() {
  showArchived.value = !showArchived.value
  if (showArchived.value && archivedPatients.value.length === 0) {
    fetchArchivedPatients()
  }
}

async function handleRestore(uuid) {
  const confirmed = await dialog.confirm({
    title: t('common.restore'),
    message: t('workspace.restore_confirm'),
    confirmText: t('common.restore'),
    style: 'info',
  })
  if (!confirmed) return
  const result = await restorePatient(uuid)
  if (result.success) {
    toast.success(t('common.success'))
    if (archivedPatients.value.length === 0) {
      showArchived.value = false
    }
  } else {
    toast.error(t('common.error'))
  }
}

async function handleForceDelete(uuid) {
  const confirmed = await dialog.confirm({
    title: t('workspace.delete_patient'),
    message: t('workspace.force_delete_confirm'),
    confirmText: t('common.force_delete'),
    style: 'danger',
  })
  if (!confirmed) return
  const result = await forceDeletePatient(uuid)
  if (result.success) {
    toast.success(t('common.success'))
    if (archivedPatients.value.length === 0) {
      showArchived.value = false
    }
  } else {
    toast.error(t('common.error'))
  }
}

function selectAndClose(uuid) {
  selectPatient(uuid)
  emit('close')
}

function handleLogout() {
  router.post('/logout')
}
</script>
