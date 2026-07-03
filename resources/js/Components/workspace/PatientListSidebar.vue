<template>
  <aside
    class="flex flex-col h-full bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800"
    :class="[
      isMobile
        ? 'fixed inset-y-0 left-0 z-40 w-[300px] shadow-2xl transition-transform duration-300 ease-in-out'
        : 'relative w-full',
      isMobile && !mobileOpen ? '-translate-x-full' : ''
    ]"
  >
    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
      <div class="flex items-center gap-2.5">
        <div class="w-7 h-7 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold text-xs">M</div>
        <span class="font-heading font-semibold text-sm text-slate-800 dark:text-white">Patients</span>
        <span class="text-[11px] text-slate-400">({{ patients.length }})</span>
      </div>
      <button v-if="isMobile" @click="$emit('close')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <div class="p-3 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
      <div class="relative">
        <svg class="absolute left-3 top-2.5 w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search by name, phone, ID..."
          class="w-full pl-9 pr-3 py-2 text-sm bg-slate-100 dark:bg-slate-800 border-0 rounded-lg text-slate-900 dark:text-white placeholder:text-slate-400 focus:ring-2 focus:ring-primary-500 transition-all"
        />
      </div>
      <button
        @click="$emit('add-patient')"
        class="mt-2 w-full flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium text-primary-700 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/30 hover:bg-primary-100 dark:hover:bg-primary-900/50 rounded-lg transition-colors"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
        Add Patient
      </button>
    </div>

    <div class="flex-1 overflow-y-auto overscroll-contain">
      <div v-if="showArchived && archivedPatients.length > 0" class="px-3 pt-3 pb-1">
        <div class="flex items-center justify-between mb-1">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $t('workspace.archived_patients') }}</span>
          <span class="text-[10px] text-slate-400">({{ archivedPatients.length }})</span>
        </div>
      </div>
      <template v-if="showArchived">
        <div v-for="patient in archivedPatients" :key="'arch-' + patient.uuid" class="px-2 py-0.5">
          <div
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left transition-all border border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/50"
          >
            <button @click="selectAndClose(patient.uuid)" class="flex items-center gap-3 min-w-0 flex-1">
              <div class="relative flex-shrink-0">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
                </div>
              </div>
              <div class="min-w-0">
                <div class="flex items-center justify-between">
                  <p class="text-sm font-medium text-slate-500 dark:text-slate-400 truncate">{{ patient.name }}</p>
                <span class="text-[10px] px-1.5 py-0.5 rounded-full font-medium bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 ml-2">{{ $t('common.archived') }}</span>
              </div>
              <div class="flex items-center gap-1.5 mt-0.5 text-xs text-slate-400">
                <span class="truncate">{{ patient.phone || '—' }}</span>
                <span>•</span>
                <span>{{ patient.code }}</span>
              </div>
            </div>
          </button>
          <div class="flex items-center gap-1 flex-shrink-0">
            <button @click.stop="handleRestore(patient.uuid)" class="p-1.5 rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-900/20 text-slate-400 hover:text-emerald-600 transition-colors" :title="$t('common.restore')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
            </button>
            <button @click.stop="handleForceDelete(patient.uuid)" class="p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-900/20 text-slate-400 hover:text-rose-600 transition-colors" :title="$t('common.force_delete')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>
          </div>
        </div>
      </div>
      </template>

      <div v-if="showArchived && archivedPatients.length === 0 && !searchQuery" class="flex flex-col items-center justify-center h-24 text-slate-400 dark:text-slate-500 px-4">
        <p class="text-xs">{{ $t('workspace.no_archived') }}</p>
      </div>

      <div v-if="showArchived && archivedPatients.length > 0" class="border-t border-slate-100 dark:border-slate-800 mx-3 my-2"></div>

      <div v-if="!searchQuery" class="px-3 pt-1 pb-1">
        <div class="flex items-center justify-between mb-1">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $t('workspace.active_patients') }}</span>
          <span class="text-[10px] text-slate-400">({{ patients.length }})</span>
        </div>
      </div>

      <div v-if="filteredPatients.length === 0 && !(showArchived && archivedPatients.length > 0)" class="flex flex-col items-center justify-center h-40 text-slate-400 dark:text-slate-500 px-4">
        <svg class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
        <p class="text-sm">{{ $t('workspace.no_patients') }}</p>
      </div>
      <div v-for="patient in filteredPatients" :key="patient.uuid" class="px-2 py-0.5">
        <button
          @click="selectAndClose(patient.uuid)"
          class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left transition-all active:scale-[0.98]"
          :class="selectedPatientId === patient.uuid
            ? 'bg-primary-50 dark:bg-primary-900/30 border border-primary-200 dark:border-primary-800'
            : 'hover:bg-slate-50 dark:hover:bg-slate-800/50 border border-transparent'"
        >
          <div class="relative flex-shrink-0">
            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold"
              :class="selectedPatientId === patient.uuid
                ? 'bg-primary-200 dark:bg-primary-800 text-primary-700 dark:text-primary-300'
                : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'"
            >
              {{ patient.name?.charAt(0) || '?' }}
            </div>
            <div v-if="patient.unread" class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-rose-500 border-2 border-white dark:border-slate-900 rounded-full"></div>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between">
              <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ patient.name }}</p>
              <span
                class="text-[10px] px-1.5 py-0.5 rounded-full font-medium flex-shrink-0"
                :class="patient.status === 'active'
                  ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                  : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'"
              >{{ patient.status }}</span>
            </div>
            <div class="flex items-center gap-1.5 mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              <span class="truncate">{{ patient.phone || '—' }}</span>
              <span>•</span>
              <span>{{ patient.code }}</span>
            </div>
            <p v-if="patient.last_visit" class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
              {{ $t('common.last_visit') }}: {{ new Date(patient.last_visit).toLocaleDateString() }}
            </p>
          </div>
        </button>
      </div>
      <div class="h-4"></div>
    </div>

    <div class="border-t border-slate-100 dark:border-slate-800 px-3 py-2">
      <button @click="toggleArchived" class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
        <span class="flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-400">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
          {{ $t('workspace.show_archived') }}
        </span>
        <div class="w-4 h-4 rounded border border-slate-300 dark:border-slate-600 flex items-center justify-center transition-colors" :class="showArchived ? 'bg-primary-600 border-primary-600' : ''">
          <svg v-if="showArchived" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
        </div>
      </button>
    </div>

    <div class="border-t border-slate-200 dark:border-slate-800 p-3 flex-shrink-0">
      <div class="flex items-center gap-3 px-2 py-2">
        <div class="w-9 h-9 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
          <img v-if="user?.avatar_url" :src="user.avatar_url" class="w-full h-full object-cover" />
          <span v-else class="text-sm font-bold text-slate-500 dark:text-slate-400">{{ user?.name?.charAt(0) || 'D' }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ user?.name || 'Doctor' }}</p>
          <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">{{ user?.specialization || 'Doctor' }}</p>
        </div>
      </div>
      <div class="flex items-center gap-1 mt-1">
        <button @click="navigateTo('/settings')" class="flex-1 flex items-center justify-center gap-1.5 px-2 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          Settings
        </button>
        <button @click="handleLogout" class="flex-1 flex items-center justify-center gap-1.5 px-2 py-1.5 text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-lg transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
          Logout
        </button>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { useWorkspace } from '@/Composables/useWorkspace'

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
const { t } = useI18n()

const { searchQuery, filteredPatients, selectedPatientId, selectPatient, patients, archivedPatients, showArchived, fetchArchivedPatients, restorePatient, forceDeletePatient, isMobile, navigateTo } = useWorkspace()

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
