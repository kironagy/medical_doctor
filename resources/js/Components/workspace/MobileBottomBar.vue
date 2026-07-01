<template>
  <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 z-50 pb-safe shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
    <div class="flex items-center justify-around h-14">
      <button @click="$emit('toggle-patients')" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 active:text-primary-600 dark:active:text-primary-400 transition-colors">
        <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
        <span class="text-[10px] font-medium">Patients</span>
      </button>

      <button v-if="selectedPatient" @click="$emit('scroll-to', 'summary')" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 active:text-primary-600 dark:active:text-primary-400 transition-colors">
        <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
        <span class="text-[10px] font-medium">Summary</span>
      </button>

      <button v-if="selectedPatient" @click="$emit('scroll-to', 'actions')" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 active:text-primary-600 dark:active:text-primary-400 transition-colors">
        <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" /></svg>
        <span class="text-[10px] font-medium">Actions</span>
      </button>

      <button v-if="selectedPatient" @click="$emit('scroll-to', 'records')" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 active:text-primary-600 dark:active:text-primary-400 transition-colors">
        <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
        <span class="text-[10px] font-medium">Records</span>
      </button>

      <button v-if="selectedPatient" @click="$emit('scroll-to', 'sharing')" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 active:text-primary-600 dark:active:text-primary-400 transition-colors">
        <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
        <span class="text-[10px] font-medium">Sharing</span>
      </button>
    </div>
  </nav>
</template>

<script setup>
import { useWorkspace } from '@/Composables/useWorkspace'

defineEmits(['toggle-patients', 'scroll-to'])

const { selectedPatient } = useWorkspace()
</script>
