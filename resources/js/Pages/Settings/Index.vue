<template>
  <AppLayout :title="$t('settings.title')">
    <div class="max-w-4xl mx-auto">
      <div class="mb-6">
        <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">{{ $t('settings.title') }}</h1>
      </div>

      <div class="flex flex-col md:flex-row gap-6">
        <!-- Sidebar Navigation -->
        <div class="w-full md:w-64 shrink-0">
          <nav class="flex flex-col space-y-1">
            <button 
              v-for="tab in tabs" 
              :key="tab.id"
              @click="activeTab = tab.id"
              class="flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-colors text-start"
              :class="[
                activeTab === tab.id 
                  ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300' 
                  : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'
              ]"
            >
              <component :is="tab.icon" class="w-5 h-5 me-3" />
              {{ $t(tab.name) }}
            </button>
          </nav>
        </div>

        <!-- Content Area -->
        <div class="flex-1">
          <ProfileForm v-if="activeTab === 'profile'" />
          <PasswordForm v-if="activeTab === 'password'" />
          <PreferencesForm v-if="activeTab === 'preferences'" />
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProfileForm from './Partials/ProfileForm.vue';
import PasswordForm from './Partials/PasswordForm.vue';
import PreferencesForm from './Partials/PreferencesForm.vue';
import { UserIcon, KeyIcon, CogIcon } from '@heroicons/vue/24/outline'; // Or define inline SVGs

const activeTab = ref('profile');

const tabs = [
  { id: 'profile', name: 'settings.profile', icon: UserIcon },
  { id: 'password', name: 'settings.password', icon: KeyIcon },
  { id: 'preferences', name: 'settings.preferences', icon: CogIcon },
];
</script>
