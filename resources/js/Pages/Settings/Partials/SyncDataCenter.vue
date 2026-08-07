<template>
  <div class="space-y-6">
    <!-- Header & Sync Control Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
      <div class="flex items-center space-x-4 rtl:space-x-reverse">
        <div class="p-3 bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400 rounded-xl">
          <CloudArrowUpIcon class="w-8 h-8" />
        </div>
        <div>
          <h2 class="text-xl font-bold text-slate-900 dark:text-white">
            مزامنة البيانات / Sync Data
          </h2>
          <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
            مركز المزامنة اليدوية والتخزين المحلي (Offline-First Sync Center)
          </p>
        </div>
      </div>

      <!-- Main Action Controls -->
      <div class="flex items-center space-x-2 rtl:space-x-reverse">
        <button
          v-if="!isSyncing"
          @click="startSync"
          class="inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-teal-600 hover:bg-teal-700 active:bg-teal-800 transition-all shadow-md space-x-2 rtl:space-x-reverse"
        >
          <ArrowPathIcon class="w-4 h-4" />
          <span>مزامنة الآن / Sync Now</span>
        </button>

        <button
          v-else
          disabled
          class="inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-bold text-slate-500 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 space-x-2 rtl:space-x-reverse opacity-80"
        >
          <ArrowPathIcon class="w-4 h-4 animate-spin text-teal-500" />
          <span>جاري المزامنة...</span>
        </button>
      </div>
    </div>

    <!-- Live Status Overview Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          <span>حالة الاتصال والشبكة</span>
          <span class="w-2.5 h-2.5 rounded-full" :class="isOnline ? 'bg-emerald-500' : 'bg-rose-500'"></span>
        </div>
        <div class="text-base font-bold" :class="isOnline ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
          {{ isOnline ? 'متصل بالإنترنت' : 'وضع بدون اتصال' }}
        </div>
        <div class="text-[10px] text-slate-400 mt-1">
          الجلسة: <span class="text-slate-600 dark:text-slate-300 font-semibold">{{ stats.auth_status || 'محلياً (Offline)' }}</span>
        </div>
      </div>

      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          العناصر المعلقة بالانتظار
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
          {{ pendingSummary.total || 0 }} <span class="text-xs font-normal text-slate-400">عنصر</span>
        </div>
        <div class="text-[10px] text-slate-400 mt-1">
          إجمالي طابور التزامن: {{ stats.total_queue || pendingSummary.total || 0 }}
        </div>
      </div>

      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          حجم قاعدة بيانات SQLite
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
          {{ stats.sqlite_size_mb || 0.5 }} <span class="text-xs font-normal text-slate-400">ميجابايت</span>
        </div>
        <div class="text-[10px] text-slate-400 mt-1">
          تخزين محلي مباشر
        </div>
      </div>

      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          آخر مزامنة مكتملة
        </div>
        <div class="text-xs font-bold text-slate-900 dark:text-white truncate">
          {{ formatDate(lastSyncResult?.timestamp || stats.last_successful_sync) }}
        </div>
        <div class="text-[10px] text-slate-400 mt-1 truncate">
          المكتمل: {{ stats.synced || 0 }} | الأخطاء: {{ stats.failed || 0 }}
        </div>
      </div>
    </div>

    <!-- Active Progress Bar during Sync -->
    <div v-if="isSyncing" class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-teal-500/30 shadow-sm space-y-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2 rtl:space-x-reverse">
          <ArrowPathIcon class="w-5 h-5 text-teal-600 dark:text-teal-400 animate-spin" />
          <span class="font-bold text-slate-900 dark:text-white text-sm">
            جاري نقل ومزامنة البيانات محلياً إلى السيرفر...
          </span>
        </div>
        <span class="text-sm font-extrabold text-teal-600 dark:text-teal-400">
          {{ isSyncing ? '100%' : '0%' }}
        </span>
      </div>

      <div class="w-full h-3 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
        <div class="h-full bg-teal-500 rounded-full animate-pulse w-full"></div>
      </div>
    </div>

    <!-- Realtime Entity Breakdown -->
    <div class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm space-y-4">
      <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider">
        تفاصيل عناصر طابور المزامنة المعلقة (Realtime Queue)
      </h3>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <!-- Patients Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <UserGroupIcon class="w-5 h-5 text-blue-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                المرضى (Patients)
              </div>
              <div class="text-xs text-slate-400">
                معلق بانتظار الرفع: {{ pendingSummary.patients || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full" :class="(pendingSummary.patients || 0) === 0 ? 'bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300'">
            {{ (pendingSummary.patients || 0) === 0 ? 'مكتمل Mapped' : 'معلق' }}
          </span>
        </div>

        <!-- Notes Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <DocumentTextIcon class="w-5 h-5 text-indigo-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                الملاحظات الطبية (Notes)
              </div>
              <div class="text-xs text-slate-400">
                معلق بانتظار الرفع: {{ pendingSummary.notes || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full" :class="(pendingSummary.notes || 0) === 0 ? 'bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300'">
            {{ (pendingSummary.notes || 0) === 0 ? 'مكتمل Mapped' : 'معلق' }}
          </span>
        </div>

        <!-- Visits Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <CalendarDaysIcon class="w-5 h-5 text-purple-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                الزيارات (Visits)
              </div>
              <div class="text-xs text-slate-400">
                معلق بانتظار الرفع: {{ stats.pending_visits || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full" :class="(stats.pending_visits || 0) === 0 ? 'bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300'">
            {{ (stats.pending_visits || 0) === 0 ? 'مكتمل Mapped' : 'معلق' }}
          </span>
        </div>

        <!-- Files Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <PhotoIcon class="w-5 h-5 text-teal-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                الملفات والفيديوهات (Files)
              </div>
              <div class="text-xs text-slate-400">
                معلق بانتظار الرفع: {{ pendingSummary.files || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full" :class="(pendingSummary.files || 0) === 0 ? 'bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300'">
            {{ (pendingSummary.files || 0) === 0 ? 'مكتمل Mapped' : 'معلق' }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import {
  CloudArrowUpIcon,
  ArrowPathIcon,
  UserGroupIcon,
  DocumentTextIcon,
  CalendarDaysIcon,
  PhotoIcon
} from '@heroicons/vue/24/outline'
import { useSyncEngine } from '@/Composables/useSyncEngine'

const { isOnline, isSyncing, lastSyncResult, pendingSummary, triggerSync, refreshPendingSummary } = useSyncEngine()

const stats = ref({})
let pollTimer = null

async function fetchDashboardStats() {
  try {
    const res = await fetch('/_native/api/sync/dashboard')
    const json = await res.json()
    if (json.success && json.stats) {
      stats.value = json.stats
    }
  } catch (e) {
    // Ignore fetch errors
  }
}

async function startSync() {
  await triggerSync()
  await fetchDashboardStats()
}

function formatDate(dateStr) {
  if (!dateStr) return 'لم تتم المزامنة بعد'
  try {
    return new Date(dateStr).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' })
  } catch (e) {
    return dateStr
  }
}

// ── FIX: this used to poll every 3s unconditionally, including while a
// sync was actively running. On this device's single-threaded PHP executor
// (one request processed at a time, ~500ms+ boot overhead each — see
// AppServiceProvider) that meant every 3s tick queued TWO more requests
// (dashboard + pending-summary) behind whatever the in-flight sync was
// still doing. Each queued request has its own client-side timeout
// (refreshPendingSummary: 5s), so they piled up, timed out, and were
// immediately replaced by the next tick's requests — the exact "endless
// canceled requests" loop reported while a sync was running for a single
// small file. Skip ticks while isSyncing is true, and poll far less often;
// startSync() already refreshes both right after a manual sync finishes,
// so this interval only needs to catch drift, not track sync progress.
onMounted(() => {
  refreshPendingSummary()
  fetchDashboardStats()
  pollTimer = setInterval(() => {
    if (isSyncing.value) return
    refreshPendingSummary()
    fetchDashboardStats()
  }, 15000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>
