<template>
  <AppLayout :title="$t('doctors.management')">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">{{ $t('nav.doctors') }}</h1>
      <Link href="/admin/doctors/create" class="btn-primary inline-flex items-center justify-center">
        <svg class="w-4 h-4 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        {{ $t('doctors.new') }}
      </Link>
    </div>

    <BaseCard :padding="false">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-start text-slate-500 dark:text-slate-400">
          <thead class="text-xs text-slate-700 dark:text-slate-300 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
            <tr>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors.name') }}</th>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors.code') }}</th>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors.status') }}</th>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-center">{{ $t('nav.patients') }}</th>
              <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors.last_login') }}</th>
              <th scope="col" class="px-6 py-4 font-medium text-end whitespace-nowrap">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="doctor in doctors.data" :key="doctor.id" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
              <td class="px-6 py-4 font-medium text-slate-900 dark:text-white flex items-center whitespace-nowrap">
                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 flex items-center justify-center font-bold me-3 flex-shrink-0">
                  {{ doctor.name.charAt(0) }}
                </div>
                <div>
                  <p>{{ doctor.name }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400 font-normal">{{ doctor.email }}</p>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-start text-slate-700 dark:text-slate-300">{{ doctor.code }}</td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span :class="[
                  'px-2 py-1 rounded-full text-xs font-medium',
                  doctor.status === 'active'
                    ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                    : 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400'
                ]">
                  {{ doctor.status === 'active' ? $t('doctors.status_active') : $t('doctors.status_suspended') }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-center">{{ doctor.patients_count }}</td>
              <td class="px-6 py-4 whitespace-nowrap text-slate-400 dark:text-slate-500 text-start">
                {{ doctor.last_login_at ? new Date(doctor.last_login_at).toLocaleDateString() : $t('doctors.never') }}
              </td>
              <td class="px-6 py-4 text-end whitespace-nowrap">
                <Link :href="`/admin/doctors/${doctor.id}/edit`" class="text-primary-600 dark:text-primary-400 hover:text-primary-900 dark:hover:text-primary-300 me-3 text-sm font-medium">{{ $t('common.edit') }}</Link>
                <button @click="suspendDoctor(doctor)" class="text-rose-600 dark:text-rose-400 hover:text-rose-900 dark:hover:text-rose-300 text-sm font-medium">
                  {{ doctor.status === 'active' ? $t('doctors.suspend') : $t('doctors.activate') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="p-4 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between">
        <span class="text-sm text-slate-500 dark:text-slate-400">{{ $t('doctors.showing', { from: doctors.from, to: doctors.to, total: doctors.total }) }}</span>
        <div class="flex gap-2">
          <Link v-if="doctors.prev_page_url" :href="doctors.prev_page_url" class="px-3 py-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-sm hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300">{{ $t('doctors.previous') }}</Link>
          <Link v-if="doctors.next_page_url" :href="doctors.next_page_url" class="px-3 py-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-sm hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300">{{ $t('doctors.next') }}</Link>
        </div>
      </div>
    </BaseCard>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';
import { useDialog } from '@/Composables/useDialog';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  doctors: Object,
  filters: Object,
});

const dialog = useDialog();

const suspendDoctor = async (doctor) => {
  const isActivating = doctor.status === 'suspended';
  const confirmed = await dialog.confirm({
    title: isActivating ? t('doctors.activate') : t('doctors.suspend'),
    message: `${isActivating ? t('doctors.confirm_activate') : t('doctors.confirm_suspend')} ${doctor.name}?`,
    confirmText: isActivating ? t('doctors.activate') : t('doctors.suspend'),
    style: isActivating ? 'success' : 'warning'
  });

  if (confirmed) {
    router.post(`/admin/doctors/${doctor.id}/suspend`);
  }
};
</script>
