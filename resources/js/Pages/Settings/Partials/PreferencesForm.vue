<template>
  <BaseCard>
    <form class="space-y-6">
      <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
        {{ $t('settings.preferences') }}
      </h2>

      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
            {{ $t('settings.language') }}
          </label>
          <div class="flex gap-4">
            <label class="flex items-center p-4 border rounded-xl cursor-pointer transition-all" :class="locale === 'en' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
              <input type="radio" v-model="locale" value="en" class="hidden" />
              <span class="font-medium dark:text-white">{{ $t('settings.lang_en') }}</span>
            </label>
            <label class="flex items-center p-4 border rounded-xl cursor-pointer transition-all" :class="locale === 'ar' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
              <input type="radio" v-model="locale" value="ar" class="hidden" />
              <span class="font-medium dark:text-white font-heading">{{ $t('settings.lang_ar') }}</span>
            </label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2 mt-6">
            {{ $t('settings.theme') }}
          </label>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <label class="flex flex-col p-4 border rounded-xl cursor-pointer transition-all" :class="theme === 'light' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
              <input type="radio" v-model="theme" value="light" class="hidden" />
              <div class="h-20 bg-slate-100 dark:bg-slate-700 rounded-lg mb-3 border border-slate-200 dark:border-slate-600 flex items-center justify-center">
                <SunIcon class="w-8 h-8 text-amber-500" />
              </div>
              <span class="font-medium text-center dark:text-white">{{ $t('settings.light_mode') }}</span>
            </label>

            <label class="flex flex-col p-4 border rounded-xl cursor-pointer transition-all" :class="theme === 'dark' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
              <input type="radio" v-model="theme" value="dark" class="hidden" />
              <div class="h-20 bg-slate-900 rounded-lg mb-3 border border-slate-700 flex items-center justify-center">
                <MoonIcon class="w-8 h-8 text-indigo-400" />
              </div>
              <span class="font-medium text-center dark:text-white">{{ $t('settings.dark_mode') }}</span>
            </label>

            <label class="flex flex-col p-4 border rounded-xl cursor-pointer transition-all" :class="theme === 'system' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
              <input type="radio" v-model="theme" value="system" class="hidden" />
              <div class="h-20 bg-gradient-to-r from-slate-100 dark:from-slate-700 to-slate-900 dark:to-slate-950 rounded-lg mb-3 border border-slate-300 dark:border-slate-600 flex items-center justify-center">
                <ComputerDesktopIcon class="w-8 h-8 text-slate-500" />
              </div>
              <span class="font-medium text-center dark:text-white">{{ $t('settings.system_mode') }}</span>
            </label>
          </div>
        </div>
      </div>
    </form>
  </BaseCard>
</template>

<script setup>
import BaseCard from '@/Components/BaseCard.vue';
import { useTheme } from '@/Composables/useTheme';
import { useLocale } from '@/Composables/useLocale';
import { SunIcon, MoonIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline';

const { theme } = useTheme();
const { locale } = useLocale();

// Note: Values auto-save via watchers inside the composables! No submit button needed.
</script>
