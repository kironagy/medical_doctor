<template>
  <div class="min-h-dvh bg-slate-50 dark:bg-slate-950 flex flex-col md:flex-row">
    
    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex flex-col w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 h-screen sticky top-0">
      <div class="p-6 flex items-center space-x-3 border-b border-slate-100 dark:border-slate-800">
        <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold">M</div>
        <span class="font-heading font-bold text-xl text-slate-800 dark:text-white">Medical Plus</span>
      </div>
      
      <!-- Main Menu -->
        <div class="px-3 py-2 space-y-1 mt-4">
          <Link href="/workspace" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/workspace') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
            {{ $t('nav.workspace') || 'Workspace' }}
          </Link>
          <Link href="/settings" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/settings') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
            <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            {{ $t('nav.settings') || 'Settings' }}
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

    <!-- Mobile Drawer (v-if to avoid DOM weight on desktop) -->
    <aside v-if="mobileMenuOpen" class="fixed inset-y-0 left-0 rtl:left-auto rtl:right-0 w-64 bg-white dark:bg-slate-900 shadow-xl z-[70] md:hidden flex flex-col">
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
        <Link href="/workspace" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/workspace') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
          <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
          {{ $t('nav.workspace') || 'Workspace' }}
        </Link>
        <Link href="/settings" @click="mobileMenuOpen = false" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors" :class="$page.url.startsWith('/settings') ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
          <svg class="w-5 h-5 me-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          {{ $t('nav.settings') || 'Settings' }}
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
    <main class="flex-1 flex flex-col min-w-0 pb-20 md:pb-0 relative">
      <!-- Desktop Header -->
      <header class="hidden md:flex items-center justify-between px-8 py-5 bg-white/95 dark:bg-slate-950/95 border-b border-slate-200 dark:border-slate-800 sticky top-0 z-10">
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

      <!-- Offline / Sync Indicators -->
      <div v-if="isOffline" class="bg-rose-500 text-white text-xs font-semibold py-1 px-4 flex items-center justify-center">
        <svg class="w-4 h-4 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243-2.829a4.978 4.978 0 011.415-2.829m-1.415 5.656a5 5 0 01-7.072 0m7.072 0l2.829 2.829M8.464 15.536A5 5 0 018.464 8.464m0 0L5.636 5.636M15.536 15.536L8.464 8.464" /></svg>
        {{ $t('offline_mode') || 'Offline Mode - Changes will be saved locally' }}
      </div>
      <div v-if="isSyncing" class="bg-blue-500 text-white text-xs font-semibold py-1 px-4 flex items-center justify-center">
        <svg class="animate-spin w-4 h-4 me-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        {{ $t('syncing') || 'Syncing changes...' }}
      </div>
      <div v-if="syncCompleted" class="bg-green-500 text-white text-xs font-semibold py-1 px-4 flex items-center justify-center transition-opacity">
        <svg class="w-4 h-4 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
        {{ $t('sync_completed') || 'Sync completed successfully' }}
      </div>
      <div v-if="syncError" class="bg-rose-600 text-white text-xs font-semibold py-1 px-4 flex items-center justify-center transition-opacity">
        <svg class="w-4 h-4 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        {{ $t('sync_failed') || 'Sync failed. Will retry later.' }}
      </div>

      <PullToRefresh
        class="flex-1 flex flex-col"
        :refresh="handleRefresh"
      >
        <div class="p-4 md:p-8">
          <slot />
        </div>
      </PullToRefresh>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 pb-safe z-50 will-change-transform shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.08)] dark:shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.3)]">
      <div class="flex justify-around items-center h-16">
        <Link href="/workspace" class="flex flex-col items-center justify-center w-full h-full" :class="$page.url.startsWith('/workspace') ? 'text-primary-600 dark:text-primary-400' : 'text-slate-500 dark:text-slate-400'">
          <svg class="w-6 h-6 mb-1 transition-transform" :class="$page.url.startsWith('/workspace') ? 'scale-110' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" :stroke-width="$page.url.startsWith('/workspace') ? '2.5' : '2'"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
          <span class="text-[10px] font-medium">{{ $t('nav.workspace') || 'Workspace' }}</span>
        </Link>
        <button @click="mobileMenuOpen = !mobileMenuOpen" class="flex flex-col items-center justify-center w-full h-full text-slate-500 dark:text-slate-400 focus:outline-none">
          <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
          <span class="text-[10px] font-medium">{{ $t('nav.menu') || 'Menu' }}</span>
        </button>
      </div>
    </nav>

  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import GlobalSearch from '@/Components/GlobalSearch.vue';
