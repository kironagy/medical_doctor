<template>
  <AppLayout :title="$t('patients.add_new')">
    <div class="max-w-2xl mx-auto">
      <div class="mb-6">
        <Link href="/patients" class="text-sm font-medium text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors flex items-center gap-1">
          <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
          {{ $t('patients.back_to_list') }}
        </Link>
      </div>

      <BaseCard>
        <form @submit.prevent="submit" class="space-y-6">
          <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-700 pb-4 mb-4">
            {{ $t('patients.information') }}
          </h2>

          <BaseInput
            v-model="form.name"
            :label="$t('patients.full_name')"
            :placeholder="$t('patients.placeholder_name')"
            required
            :error="form.errors.name"
          />

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <BaseInput
              v-model="form.phone"
              :label="$t('patients.phone')"
              :placeholder="$t('patients.placeholder_phone')"
              :error="form.errors.phone"
            />
            <BaseInput
              v-model="form.email"
              :label="$t('patients.email')"
              type="email"
              :placeholder="$t('patients.placeholder_email')"
              :error="form.errors.email"
            />
          </div>

          <BaseInput
            v-model="form.address"
            :label="$t('patients.address')"
            :placeholder="$t('patients.placeholder_address')"
            :error="form.errors.address"
          />

          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
              {{ $t('patients.diagnosis') }}
            </label>
            <textarea
              v-model="form.diagnosis"
              class="input-field"
              rows="4"
              :placeholder="$t('patients.placeholder_diagnosis')"
            ></textarea>
            <p v-if="form.errors.diagnosis" class="mt-1 text-sm text-rose-500">{{ form.errors.diagnosis }}</p>
          </div>

          <div class="pt-4 flex justify-end gap-3 rtl:space-x-reverse border-t border-slate-100 dark:border-slate-700 mt-6">
            <Link href="/patients" class="px-5 py-2.5 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors rounded-lg">
              {{ $t('common.cancel') }}
            </Link>
            <BaseButton type="submit" :loading="form.processing">
              {{ $t('patients.save_patient') }}
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
  phone: '',
  email: '',
  address: '',
  diagnosis: '',
});

const submit = () => {
  form.post('/patients');
};
</script>
