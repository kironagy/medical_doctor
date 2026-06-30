<template>
  <AppLayout :title="$t('doctors.add_new')">
    <div class="max-w-2xl mx-auto">
      <div class="mb-6">
        <Link href="/admin/doctors" class="text-sm font-medium text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors flex items-center gap-1">
          <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
          {{ $t('doctors.back_to_dir') }}
        </Link>
      </div>

      <BaseCard>
        <form @submit.prevent="submit" class="space-y-6">
          <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-700 pb-4 mb-4">
            {{ $t('doctors.information') }}
          </h2>

          <BaseInput
            v-model="form.name"
            :label="$t('doctors.full_name')"
            :placeholder="$t('doctors.placeholder_name')"
            required
            :error="form.errors.name"
          />

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <BaseInput
              v-model="form.email"
              :label="$t('patients.email')"
              type="email"
              required
              :placeholder="$t('doctors.placeholder_email')"
              :error="form.errors.email"
            />
            <BaseInput
              v-model="form.phone"
              :label="$t('patients.phone')"
              :placeholder="$t('patients.placeholder_phone')"
              :error="form.errors.phone"
            />
          </div>

          <BaseInput
            v-model="form.specialization"
            :label="$t('doctors.specialization')"
            :placeholder="$t('doctors.placeholder_spec')"
            :error="form.errors.specialization"
          />

          <BaseInput
            v-model="form.address"
            :label="$t('patients.address')"
            :placeholder="$t('patients.placeholder_address')"
            :error="form.errors.address"
          />

          <BaseInput
            v-model="form.password"
            :label="$t('doctors.initial_password')"
            type="password"
            required
            :error="form.errors.password"
          />

          <div class="pt-4 flex justify-end gap-3 rtl:space-x-reverse border-t border-slate-100 dark:border-slate-700 mt-6">
            <Link href="/admin/doctors" class="px-5 py-2.5 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors rounded-lg">
              {{ $t('common.cancel') }}
            </Link>
            <BaseButton type="submit" :loading="form.processing">
              {{ $t('doctors.save') }}
            </BaseButton>
          </div>
        </form>
      </BaseCard>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';
import BaseInput from '@/Components/BaseInput.vue';
import BaseButton from '@/Components/BaseButton.vue';

const form = useForm({
  name: '',
  email: '',
  phone: '',
  specialization: '',
  address: '',
  password: '',
});

const submit = () => {
  form.post('/admin/doctors');
};
</script>
