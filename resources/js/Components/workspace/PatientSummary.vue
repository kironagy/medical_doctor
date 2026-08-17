<template>
  <div class="card-surface p-4 md:p-6 border-2 !border-primary-500 dark:!border-primary-700">
    <div class="flex flex-col gap-4">
      
      <!-- Top Section: Patient Details -->
      <div class="flex flex-col items-start gap-3 w-full">
        <!-- Name & Code -->
        <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-4 w-full">
          <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-white">
            {{ $t('patient_summary.name') }}: {{ patient.name }}
          </h2>
          <p class="text-sm font-bold text-primary-600 dark:text-primary-400">
            # {{ $t('patient_summary.code') }}: {{ patient.code || patient.uuid?.slice(0, 6) }}
          </p>
        </div>

        <!-- Address & Phone -->
        <div class="flex flex-col md:flex-row items-start md:items-center gap-3 md:gap-6 text-sm text-slate-700 dark:text-slate-300 font-medium">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            <span>{{ $t('patient_summary.address') }}: {{ patient.address || '—' }}</span>
          </div>
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
            <span dir="ltr">{{ $t('patient_summary.phone') }}: {{ patient.phone || '—' }}</span>
          </div>
        </div>

        <!-- Offline package status badge — app only, see native-only actions below -->
        <div
          v-if="offlinePackage && detectNative()"
          class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold"
          :class="offlineBadgeClass"
        >
          <svg v-if="offlinePackage.status === 'downloading' || offlinePackage.status === 'verifying'" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke-width="4" />
            <path class="opacity-75" d="M4 12a8 8 0 018-8" />
          </svg>
          <svg v-else-if="offlinePackage.status === 'ready'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
          <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ offlineStatusLabel }}</span>
        </div>

        <!-- Shared badge: shown when this patient was shared with the current doctor -->
        <div
          v-if="isShared"
          class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold"
          :class="isReadOnly
            ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border border-amber-300 dark:border-amber-700'
            : 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border border-blue-300 dark:border-blue-700'"
        >
          <!-- Share icon -->
          <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          <span>
            {{ $t('patient_summary.shared_by') }}: {{ sharedByName || $t('patient_summary.other_doctor') }}
          </span>
          <!-- Read-only indicator -->
          <span
            v-if="isReadOnly"
            class="mx-1 px-1.5 py-0.5 rounded text-[10px] font-black bg-amber-200 dark:bg-amber-800 text-amber-800 dark:text-amber-200 uppercase tracking-wide"
          >
            {{ $t('patient_summary.read_only') }}
          </span>
        </div>
      </div>

      <!-- Bottom Section: Actions and Diagnosis -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mt-2">
        <!-- Action Buttons — hidden completely when read-only access -->
        <div v-if="!isReadOnly" class="flex flex-wrap items-center gap-2">
          <button v-if="!detectNative()" @click="$emit('download')" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 dark:hover:bg-indigo-900/20 border border-indigo-200 text-indigo-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-3 3m0 0l-3-3m3 3V4" /></svg>
            {{ $t('patient_summary.download') }}
          </button>
          <!-- Offline package actions — app only. Downloading a patient for
               offline use stores the bytes on the device, so on the website
               these buttons have nothing to act on. Mirrors the web-only
               guard on the plain Download button above. -->
          <template v-if="detectNative()">
            <button
              v-if="!offlinePackage || offlinePackage.status === 'failed'"
              @click="handleDownloadOffline"
              :disabled="!isOnline || isOfflineBusy"
              class="px-3 py-1.5 bg-teal-50 hover:bg-teal-100 dark:hover:bg-teal-900/20 border border-teal-200 text-teal-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
              {{ offlinePackage?.status === 'failed' ? $t('patient_summary.retry_offline') : $t('patient_summary.download_offline') }}
            </button>
            <button
              v-else-if="offlinePackage.status === 'ready'"
              @click="handleRefreshOffline"
              :disabled="!isOnline || isOfflineBusy"
              class="px-3 py-1.5 bg-teal-50 hover:bg-teal-100 dark:hover:bg-teal-900/20 border border-teal-200 text-teal-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
              {{ $t('patient_summary.refresh_offline') }}
            </button>
          </template>
          <button @click="$emit('share')" class="px-3 py-1.5 bg-primary-50 hover:bg-primary-100 dark:hover:bg-primary-900/20 border border-primary-200 text-primary-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
            {{ $t('patient_summary.share') }}
          </button>
          <button @click="$emit('edit')" class="btn-primary !px-3 !py-1.5 flex items-center gap-1.5 text-xs font-bold">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            {{ $t('patient_summary.edit') }}
          </button>
          <button @click="$emit('delete')" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 dark:hover:bg-rose-900/20 border border-rose-200 text-rose-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            {{ $t('patient_summary.delete') }}
          </button>
        </div>

        <!-- Diagnosis -->
        <div class="flex items-center gap-1.5 text-rose-600 font-bold text-sm">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
          <span>{{ $t('patient_summary.diagnosis') }}: {{ patient.diagnosis || '—' }}</span>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useOfflinePackages } from '@/Composables/useOfflinePackages'
