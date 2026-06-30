<template>
  <AppLayout title="Dashboard">
    <div class="mb-6">
      <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">
        {{ isSuperAdmin ? $t('dashboard.global_overview') : $t('dashboard.my_practice') }}
      </h1>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      
      <template v-if="isSuperAdmin">
        <!-- Admin Only Stats -->
        <BaseCard class="bg-gradient-to-br from-indigo-500 to-indigo-700 text-white border-0 shadow-lg">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-indigo-100 text-sm font-medium mb-1">{{ $t('dashboard.total_doctors') }}</p>
              <h3 class="text-3xl font-bold font-heading">{{ stats.total_doctors }}</h3>
            </div>
            <div class="p-3 bg-white/20 rounded-xl backdrop-blur-sm">
              <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
            </div>
          </div>
        </BaseCard>

        <BaseCard class="bg-white dark:bg-slate-800 border-0 shadow-sm dark:shadow-none dark:border dark:border-slate-700">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mb-1">{{ $t('dashboard.active_doctors') }}</p>
              <h3 class="text-3xl font-bold font-heading text-slate-800 dark:text-white">{{ stats.active_doctors }}</h3>
            </div>
            <div class="p-3 bg-emerald-50 dark:bg-emerald-900/30 rounded-xl">
              <svg class="w-8 h-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
          </div>
        </BaseCard>
      </template>

      <!-- Common Stats -->
      <BaseCard :class="!isSuperAdmin ? 'bg-gradient-to-br from-primary-500 to-primary-700 text-white border-0 shadow-lg' : 'bg-white dark:bg-slate-800 border-0 shadow-sm dark:shadow-none dark:border dark:border-slate-700'">
        <div class="flex items-center justify-between">
          <div>
            <p :class="!isSuperAdmin ? 'text-primary-100' : 'text-slate-500 dark:text-slate-400'" class="text-sm font-medium mb-1">{{ $t('dashboard.total_patients') }}</p>
            <h3 class="text-3xl font-bold font-heading" :class="!isSuperAdmin ? 'text-white' : 'text-slate-800 dark:text-white'">{{ stats.total_patients }}</h3>
          </div>
          <div :class="!isSuperAdmin ? 'bg-white/20 backdrop-blur-sm' : 'bg-slate-50 dark:bg-slate-900/50'" class="p-3 rounded-xl">
            <svg class="w-8 h-8" :class="!isSuperAdmin ? 'text-white' : 'text-primary-600 dark:text-primary-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
          </div>
        </div>
      </BaseCard>

      <BaseCard class="bg-white dark:bg-slate-800 border-0 shadow-sm dark:shadow-none dark:border dark:border-slate-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mb-1">{{ $t('dashboard.total_files') }}</p>
            <h3 class="text-3xl font-bold font-heading text-slate-800 dark:text-white">{{ stats.recent_files }}</h3>
          </div>
          <div class="p-3 bg-slate-50 dark:bg-slate-900/50 rounded-xl">
            <svg class="w-8 h-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
          </div>
        </div>
      </BaseCard>

    </div>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';

defineProps({
  stats: Object,
  isSuperAdmin: Boolean,
});
</script>
