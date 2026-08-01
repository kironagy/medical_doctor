<template>
  <div class="space-y-6">
    <!-- Header & Sync Control Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
      <div class="flex items-center space-x-4 rtl:space-x-reverse">
        <div class="p-3 bg-primary-50 dark:bg-primary-950/50 text-primary-600 dark:text-primary-400 rounded-xl">
          <CloudArrowUpIcon class="w-8 h-8" />
        </div>
        <div>
          <h2 class="text-xl font-bold text-slate-900 dark:text-white">
            {{ $t('settings.sync_data') || 'Sync Data' }} / مزامنة البيانات
          </h2>
          <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
            {{ $t('settings.sync_subtitle') || 'Manual Offline-First Sync & Storage Center' }}
          </p>
        </div>
      </div>

      <!-- Main Action Controls -->
      <div class="flex items-center space-x-2 rtl:space-x-reverse">
        <button
          v-if="engineState === 'idle' || engineState === 'cancelled'"
          @click="startSync"
          class="inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 active:bg-primary-800 transition-all shadow-md shadow-primary-500/20 space-x-2 rtl:space-x-reverse"
        >
          <ArrowPathIcon class="w-4 h-4" />
          <span>{{ $t('sync.sync_now') || 'Sync Now' }}</span>
        </button>

        <button
          v-if="engineState === 'running'"
          @click="pauseSync"
          class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/40 hover:bg-amber-100 border border-amber-200 dark:border-amber-800 transition-all space-x-2 rtl:space-x-reverse"
        >
          <PauseIcon class="w-4 h-4" />
          <span>{{ $t('sync.pause') || 'Pause Sync' }}</span>
        </button>

        <button
          v-if="engineState === 'paused'"
          @click="resumeSync"
          class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/40 hover:bg-blue-100 border border-blue-200 dark:border-blue-800 transition-all space-x-2 rtl:space-x-reverse"
        >
          <PlayIcon class="w-4 h-4" />
          <span>{{ $t('sync.resume') || 'Resume Sync' }}</span>
        </button>

        <button
          v-if="engineState === 'running' || engineState === 'paused'"
          @click="cancelSync"
          class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100 border border-rose-200 dark:border-rose-800 transition-all space-x-2 rtl:space-x-reverse"
        >
          <XMarkIcon class="w-4 h-4" />
          <span>{{ $t('sync.cancel') || 'Cancel Sync' }}</span>
        </button>
      </div>
    </div>

    <!-- Live Status Overview Cards (iCloud / Drive style) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          <span>{{ $t('sync.status') || 'Sync Status' }}</span>
          <component :is="stateBadge.icon" :class="['w-4 h-4', stateBadge.color]" />
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white capitalize">
          {{ stats.engine_state || 'Idle' }}
        </div>
        <div class="text-[10px] text-slate-400 mt-1">
          Auth: <span class="text-emerald-500 font-semibold">{{ stats.auth_status || 'Authenticated' }}</span>
        </div>
      </div>

      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          {{ $t('sync.pending_queue') || 'Pending Queue' }}
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
          {{ stats.pending || 0 }} <span class="text-xs font-normal text-slate-400">items</span>
        </div>
        <div class="text-[10px] text-slate-400 mt-1">
          Total Queue: {{ stats.total_queue || 0 }}
        </div>
      </div>

      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          {{ $t('sync.sqlite_db_size') || 'SQLite DB Size' }}
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
          {{ stats.sqlite_size_mb || 0 }} <span class="text-xs font-normal text-slate-400">MB</span>
        </div>
        <div class="text-[10px] text-slate-400 mt-1">
          Cache: {{ stats.local_cache_size_mb || 0 }} MB
        </div>
      </div>

      <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
          {{ $t('sync.last_synced') || 'Last Synced' }}
        </div>
        <div class="text-xs font-bold text-slate-900 dark:text-white truncate">
          {{ formatDate(stats.last_successful_sync) }}
        </div>
        <div class="text-[10px] text-slate-400 mt-1 truncate">
          Failed: {{ stats.failed || 0 }} | Conflicts: {{ stats.conflict || 0 }}
        </div>
      </div>
    </div>

    <!-- Active Overall Progress Panel -->
    <div v-if="engineState === 'running' || progressPercent > 0" class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm space-y-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2 rtl:space-x-reverse">
          <ArrowPathIcon class="w-5 h-5 text-primary-600 dark:text-primary-400 animate-spin" />
          <span class="font-bold text-slate-900 dark:text-white text-sm">
            {{ $t('sync.syncing_data') || 'Syncing Data...' }}
          </span>
        </div>
        <span class="text-sm font-extrabold text-primary-600 dark:text-primary-400">
          {{ progressPercent }}%
        </span>
      </div>

      <!-- Main Progress Bar -->
      <div class="w-full h-3 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
        <div
          class="h-full bg-primary-600 transition-all duration-300 rounded-full"
          :style="{ width: `${progressPercent}%` }"
        ></div>
      </div>

      <!-- Realtime Transfer Metrics -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs text-slate-500 dark:text-slate-400 pt-2 border-t border-slate-100 dark:border-slate-700">
        <div>Processed: <strong class="text-slate-800 dark:text-slate-200">{{ processedItems }} / {{ totalItems }}</strong></div>
        <div>Speed: <strong class="text-slate-800 dark:text-slate-200">{{ transferSpeed }} MB/s</strong></div>
        <div>Est. Time: <strong class="text-slate-800 dark:text-slate-200">{{ estimatedSecondsRemaining }}s</strong></div>
        <div>Completed: <strong class="text-emerald-600 font-semibold">{{ stats.synced || 0 }}</strong></div>
      </div>
    </div>

    <!-- Entity Progress Breakdown Cards (Part 4) -->
    <div class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm space-y-4">
      <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider">
        {{ $t('sync.entity_breakdown') || 'Entity Queue Breakdown' }}
      </h3>

      <div class="space-y-3">
        <!-- Patients Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <UserGroupIcon class="w-5 h-5 text-blue-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                {{ $t('sync.patients') || 'Patients' }}
              </div>
              <div class="text-xs text-slate-400">
                Pending: {{ stats.pending_patients || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
            {{ stats.pending_patients === 0 ? 'Completed' : 'Waiting' }}
          </span>
        </div>

        <!-- Notes Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <DocumentTextIcon class="w-5 h-5 text-indigo-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                {{ $t('sync.notes') || 'Notes' }}
              </div>
              <div class="text-xs text-slate-400">
                Pending: {{ stats.pending_notes || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
            {{ stats.pending_notes === 0 ? 'Completed' : 'Waiting' }}
          </span>
        </div>

        <!-- Visits Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <CalendarDaysIcon class="w-5 h-5 text-purple-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                {{ $t('sync.visits') || 'Visits' }}
              </div>
              <div class="text-xs text-slate-400">
                Pending: {{ stats.pending_visits || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
            {{ stats.pending_visits === 0 ? 'Completed' : 'Waiting' }}
          </span>
        </div>

        <!-- Files Card -->
        <div class="p-3.5 bg-slate-50 dark:bg-slate-900/50 rounded-xl flex items-center justify-between">
          <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <PhotoIcon class="w-5 h-5 text-teal-500" />
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                {{ $t('sync.files') || 'Files & Media' }}
              </div>
              <div class="text-xs text-slate-400">
                Pending: {{ stats.pending_files || 0 }}
              </div>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
            {{ stats.pending_files === 0 ? 'Completed' : 'Waiting' }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import {
  CloudArrowUpIcon,
  ArrowPathIcon,
  PauseIcon,
  PlayIcon,
  XMarkIcon,
  CheckCircleIcon,
  ExclamationCircleIcon,
  ExclamationTriangleIcon,
  ClockIcon,
  UserGroupIcon,
  DocumentTextIcon,
  CalendarDaysIcon,
  PhotoIcon
} from '@heroicons/vue/24/outline'

const stats = ref({})
const engineState = ref('idle')
const processedItems = ref(0)
const totalItems = ref(0)
const transferSpeed = ref('1.2')
const estimatedSecondsRemaining = ref(0)
let pollTimer = null

const progressPercent = computed(() => {
  if (!totalItems.value || totalItems.value === 0) return 0
  return Math.min(100, Math.round((processedItems.value / totalItems.value) * 100))
})

const stateBadge = computed(() => {
  switch (engineState.value) {
    case 'running':
      return { icon: ArrowPathIcon, color: 'text-blue-500 animate-spin', text: 'Running' }
    case 'paused':
      return { icon: PauseIcon, color: 'text-amber-500', text: 'Paused' }
    case 'cancelled':
      return { icon: XMarkIcon, color: 'text-slate-400', text: 'Cancelled' }
    default:
      return { icon: CheckCircleIcon, color: 'text-emerald-500', text: 'Idle' }
  }
})

async function fetchDashboardStats() {
  try {
    const res = await fetch('/_native/api/sync/dashboard')
    const json = await res.json()
    if (json.success && json.stats) {
      stats.value = json.stats
      engineState.value = json.stats.engine_state || 'idle'
      totalItems.value = json.stats.total_queue || 0
      processedItems.value = json.stats.synced || 0
    }
  } catch (e) {
    // Ignore fetch errors
  }
}

async function startSync() {
  engineState.value = 'running'
  try {
    const res = await fetch('/_native/api/sync/manual', { method: 'POST' })
    await fetchDashboardStats()
  } catch (e) {
    engineState.value = 'idle'
  }
}

async function pauseSync() {
  await fetch('/_native/api/sync/pause', { method: 'POST' })
  await fetchDashboardStats()
}

async function resumeSync() {
  await fetch('/_native/api/sync/resume', { method: 'POST' })
  await fetchDashboardStats()
}

async function cancelSync() {
  await fetch('/_native/api/sync/cancel', { method: 'POST' })
  await fetchDashboardStats()
}

function formatDate(dateStr) {
  if (!dateStr) return 'Never'
  try {
    return new Date(dateStr).toLocaleString()
  } catch (e) {
    return dateStr
  }
}

onMounted(() => {
  fetchDashboardStats()
  pollTimer = setInterval(fetchDashboardStats, 3000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>