import { useSyncEngine } from '@/Composables/useSyncEngine'
import { useToast } from '@/Composables/useToast'
import { useNativeBridge } from '@/Composables/useNativeBridge'

const props = defineProps({
  patient: Object,
  isPrimaryDoctor: Boolean,
})

defineEmits(['edit', 'delete', 'share'])

const { isShared, isReadOnly, sharedByName } = useWorkspace()
const { getPackage, isBusy, fetchPackages, downloadPackage, refreshPackage } = useOfflinePackages()
const { isOnline } = useSyncEngine()
const toast = useToast()
const { detectNative } = useNativeBridge()

onMounted(() => {
  if (detectNative()) fetchPackages()
})

const offlinePackage = computed(() => props.patient?.uuid ? getPackage(props.patient.uuid) : null)
const isOfflineBusy = computed(() => props.patient?.uuid ? isBusy(props.patient.uuid) : false)

const offlineStatusLabel = computed(() => {
  const map = {
    downloading: 'جاري تحميل النسخة Offline...',
    verifying: 'جاري التحقق...',
    ready: 'متاح Offline',
    failed: 'فشل التحميل Offline',
  }
  return map[offlinePackage.value?.status] || ''
})

const offlineBadgeClass = computed(() => {
  const status = offlinePackage.value?.status
  if (status === 'ready') return 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700'
  if (status === 'failed') return 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400 border border-rose-300 dark:border-rose-700'
  return 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border border-amber-300 dark:border-amber-700'
})

async function handleDownloadOffline() {
  if (!props.patient?.uuid) return
  try {
    await downloadPackage(props.patient.uuid)
    if (offlinePackage.value?.status === 'ready') {
      toast.success('تم حفظ المريض للعرض Offline')
    } else if (offlinePackage.value?.status === 'failed') {
      toast.error(offlinePackage.value?.error || 'تعذر حفظ بعض الملفات')
    }
  } catch (e) {
    toast.error(e?.message === 'offline' ? 'يلزم الاتصال بالإنترنت للتحميل' : 'تعذر التحميل')
  }
}

async function handleRefreshOffline() {
  if (!props.patient?.uuid) return
  try {
    await refreshPackage(props.patient.uuid)
    if (offlinePackage.value?.status === 'ready') {
      toast.success('تم تحديث النسخة المحفوظة')
    } else if (offlinePackage.value?.status === 'failed') {
      toast.error(offlinePackage.value?.error || 'تعذر تحديث بعض الملفات')
    }
  } catch (e) {
    toast.error(e?.message === 'offline' ? 'يلزم الاتصال بالإنترنت للتحديث' : 'تعذر التحديث')
  }
}
</script>
