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
import { useForm, usePage, Head, router } from '@inertiajs/vue3';
import BaseCard from '@/Components/BaseCard.vue';
import BaseInput from '@/Components/BaseInput.vue';
import BaseButton from '@/Components/BaseButton.vue';

const page = usePage()

// If user is already authenticated (session survived restart), redirect to home
const user = computed(() => page.props.auth?.user)
if (user.value && typeof window !== 'undefined') {
  // Using window.location.replace instead of router.visit ensures that if a user 
  // hits the back button to reach this page, we permanently replace the login page 
  // history entry with the home page, allowing them to continue going back.
  window.location.replace('/')
}

const form = useForm({
  email: '',
  password: '',
  remember: true,
});

const submit = async () => {
  form.clearErrors();
  form.processing = true;

  try {
    const response = await window.axios.post('/login', {
      email: form.email,
      password: form.password,
      remember: form.remember
    }, {
      headers: {
        'X-Inertia': 'false',
        'Accept': 'application/json'
      }
    });

    try { localStorage.setItem('np_persist_login', '1') } catch(e) {}

    // Force a full page reload to flush the Android WebView CookieManager
    setTimeout(() => {
        window.location.replace(response.data.redirect || '/workspace');
    }, 100);

  } catch (error) {
    form.processing = false;
    form.reset('password');

    if (error.response?.data?.errors) {
      // Apply the errors back to the Inertia form object
      for (const key in error.response.data.errors) {
        form.setError(key, error.response.data.errors[key][0]);
      }
    }
  }
};
</script>
