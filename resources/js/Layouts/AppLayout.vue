<template>
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col md:flex-row">
    
    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex flex-col w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 h-screen sticky top-0">
      <div class="p-6 flex items-center space-x-3 border-b border-slate-100 dark:border-slate-800">
        <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold">M</div>
        <span class="font-heading font-bold text-xl text-slate-800 dark:text-white">Medical Plus</span>
      </div>
      
      <!-- Main Menu -->
        <div class="px-3 py-2 space-y-1 mt-4">
          <Link href="/dashboard" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/dashboard') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
            {{ $t('nav.dashboard') }}
          </Link>
          <Link href="/patients" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url === '/patients' ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            {{ $t('nav.patients') }}
          </Link>
          <Link href="/patients/shared" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url === '/patients/shared' ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
            {{ $t('nav.patients') }} (Shared)
          </Link>
          <Link href="/settings" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/settings') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            {{ $t('nav.settings') }}
          </Link>
        </div>

        <!-- Admin Menu -->
        <div v-if="$page.props.auth?.user?.role === 'super-admin'" class="px-3 py-2 space-y-1 mt-8">
          <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">{{ $t('nav.administration') || 'Administration' }}</p>
          <Link href="/admin/doctors" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/admin/doctors') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
            {{ $t('nav.doctors_dir') || 'Doctors Directory' }}
          </Link>
        </div>
      
      <div class="p-4 border-t border-slate-200 dark:border-slate-800 mt-auto">
        <div class="flex items-center space-x-3 rtl:space-x-reverse mb-3">
          <div class="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-800 flex-shrink-0 flex items-center justify-center font-bold text-slate-500 dark:text-slate-400">
            {{ $page.props.auth?.user?.name?.charAt(0) || 'U' }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $page.props.auth?.user?.name || $t('nav.guest') || 'Guest' }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ $page.props.auth?.user?.email || '' }}</p>
          </div>
        </div>
        <Link href="/logout" method="post" as="button" class="w-full text-start text-sm text-rose-600 dark:text-rose-400 font-medium hover:text-rose-700 dark:hover:text-rose-300 p-2 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-lg transition-colors">
          {{ $t('nav.sign_out') || 'Sign out' }}
        </Link>
      </div>
    </aside>

    <!-- Mobile Drawer Overlay -->
    <div v-if="mobileMenuOpen" class="fixed inset-0 bg-slate-900/50 z-[60] md:hidden" @click="mobileMenuOpen = false"></div>

    <!-- Mobile Drawer -->
    <aside class="fixed inset-y-0 left-0 rtl:left-auto rtl:right-0 w-64 bg-white dark:bg-slate-900 shadow-xl z-[70] transform transition-transform duration-300 md:hidden flex flex-col" :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full'">
      <div class="p-6 flex items-center justify-between border-b border-slate-100 dark:border-slate-800">
        <div class="flex items-center space-x-3 rtl:space-x-reverse">
          <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold">M</div>
          <span class="font-heading font-bold text-xl text-slate-800 dark:text-white">Medical Plus</span>
        </div>
        <button @click="mobileMenuOpen = false" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 p-1">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>
      
      <!-- Mobile Main Menu -->
      <div class="px-3 py-2 space-y-1 mt-4 overflow-y-auto flex-1">
        <Link href="/dashboard" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/dashboard') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
          <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" /></svg>
          {{ $t('nav.dashboard') }}
        </Link>
        <Link href="/patients" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url === '/patients' ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
          <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
          {{ $t('nav.patients') }}
        </Link>
        <Link href="/patients/shared" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url === '/patients/shared' ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
          <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
          {{ $t('nav.patients') }} (Shared)
        </Link>
        <Link href="/settings" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/settings') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
          <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          {{ $t('nav.settings') }}
        </Link>
        
        <!-- Mobile Admin Menu -->
        <div v-if="$page.props.auth?.user?.role === 'super-admin'" class="mt-8">
          <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">{{ $t('nav.administration') || 'Administration' }}</p>
          <Link href="/admin/doctors" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/admin/doctors') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
            {{ $t('nav.doctors_dir') || 'Doctors Directory' }}
          </Link>
        </div>
      </div>
      
      <div class="p-4 border-t border-slate-200 dark:border-slate-800 mt-auto">
        <div class="flex items-center space-x-3 rtl:space-x-reverse mb-3">
          <div class="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-800 flex-shrink-0 flex items-center justify-center font-bold text-slate-500 dark:text-slate-400">
            {{ $page.props.auth?.user?.name?.charAt(0) || 'U' }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $page.props.auth?.user?.name || $t('nav.guest') || 'Guest' }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ $page.props.auth?.user?.email || '' }}</p>
          </div>
        </div>
        <Link href="/logout" method="post" as="button" class="w-full text-start text-sm text-rose-600 dark:text-rose-400 font-medium hover:text-rose-700 dark:hover:text-rose-300 p-2 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-lg transition-colors">
          {{ $t('nav.sign_out') || 'Sign out' }}
        </Link>
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col min-w-0 pb-20 md:pb-0">
      <!-- Desktop Header -->
      <header class="hidden md:flex items-center justify-between px-8 py-5 bg-white/80 dark:bg-slate-950/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 sticky top-0 z-10">
        <h1 class="text-2xl font-heading font-bold text-slate-800 dark:text-white">{{ title }}</h1>
        <div class="flex items-center space-x-4 flex-1 justify-end max-w-xl">
          <GlobalSearch />
        </div>
      </header>
      
      <!-- Mobile Header -->
      <header class="md:hidden flex items-center justify-between px-4 py-3 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 sticky top-0 z-10 shadow-sm">
        <h1 class="text-xl font-heading font-bold text-slate-800 dark:text-white truncate me-2">{{ title }}</h1>
        <GlobalSearch />
      </header>

      <div class="flex-1 p-4 md:p-8">
        <slot />
      </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 pb-safe z-50 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)] dark:shadow-none">
      <div class="flex justify-around items-center h-16">
        <Link href="/dashboard" class="flex flex-col items-center justify-center w-full h-full" :class="$page.url.startsWith('/dashboard') ? 'text-primary-600 dark:text-primary-400' : 'text-slate-500 dark:text-slate-400'">
          <svg class="w-6 h-6 mb-1 transition-transform" :class="$page.url.startsWith('/dashboard') ? 'scale-110' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" :stroke-width="$page.url.startsWith('/dashboard') ? '2.5' : '2'"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
          <span class="text-[10px] font-medium">{{ $t('nav.home') || 'Home' }}</span>
        </Link>
        <Link href="/patients" class="flex flex-col items-center justify-center w-full h-full" :class="$page.url === '/patients' ? 'text-primary-600 dark:text-primary-400' : 'text-slate-500 dark:text-slate-400'">
          <svg class="w-6 h-6 mb-1 transition-transform" :class="$page.url === '/patients' ? 'scale-110' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" :stroke-width="$page.url === '/patients' ? '2.5' : '2'"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
          <span class="text-[10px] font-medium">{{ $t('nav.patients') }}</span>
        </Link>
        <button @click="mobileMenuOpen = !mobileMenuOpen" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 focus:outline-none">
          <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
          <span class="text-[10px] font-medium">{{ $t('nav.menu') || 'Menu' }}</span>
        </button>
      </div>
    </nav>

    <!-- Global Dialog System -->
    <GlobalDialog />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import GlobalSearch from '@/Components/GlobalSearch.vue';
import GlobalDialog from '@/Components/GlobalDialog.vue';
import { useTheme } from '@/Composables/useTheme';
import { useLocale } from '@/Composables/useLocale';

// Initialize global preferences
useTheme();
useLocale();

const mobileMenuOpen = ref(false);

defineProps({
  title: {
    type: String,
    default: 'Medical Plus'
  },
});
</script>
