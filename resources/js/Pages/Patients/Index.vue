<template>
  <AppLayout title="Patients">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
      <div class="relative w-full sm:max-w-md">
        <div class="absolute inset-y-0 left-0 rtl:left-auto rtl:right-0 ps-3 flex items-center pointer-events-none">
          <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </div>
        <input type="text" class="input-field ps-10" :placeholder="$t('patients.search_placeholder')" />
      </div>
      <Link href="/patients/create" class="btn-primary w-full sm:w-auto inline-flex items-center justify-center">
        <svg class="w-4 h-4 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        {{ $t('patients.new') }}
      </Link>
    </div>

    <BaseCard :padding="false">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-start text-slate-500 dark:text-slate-400">
          <thead class="text-xs text-slate-700 dark:text-slate-300 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
            <tr>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.name') }}</th>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.id') }}</th>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.diagnosis') }}</th>
              <th scope="col" class="px-6 py-4 font-medium text-end whitespace-nowrap">{{ $t('common.actions') || 'Actions' }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="patient in patients" :key="patient.id" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
              <td class="px-6 py-4 font-medium text-slate-900 dark:text-white flex items-center whitespace-nowrap text-start">
                <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold me-3 flex-shrink-0">
                  {{ patient.name.charAt(0) }}
                </div>
                {{ patient.name }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-start text-slate-700 dark:text-slate-300">{{ patient.code }}</td>
              <td class="px-6 py-4 whitespace-nowrap text-slate-500 dark:text-slate-400 truncate max-w-xs text-start">{{ patient.diagnosis || $t('patients.not_specified') }}</td>
              <td class="px-6 py-4 text-end whitespace-nowrap">
                <Link :href="`/patients/${patient.uuid}`" class="text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium text-sm">{{ $t('patients.view_profile') }}</Link>
              </td>
            </tr>
            <tr v-if="patients.length === 0">
              <td colspan="4" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                {{ $t('patients.no_patients') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';
import BaseButton from '@/Components/BaseButton.vue';

defineProps({
  patients: Array
});
</script>
