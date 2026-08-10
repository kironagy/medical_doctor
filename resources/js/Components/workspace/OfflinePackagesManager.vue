<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <p class="text-xs text-slate-500 dark:text-slate-400">
        المرضى المحفوظين لعرضهم بدون إنترنت
      </p>
      <button
        type="button"
        @click="reload"
        :disabled="loadingList"
        class="p-2 rounded-lg text-slate-400 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-900/20 transition-colors disabled:opacity-50"
      >
        <svg class="w-4 h-4" :class="{ 'animate-spin': loadingList }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </button>
    </div>

    <div v-if="!loadingList && packages.length === 0" class="text-center py-10 text-slate-400 text-sm">
      لا يوجد مرضى محفوظين Offline بعد
    </div>

    <div
      v-for="pkg in packages"
      :key="pkg.patient_uuid"
      @click="openPatient(pkg)"
      class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm cursor-pointer hover:border-teal-400 dark:hover:border-teal-600 transition-colors"
    >
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="font-bold text-slate-900 dark:text-white truncate">{{ pkg.patient_name || pkg.patient_uuid }}</p>
          <p class="text-xs text-slate-400 mt-0.5">
            آخر تحديث: {{ formatDate(pkg.last_refreshed_at) }}
          </p>
          <p v-if="pkg.status === 'failed' && pkg.error" class="text-xs text-rose-600 dark:text-rose-400 mt-1">
            {{ pkg.error }}
          </p>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
          <span
            class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide"
            :class="statusBadgeClass(pkg.status)"
          >
            {{ statusLabel(pkg.status) }}
          </span>

          <button
            type="button"
            @click.stop="doRefresh(pkg)"
            :disabled="!isOnline || isBusy(pkg.patient_uuid)"
            class="p-2 rounded-lg text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/20 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            title="تحديث"
          >
            <svg class="w-4 h-4" :class="{ 'animate-spin': isBusy(pkg.patient_uuid) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </button>

          <button
            type="button"
            @click.stop="doDelete(pkg)"
            :disabled="isBusy(pkg.patient_uuid)"
            class="p-2 rounded-lg text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            title="حذف"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useOfflinePackages } from '@/Composables/useOfflinePackages'
import { useSyncEngine } from '@/Composables/useSyncEngine'
import { useToast } from '@/Composables/useToast'
import { useWorkspace } from '@/Composables/useWorkspace'

const { packages, loadingList, fetchPackages, refreshPackage, deletePackage, isBusy } = useOfflinePackages()
const { isOnline } = useSyncEngine()
const { selectPatient } = useWorkspace()
const toast = useToast()

function openPatient(pkg) {
  selectPatient(pkg.patient_uuid)
}

onMounted(fetchPackages)

function reload() {
  fetchPackages()
}

function statusLabel(status) {
  return { downloading: 'جاري التحميل', verifying: 'جاري التحقق', ready: 'جاهز', failed: 'فشل' }[status] || status
}

function statusBadgeClass(status) {
  return {
    downloading: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
    verifying: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
    ready: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400',
    failed: 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400',
  }[status] || 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300'
}

function formatDate(value) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleString('ar-EG', { dateStyle: 'medium', timeStyle: 'short' })
  } catch (e) {
    return value
  }
}

async function doRefresh(pkg) {
  try {
    await refreshPackage(pkg.patient_uuid)
    toast.success('تم تحديث بيانات المريض')
  } catch (e) {
    toast.error('تعذر التحديث — تأكد من الاتصال بالإنترنت')
  }
}

async function doDelete(pkg) {
  if (!confirm('هل تريد حذف نسخة هذا المريض المحفوظة Offline؟')) return
  try {
    await deletePackage(pkg.patient_uuid)
    toast.success('تم حذف النسخة المحفوظة')
  } catch (e) {
    toast.error('تعذر الحذف')
  }
}
</script>
