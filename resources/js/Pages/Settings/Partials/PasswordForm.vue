<template>
  <BaseCard>
    <form @submit.prevent="submit" class="space-y-6">
      <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
        {{ $t('settings.password') }}
      </h2>

      <BaseInput
        v-model="form.current_password"
        :label="$t('settings.current_password')"
        type="password"
        required
        :error="form.errors.current_password"
      />

      <BaseInput
        v-model="form.password"
        :label="$t('settings.new_password')"
        type="password"
        required
        :error="form.errors.password"
      />
      
      <!-- Password Strength Indicator -->
      <div v-if="form.password" class="mt-2 flex gap-1">
        <div class="h-1 w-full rounded-full" :class="strengthScore >= 1 ? strengthColor : 'bg-slate-200 dark:bg-slate-700'"></div>
        <div class="h-1 w-full rounded-full" :class="strengthScore >= 2 ? strengthColor : 'bg-slate-200 dark:bg-slate-700'"></div>
        <div class="h-1 w-full rounded-full" :class="strengthScore >= 3 ? strengthColor : 'bg-slate-200 dark:bg-slate-700'"></div>
        <div class="h-1 w-full rounded-full" :class="strengthScore >= 4 ? strengthColor : 'bg-slate-200 dark:bg-slate-700'"></div>
      </div>

      <BaseInput
        v-model="form.password_confirmation"
        :label="$t('settings.confirm_password')"
        type="password"
        required
        :error="form.errors.password_confirmation"
      />

      <div class="pt-4 flex justify-end">
        <BaseButton type="submit" :loading="form.processing">{{ $t('settings.update_password') }}</BaseButton>
      </div>
    </form>
  </BaseCard>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BaseCard from '@/Components/BaseCard.vue';
import BaseInput from '@/Components/BaseInput.vue';
import BaseButton from '@/Components/BaseButton.vue';

const form = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
});

const submit = () => {
  form.put('/settings/password', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });
};

const strengthScore = computed(() => {
  const p = form.password;
  let score = 0;
  if (!p) return score;
  if (p.length >= 8) score += 1;
  if (/[A-Z]/.test(p)) score += 1;
  if (/[0-9]/.test(p)) score += 1;
  if (/[^A-Za-z0-9]/.test(p)) score += 1;
  return score;
});

const strengthColor = computed(() => {
  if (strengthScore.value <= 1) return 'bg-rose-500';
  if (strengthScore.value === 2) return 'bg-amber-500';
  if (strengthScore.value === 3) return 'bg-blue-500';
  return 'bg-emerald-500';
});
</script>
