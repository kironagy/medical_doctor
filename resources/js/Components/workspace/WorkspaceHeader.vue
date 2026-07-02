<template>
  <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-950/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
    <div class="flex items-center justify-between px-3 md:px-5 h-14 md:h-16">
      <div class="flex items-center gap-2 min-w-0">
        <button @click="$emit('toggle-patients')" class="p-2 -ml-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 md:hidden" :title="$t('workspace.patient_list')">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <button @click="toggleSidebar" class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 hidden md:inline-flex" :title="sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'">
          <svg class="w-4 h-4 transition-transform duration-200" :class="sidebarOpen ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        </button>

        <div v-if="selectedPatient" class="flex items-center gap-2.5 min-w-0">
          <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-700 dark:text-primary-300 font-bold text-sm flex-shrink-0">
            {{ selectedPatient.name?.charAt(0) }}
          </div>
          <div class="min-w-0 hidden xs:block">
            <div class="flex items-center gap-1.5">
              <h2 class="text-sm md:text-base font-bold font-heading text-slate-900 dark:text-white truncate max-w-[160px] md:max-w-xs">{{ selectedPatient.name }}</h2>
              <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded"
                :class="isPrimaryDoctor ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'"
              >{{ isPrimaryDoctor ? $t('workspace.primary') : $t('workspace.shared') }}</span>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400 truncate">
              <span class="inline">{{ selectedPatient.code }}</span>
              <span>•</span>
              <span>{{ selectedPatient.phone || '—' }}</span>
            </div>
          </div>
        </div>

        <div v-else class="flex items-center gap-2">
          <div class="w-9 h-9 rounded-full bg-slate-200 dark:bg-slate-800 flex items-center justify-center text-slate-400">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
          </div>
          <div class="hidden xs:block">
            <p class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('nav.workspace') }}</p>
            <p class="text-[11px] text-slate-500">{{ $t('workspace.select_patient_to_begin') }}</p>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-0.5 md:gap-1">
        <button @click="openSettings()" class="p-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-200 transition-colors" :title="$t('nav.settings')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
        </button>
        <div v-if="selectedPatient" class="relative">
          <button data-action="actions" @click="$emit('toggle-actions')" class="p-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-200 transition-colors" :title="$t('common.actions')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" /></svg>
          </button>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup>
import { useWorkspace } from '@/Composables/useWorkspace'

defineEmits(['toggle-patients', 'toggle-actions'])

const { selectedPatient, isPrimaryDoctor, sidebarOpen, openSettings, toggleSidebar } = useWorkspace()
</script>