import PullToRefresh from '@/Components/PullToRefresh.vue';
import { useTheme } from '@/Composables/useTheme';
import { useLocale } from '@/Composables/useLocale';
import { useToast } from '@/Composables/useToast';
import { useWorkspace } from '@/Composables/useWorkspace';

const page = usePage();
const { theme } = useTheme();
const { locale } = useLocale();
const toast = useToast();

const mobileMenuOpen = ref(false);

// Offline Mode & Sync State
const isOffline = ref(!navigator.onLine);
const isSyncing = ref(false);
const syncCompleted = ref(false);
const syncError = ref(false);

const checkOnlineStatus = async () => {
    const wasOffline = isOffline.value;
    isOffline.value = !navigator.onLine;

    if (wasOffline && !isOffline.value) {
        // Back online! Trigger sync
        toast.success('Back online');
        isSyncing.value = true;
        try {
            const res = await fetch('/api/native/sync', { method: 'POST', headers: { 'Accept': 'application/json' }});
            if (res.status === 200) {
                syncCompleted.value = true;
                toast.success('Synchronization completed');
                setTimeout(() => { syncCompleted.value = false; }, 3000);
            } else {
                syncError.value = true;
                setTimeout(() => { syncError.value = false; }, 3000);
            }
            
            // Reactive updates without page reload
            const ws = useWorkspace();
            ws.refreshPatientList();
            ws.refreshWorkspaceData();
        } catch (e) {
            syncError.value = true;
            setTimeout(() => { syncError.value = false; }, 3000);
        } finally {
            isSyncing.value = false;
        }
    }
};

let refreshPromise = null

async function handleRefresh() {
  if (refreshPromise) return refreshPromise
  refreshPromise = router.reload({
    preserveState: true,
    preserveScroll: true,
    only: inferRefreshOnly(),
  }).then(() => {
    refreshPromise = null
  }).catch(() => {
    refreshPromise = null
  })
  return refreshPromise
}

function inferRefreshOnly() {
  const url = page.url
  if (url.startsWith('/admin/doctors')) return ['doctors']
  return undefined
}

const LIGHT_THEME_COLOR = '#0d9488';
const DARK_THEME_COLOR = '#030712';

function syncStatusBar(t) {
  const isDark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  const color = isDark ? DARK_THEME_COLOR : LIGHT_THEME_COLOR;
  let meta = document.querySelector('meta[name="theme-color"]');
  if (meta) {
    meta.content = color;
  }
  if (window.native?.theme) {
    try {
      window.native.theme.setStatusBarColor(color);
    } catch (e) {}
  }
}

onMounted(() => {
  syncStatusBar(theme.value);
  watch(theme, (val) => syncStatusBar(val), { immediate: false });
  
  window.addEventListener('online', checkOnlineStatus);
  window.addEventListener('offline', checkOnlineStatus);

  // Initial Pull Sync when online on startup
  if (navigator.onLine) {
      isSyncing.value = true;
      fetch('/api/native/sync', { method: 'POST', headers: { 'Accept': 'application/json' }})
        .then(() => {
            const ws = useWorkspace();
            ws.refreshPatientList();
            ws.refreshWorkspaceData();
        })
        .catch(() => {})
        .finally(() => {
            isSyncing.value = false;
        });
  }
});

onUnmounted(() => {
  window.removeEventListener('online', checkOnlineStatus);
  window.removeEventListener('offline', checkOnlineStatus);
});

defineProps({
  title: {
    type: String,
    default: 'Medical Plus'
  },
});
</script>
