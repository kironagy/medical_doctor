<template>
  <div class="max-w-4xl mx-auto px-4 py-6">
    <div class="mb-6">
      <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">{{ $t('settings.title') }}</h1>
    </div>

    <div class="flex flex-col md:flex-row gap-6">
      <!-- Settings Sidebar / Navigation -->
      <div class="w-full md:w-64 shrink-0">
        <!-- Mobile Toggle Button -->
        <div class="md:hidden mb-2">
          <button
            @click="isMobileMenuOpen = !isMobileMenuOpen"
            class="w-full flex items-center justify-between p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
          >
            <div class="flex items-center">
              <component :is="activeTabObj?.icon" class="w-5 h-5 me-3 text-primary-600 dark:text-primary-400" />
              <span class="font-medium text-slate-800 dark:text-slate-200">{{ $t(activeTabObj?.name || 'Menu') }}</span>
            </div>
            <ChevronDownIcon
              class="w-5 h-5 text-slate-400 transition-transform duration-200"
              :class="{ 'rotate-180': isMobileMenuOpen }"
            />
          </button>
        </div>

        <nav
          class="flex-col space-y-1 bg-white dark:bg-transparent md:bg-transparent border border-slate-200 dark:border-slate-700 md:border-0 rounded-xl p-2 md:p-0"
          :class="isMobileMenuOpen ? 'flex' : 'hidden md:flex'"
        >
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="selectTab(tab.id)"
            class="flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-colors text-start"
            :class="[
              activeTab === tab.id
                ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300'
                : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800/50'
            ]"
          >
            <component :is="tab.icon" class="w-5 h-5 me-3" />
            {{ $t(tab.name) }}
          </button>
        </nav>
      </div>

      <!-- Settings Content -->
      <div class="flex-1">
        <ProfileForm v-if="activeTab === 'profile'" />
        <PasswordForm v-if="activeTab === 'password'" />
        <PreferencesForm v-if="activeTab === 'preferences'" />
        <CategoryForm v-if="activeTab === 'categories'" />
        <DownloadAppForm v-if="activeTab === 'download'" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import ProfileForm from '@/Pages/Settings/Partials/ProfileForm.vue'
import PasswordForm from '@/Pages/Settings/Partials/PasswordForm.vue'
import PreferencesForm from '@/Pages/Settings/Partials/PreferencesForm.vue'
import CategoryForm from '@/Pages/Settings/Partials/CategoryForm.vue'
import DownloadAppForm from '@/Pages/Settings/Partials/DownloadAppForm.vue'
import {
  UserIcon,
  KeyIcon,
  CogIcon,
  TagIcon,
  ArrowDownTrayIcon,
  ChevronDownIcon
} from '@heroicons/vue/24/outline'

const activeTab = ref('profile')
const isMobileMenuOpen = ref(false)

const tabs = [
  { id: 'profile', name: 'settings.profile', icon: UserIcon },
  { id: 'password', name: 'settings.password', icon: KeyIcon },
  { id: 'preferences', name: 'settings.preferences', icon: CogIcon },
  { id: 'categories', name: 'settings.categories', icon: TagIcon },
  { id: 'download', name: 'settings.download', icon: ArrowDownTrayIcon },
]

const activeTabObj = computed(() => tabs.find(t => t.id === activeTab.value))

function selectTab(id) {
  activeTab.value = id
  isMobileMenuOpen.value = false
}
</script>
