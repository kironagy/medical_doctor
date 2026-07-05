<template>
  <AppLayout title="Dashboard">
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Total Patients</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.total_patients || 0 }}</p>
        </div>
        <div class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Recent Files</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.recent_files || 0 }}</p>
        </div>
        <div class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Active Shares</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.active_shares || 0 }}</p>
        </div>
        <div v-if="isSuperAdmin" class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Active Doctors</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.active_doctors || 0 }}</p>
        </div>
      </div>

      <DownloadAppForm
        :visible="showDownload"
        @close="showDownload = false"
      />

      <button
        v-if="!showDownload"
        @click="showDownload = true"
        class="flex items-center justify-center w-full px-6 py-3 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-xl transition-colors"
      >
        <svg class="w-5 h-5 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        Download App
      </button>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DownloadAppForm from '@/Pages/Settings/Partials/DownloadAppForm.vue';

const props = defineProps({
  stats: { type: Object, default: () => ({}) },
  isSuperAdmin: { type: Boolean, default: false }
});

const showDownload = ref(true);
</script>
