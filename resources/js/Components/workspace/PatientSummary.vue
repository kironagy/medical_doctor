<template>
  <div class="card-surface p-4 md:p-6 border-2 !border-primary-500 dark:!border-primary-700" dir="rtl">
    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
      
      <!-- Right Side: Name and Code -->
      <div class="flex flex-col items-end w-full md:w-auto text-right">
        <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-white mb-2">
          الاسم: {{ patient.name }}
        </h2>
        <p class="text-sm font-bold text-primary-600 dark:text-primary-400">
          # الكود : {{ patient.code || patient.uuid?.slice(0, 6) }}
        </p>

        <!-- Shared badge: shown when this patient was shared with the current doctor -->
        <div
          v-if="isShared"
          class="mt-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold"
          :class="isReadOnly
            ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border border-amber-300 dark:border-amber-700'
            : 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border border-blue-300 dark:border-blue-700'"
        >
          <!-- Share icon -->
          <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          <span>
            مشارك من: {{ sharedByName || 'طبيب آخر' }}
          </span>
          <!-- Read-only indicator -->
          <span
            v-if="isReadOnly"
            class="mr-1 px-1.5 py-0.5 rounded text-[10px] font-black bg-amber-200 dark:bg-amber-800 text-amber-800 dark:text-amber-200 uppercase tracking-wide"
          >
            للعرض فقط
          </span>
        </div>
      </div>

      <!-- Middle: Address and Phone -->
      <div class="flex flex-col md:flex-row items-center gap-6 w-full md:w-auto justify-center md:justify-end text-sm text-slate-700 dark:text-slate-300 font-medium">
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          <span>العنوان: {{ patient.address || '—' }}</span>
        </div>
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
          <span dir="ltr">التليفون: {{ patient.phone || '—' }}</span>
        </div>
      </div>

      <!-- Left Side: Diagnosis and Actions -->
      <div class="flex flex-col items-start gap-2.5 w-full md:w-auto">
        <!-- Action Buttons — hidden completely when read-only access -->
        <div v-if="!isReadOnly" class="flex flex-wrap items-center gap-2 w-full justify-start md:justify-end">
          <button @click="$emit('download')" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 dark:hover:bg-indigo-900/20 border border-indigo-200 text-indigo-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-3 3m0 0l-3-3m3 3V4" /></svg>
            تحميل الملفات
          </button>
          <button @click="$emit('share')" class="px-3 py-1.5 bg-primary-50 hover:bg-primary-100 dark:hover:bg-primary-900/20 border border-primary-200 text-primary-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
            مشاركة
          </button>
          <button @click="$emit('edit')" class="btn-primary !px-3 !py-1.5 flex items-center gap-1.5 text-xs font-bold">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            تعديل
          </button>
          <button @click="$emit('delete')" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 dark:hover:bg-rose-900/20 border border-rose-200 text-rose-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            مسح المريض
          </button>
        </div>

        <!-- Diagnosis -->
        <div class="flex items-center gap-1.5 text-rose-600 font-bold text-sm">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
          <span>التشخيص: {{ patient.diagnosis || '—' }}</span>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { useWorkspace } from '@/Composables/useWorkspace'

const props = defineProps({
  patient: Object,
  isPrimaryDoctor: Boolean,
})

defineEmits(['edit', 'delete', 'share'])

const { isShared, isReadOnly, sharedByName } = useWorkspace()
</script>
