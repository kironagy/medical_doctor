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

const PRODUCTION_API_BASE = 'https://prof-hosam-fekry.online/api/mobile/v1';

const submit = async () => {
  console.log('[MobileApp] Starting login flow...');
  form.processing = true;
  form.clearErrors();

  try {
    console.log('[MobileApp] Step 1: Checking for stored auth data...');
    // Check if we have stored auth for offline login
    const checkStoredResponse = await fetch('/api/native/auth/check', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
    });
    const storedData = await checkStoredResponse.json();

    if (storedData.hasStoredAuth) {
      console.log('[MobileApp] Found stored auth data, checking online status...');
      // Try online login first, if fails use offline
      try {
        await attemptOnlineLogin();
      } catch (onlineError) {
        console.log('[MobileApp] Online login failed, falling back to offline login');
        await attemptOfflineLogin(storedData.storedUser);
      }
    } else {
      console.log('[MobileApp] No stored auth data, requiring online login');
      await attemptOnlineLogin();
    }

  } catch (error) {
    console.error('[MobileApp] Login error:', error);
    form.errors.email = error.message;
  } finally {
    form.processing = false;
    form.reset('password');
  }
};

async function attemptOnlineLogin() {
  console.log('[MobileApp] Attempting online login with production API...');

  // Step 1: Authenticate with production API
  console.log(`[MobileApp] POST ${PRODUCTION_API_BASE}/auth/login`);
  const prodResponse = await fetch(`${PRODUCTION_API_BASE}/auth/login`, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      email: form.email,
      password: form.password,
      device_name: 'nativephp-android',
    }),
  });

  console.log('[MobileApp] Production login HTTP status:', prodResponse.status);
  const prodData = await prodResponse.json();
  console.log('[MobileApp] Production login response:', prodData);

  if (!prodResponse.ok) {
    throw new Error(prodData.message || 'Failed to authenticate with server');
  }

  if (!prodData.token) {
    throw new Error('Server did not return an authentication token');
  }

  // Step 2: Store auth data locally and initialize sync
  console.log('[MobileApp] Storing auth data locally...');
  const localResponse = await fetch('/api/native/auth/store', {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      token: prodData.token,
      user: prodData.user,
      server_time: prodData.server_time,
    }),
  });

  console.log('[MobileApp] Local store HTTP status:', localResponse.status);
  const localData = await localResponse.json();
  console.log('[MobileApp] Local store response:', localData);

  if (!localResponse.ok) {
    throw new Error(localData.message || 'Failed to save authentication data');
  }

  // Step 3: Redirect to workspace
  console.log('[MobileApp] Login successful, redirecting to workspace');
  window.location.href = '/workspace';
}

async function attemptOfflineLogin(storedUser) {
  console.log('[MobileApp] Attempting offline login...');

  // Verify email matches stored user
  if (storedUser.email !== form.email) {
    throw new Error('Internet connection is required for the first login.');
  }

  // For offline, we'll just use stored auth without verifying password
  console.log('[MobileApp] Offline login accepted, redirecting to workspace');
  const offlineResponse = await fetch('/api/native/auth/offline', {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email: form.email }),
  });

  if (!offlineResponse.ok) {
    throw new Error('Failed to complete offline login');
  }

  window.location.href = '/workspace';
}
</script>
