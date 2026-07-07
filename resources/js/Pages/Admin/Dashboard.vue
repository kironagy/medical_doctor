<template>
  <AppLayout title="Admin Dashboard">
    <div class="max-w-7xl mx-auto space-y-6">
      <!-- Stats Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Total Doctors</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.total_doctors || 0 }}</p>
        </div>
        <div class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Active Doctors</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.active_doctors || 0 }}</p>
        </div>
        <div class="p-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Inactive Doctors</p>
          <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ stats.inactive_doctors || 0 }}</p>
        </div>
      </div>

      <!-- Add Doctor CTA Card -->
      <div class="bg-gradient-to-r from-primary-600 to-primary-700 rounded-xl p-6 shadow-lg">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
          <div class="text-white">
            <h2 class="text-xl font-bold font-heading mb-1">Manage Doctors</h2>
            <p class="text-primary-100">Add new doctors or manage existing ones</p>
          </div>
          <div class="flex gap-3">
            <Link href="/admin/doctors" class="px-5 py-2.5 bg-white text-primary-700 font-medium rounded-lg hover:bg-primary-50 transition-colors">
              View All
            </Link>
            <Link href="/admin/doctors/create" class="px-5 py-2.5 bg-primary-800 text-white font-medium rounded-lg hover:bg-primary-900 transition-colors shadow-lg">
              + Add Doctor
            </Link>
          </div>
        </div>
      </div>

      <!-- Recent Doctors Table -->
      <!-- Recent Doctors Table -->
      <div v-if="recentDoctors.length > 0">
        <h3 class="text-lg font-bold font-heading text-slate-900 dark:text-white mb-4">Recent Doctors</h3>
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-start text-slate-500 dark:text-slate-400">
              <thead class="text-xs text-slate-700 dark:text-slate-300 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
                <tr>
                  <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Name</th>
                  <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Email</th>
                  <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Specialization</th>
                  <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Status</th>
                  <th scope="col" class="px-6 py-4 font-medium text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="doctor in recentDoctors" :key="doctor.id" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td class="px-6 py-4 font-medium text-slate-900 dark:text-white">{{ doctor.name }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">{{ doctor.email }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">{{ doctor.specialization || '-' }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span :class="[
                      'px-2 py-1 rounded-full text-xs font-medium',
                      doctor.status === 'active'
                        ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                        : 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400'
                    ]">
                      {{ doctor.status }}
                    </span>
                  </td>
                  <td class="px-6 py-4 text-end whitespace-nowrap">
                    <Link :href="`/admin/doctors/${doctor.id}/edit`" class="text-primary-600 dark:text-primary-400 hover:text-primary-900 dark:hover:text-primary-300 text-sm font-medium">
                      Edit
                    </Link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
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
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';
import DownloadAppForm from '@/Pages/Settings/Partials/DownloadAppForm.vue';

defineProps({
  stats: { type: Object, required: true },
  recentDoctors: { type: Array, default: () => [] },
});

const showDownload = ref(true);
</script>
