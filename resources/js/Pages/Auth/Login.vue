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
         {{ $t('auth.subtitle') || 'prof hosam fekry ortho team' }}
      </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <BaseCard>
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
import { computed } from 'vue'
import { useForm, usePage, router } from '@inertiajs/vue3';
import BaseCard from '@/Components/BaseCard.vue';
import BaseInput from '@/Components/BaseInput.vue';
import BaseButton from '@/Components/BaseButton.vue';

const page = usePage()

// If user is already authenticated or running in native app, navigate smoothly to workspace (SPA)
const user = computed(() => page.props.auth?.user)
if ((user.value || (typeof window !== 'undefined' && (window.AndroidBridge || window.NativePHP || window.location.hostname === '127.0.0.1'))) && typeof window !== 'undefined' && window.location.pathname === '/login') {
  router.visit('/workspace', { replace: true });
}

const form = useForm({
  email: 'doctor@medical.test',
  password: 'password',
  remember: true,
});

const submit = () => {
  form.post('/login', {
    replace: true,
    onSuccess: async (page) => {
      try {
        const token = page.props.session_remember_token;
        if (token) {
          localStorage.setItem('np_auth_token', token);
          localStorage.setItem('np_persist_login', '1');
        }

        const apiToken = page.props.api_token;
        if (apiToken) {
          localStorage.setItem('np_api_token', apiToken);
        }

        // ── Bootstrap: Cache all master data for offline operation ───────
        // This call primes the local SQLite with:
        //   - File categories (for upload forms)
        //   - User profile (for FK assignment)
        //   - Visit types (for visit creation forms)
        //
        // Without this, offline forms show empty dropdowns and fail with
        // NOT NULL constraint errors when creating records offline.
        //
        // We fire-and-forget: a failure is non-fatal (data will be fetched
        // on next online session or when categories are loaded normally).
        if (apiToken) {
          // 1. Prime the local SQLite cache (Only intercepted/applicable on Mobile App)
          try {
            await fetch('/_native/api/bootstrap/refresh', {
              method: 'POST',
              headers: {
                'Authorization': 'Bearer ' + apiToken,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
            });
            console.log('[Login] Local bootstrap cache primed successfully');
          } catch (bootstrapErr) {
            console.warn('[Login] Local bootstrap cache failed:', bootstrapErr.message);
          }

          // 2. Original endpoint (no-op on mobile MySQL but kept for compatibility)
          try {
            await fetch('/api/v1/mobile/bootstrap/refresh', {
              method: 'POST',
              headers: {
                'Authorization': 'Bearer ' + apiToken,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
            });
            console.log('[Login] Remote bootstrap cache primed successfully');
          } catch (bootstrapErr) {
            // Non-fatal: offline or server unreachable — will retry later
            console.warn('[Login] Remote bootstrap cache failed (non-fatal):', bootstrapErr.message);
          }
        }
      } catch(e) {}
    },
    onFinish: () => form.reset('password'),
  });
};
</script>
