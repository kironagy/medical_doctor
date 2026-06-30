<template>
  <!-- Floating button (collapsed) -->
  <Transition name="um-fade">
    <button
      v-if="!open && hasActive"
      @click="open = true"
      class="fixed z-[80] bottom-4 right-4 rtl:right-auto rtl:left-4 md:bottom-6 md:right-6 rtl:md:left-6 rtl:md:right-auto flex items-center gap-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-lg rounded-full pe-5 ps-3 py-2.5 hover:shadow-xl transition-shadow"
    >
      <span class="relative flex items-center justify-center w-9 h-9 rounded-full bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
        <span v-if="activeJobs.length" class="absolute -top-1 -right-1 rtl:-right-auto rtl:-left-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center">{{ activeJobs.length }}</span>
      </span>
      <span class="text-sm font-semibold text-slate-700 dark:text-slate-200 tabular-nums">
        {{ $t('files.uploads_active', { n: activeJobs.length }) }}
      </span>
      <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ totalProgress.toFixed(0) }}%</span>
    </button>
  </Transition>

  <!-- Panel (expanded) -->
  <Transition name="um-slide">
    <div
      v-if="open"
      class="fixed z-[85] bottom-0 right-0 rtl:right-auto rtl:left-0 md:bottom-6 md:right-6 rtl:md:left-6 rtl:md:right-auto w-full md:w-96 max-w-md rounded-t-2xl md:rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl flex flex-col"
      style="max-height: min(70vh, 28rem);"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 dark:border-slate-800">
        <div class="flex items-center gap-2">
          <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
          <h3 class="text-sm font-semibold text-slate-800 dark:text-white">{{ $t('files.upload_manager_title') }}</h3>
        </div>
        <div class="flex items-center gap-1">
          <button
            v-if="jobs.some(j => ['completed','cancelled'].includes(j.status))"
            @click="clearCompleted"
            class="text-[11px] font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 px-2 py-1 rounded-md hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
          >{{ $t('files.clear_finished') }}</button>
          <button @click="open = false" class="p-1.5 rounded-md text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
      </div>

      <!-- Summary -->
      <div v-if="hasActive" class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
        <span>{{ $t('files.uploads_active', { n: activeJobs.length }) }}</span>
        <span class="tabular-nums">{{ totalProgress.toFixed(0) }}%</span>
        <div class="flex-1 ms-3 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
          <div class="h-full bg-primary-500 rounded-full transition-[width] duration-300 ease-out" :style="{ width: `${totalProgress}%` }"></div>
        </div>
      </div>

      <!-- List -->
      <div class="flex-1 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
        <div v-if="!jobs.length" class="py-10 text-center text-sm text-slate-400 dark:text-slate-500">{{ $t('files.no_uploads') }}</div>

        <div v-for="job in jobs" :key="job.id" class="px-4 py-3">
          <div class="flex items-center justify-between gap-2 mb-1.5">
            <div class="min-w-0 flex items-center gap-2">
              <span class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ job.name }}</span>
              <span class="shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider" :class="statusBadge(job.status)">{{ statusText(job.status) }}</span>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button
                v-if="job.status === 'failed'"
                @click="retry(job.id)"
                class="p-1.5 rounded-md text-slate-500 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition-colors"
                :title="$t('files.retry_upload')"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
              </button>
              <button
                v-if="job.cancellable && ['preparing','uploading','merging','processing','retrying'].includes(job.status)"
                @click="cancel(job.id)"
                class="p-1.5 rounded-md text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/30 transition-colors"
                :title="$t('files.cancel_upload')"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
              <button
                v-if="['completed','cancelled','failed'].includes(job.status)"
                @click="removeJob(job.id)"
                class="p-1.5 rounded-md text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                :title="$t('files.dismiss')"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" /></svg>
              </button>
            </div>
          </div>

          <!-- progress bar -->
          <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-1.5 overflow-hidden mb-1.5">
            <div class="h-full rounded-full transition-[width] duration-150 ease-out"
                 :class="progressBarClass(job.status)"
                 :style="{ width: `${job.progress}%` }"></div>
          </div>

          <!-- meta -->
          <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10.5px] text-slate-500 dark:text-slate-400 tabular-nums">
            <span>{{ fmtSize(job.uploadedBytes) }} / {{ fmtSize(job.totalSize) }}</span>
            <span v-if="job.remainingBytes > 0 && job.totalSize">{{ $t('files.remaining_size') }}: {{ fmtSize(job.remainingBytes) }}</span>
            <span v-if="job.speed > 0 && ['uploading','retrying'].includes(job.status)">{{ fmtSpeed(job.speed) }}</span>
            <span v-if="['uploading','retrying'].includes(job.status)">{{ job.etaText }}</span>
            <span v-if="job.retryChunk !== null" class="text-amber-600 dark:text-amber-400">#{{ job.retryChunk }}</span>
            <span v-if="job.status === 'processing'" class="text-amber-600 dark:text-amber-400">{{ $t('files.uploads_processing') }}…</span>
          </div>

          <!-- error -->
          <p v-if="job.status === 'failed' && job.errorMsg" class="mt-1.5 text-[11px] text-rose-600 dark:text-rose-400 line-clamp-2">{{ job.errorMsg }}</p>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useUploads } from '@/Composables/useUploads';

const { t } = useI18n();
const { jobs, activeJobs, hasActive, totalProgress, cancel, retry, removeJob, clearCompleted, fmtSize, fmtSpeed } = useUploads();

const open = ref(false);
// Auto-open the panel the moment an upload becomes active.
watch(hasActive, (v) => { if (v && !open.value) open.value = true; });

const statusText = (s) =>
  t('files.status_' + s) || s;
const statusBadge = (s) => ({
  preparing: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  uploading: 'bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300',
  merging: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
  processing: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
  completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
  cancelled: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
  failed: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
  retrying: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
}[s] || 'bg-slate-100 text-slate-600');
const progressBarClass = (s) => ({
  failed: 'bg-rose-500',
  cancelled: 'bg-slate-400',
  merging: 'bg-amber-500',
  processing: 'bg-indigo-500 indeterminate',
  completed: 'bg-emerald-500',
  retrying: 'bg-amber-500',
}[s] || 'bg-primary-500');
</script>

<style scoped>
.um-fade-enter-active, .um-fade-leave-active { transition: opacity .2s ease, transform .2s ease; }
.um-fade-enter-from, .um-fade-leave-to { opacity: 0; transform: translateY(10px); }
.um-slide-enter-active, .um-slide-leave-active { transition: opacity .25s ease, transform .25s ease; }
.um-slide-enter-from, .um-slide-leave-to { opacity: 0; transform: translateY(20px); }
@keyframes um-indeterminate {
  0% { width: 0%; margin-left: 0%; }
  50% { width: 40%; margin-left: 30%; }
  100% { width: 0%; margin-left: 100%; }
}
.indeterminate {
  position: relative;
  width: 40% !important;
  animation: um-indeterminate 1.4s ease-in-out infinite;
}
</style>