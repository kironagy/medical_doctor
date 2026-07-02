<template>
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <div class="flex justify-center mb-6">
        <div class="w-12 h-12 bg-primary-600 rounded-xl flex items-center justify-center text-white font-bold text-xl shadow-lg">M</div>
      </div>
      <h2 class="text-center text-3xl font-heading font-bold text-slate-900 dark:text-white tracking-tight">
        {{ $t('auth.sign_in') || 'Sign in to your account' }}
      </h2>
      <p class="mt-2 text-center text-sm text-slate-600 dark:text-slate-400">
        {{ $t('auth.subtitle') || 'Medical Plus Professional' }}
      </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <BaseCard class="px-4 py-8 sm:px-10">
        <form @submit.prevent="submit" class="space-y-6">
          <BaseInput
            v-model="form.email"
            :label="$t('auth.email') || 'Email address'"
            type="email"
            required
            :error="form.errors.email"
          />

          <BaseInput
            v-model="form.password"
            :label="$t('auth.password') || 'Password'"
            type="password"
            required
            :error="form.errors.password"
          />

          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input
                id="remember_me"
                v-model="form.remember"
                type="checkbox"
                class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-slate-300 dark:border-slate-600 dark:bg-slate-800 rounded"
              />
              <label for="remember_me" class="ms-2 block text-sm text-slate-900 dark:text-slate-300">
                {{ $t('auth.remember') || 'Remember me' }}
              </label>
            </div>

            <div class="text-sm">
              <a href="#" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300 transition-colors">
                {{ $t('auth.forgot') || 'Forgot your password?' }}
              </a>
            </div>
          </div>

          <div>
            <BaseButton
              type="submit"
              class="w-full flex justify-center py-3"
              :loading="form.processing"
            >
              {{ $t('auth.sign_in_btn') || 'Sign in' }}
            </BaseButton>
          </div>
        </form>
      </BaseCard>
    </div>
  </div>
</template>

<script setup>
import { useForm, Head, router } from '@inertiajs/vue3';
import BaseCard from '@/Components/BaseCard.vue';
import BaseInput from '@/Components/BaseInput.vue';
import BaseButton from '@/Components/BaseButton.vue';
import { ref } from 'vue';

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

const submit = async () => {
  console.log('[MobileApp] Starting login flow...');
  form.processing = true;
  form.clearErrors();

  try {
    console.log('[MobileApp] Checking online status and attempting login...');

    // Attempt online login through the local Laravel backend
    try {
      await attemptOnlineLogin();
    } catch (error) {
      console.log('[MobileApp] Online login failed, attempting offline login...');
      await attemptOfflineLogin();
    }

  } catch (error) {
    console.error('[MobileApp] Login error:', error);
    form.errors.email = error.message || 'An unexpected error occurred.';
  } finally {
    form.processing = false;
    form.reset('password');
  }
};

async function attemptOnlineLogin() {
  console.log('[MobileApp] POST /api/native/login');

  const response = await fetch('/api/native/login', {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      email: form.email,
      password: form.password,
    }),
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Failed to authenticate.');
  }

  console.log('[MobileApp] Login successful, redirecting to workspace');
  window.location.href = '/workspace';
}

async function attemptOfflineLogin() {
  console.log('[MobileApp] POST /api/native/offline-login');

  const response = await fetch('/api/native/offline-login', {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email: form.email }),
  });

  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new Error(data.message || 'Internet connection is required for the first login.');
  }

  console.log('[MobileApp] Offline login accepted, redirecting to workspace');
  window.location.href = '/workspace';
}
</script>
