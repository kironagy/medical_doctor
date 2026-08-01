<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-[300] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="$emit('close')">
      <div class="w-full max-w-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-850">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-teal-500/10 dark:bg-teal-400/10 flex items-center justify-center text-teal-600 dark:text-teal-400">
              <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </div>
            <div>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white">مركز المزامنة</h3>
              <p class="text-xs text-slate-500 dark:text-slate-400">إدارة مزامنة البيانات دون اتصال (Offline-First Sync Center)</p>
            </div>
          </div>
          <button @click="$emit('close')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-lg transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <!-- Body -->
        <div class="p-6 overflow-y-auto space-y-6 flex-1">
          <!-- Status Banner -->
          <div class="flex items-center justify-between p-4 rounded-xl border" :class="isOnline ? 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-800/50 text-emerald-800 dark:text-emerald-300' : 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-800/50 text-rose-800 dark:text-rose-300'">
            <div class="flex items-center gap-3">
              <span class="w-3 h-3 rounded-full animate-pulse" :class="isOnline ? 'bg-emerald-500' : 'bg-rose-500'"></span>
              <span class="text-sm font-bold">{{ isOnline ? 'متصل بالإنترنت (Online)' : 'غير متصل بالإنترنت (Offline Mode)' }}</span>
            </div>
            <span class="text-xs font-mono font-bold px-2.5 py-1 rounded-lg bg-white/60 dark:bg-slate-800/60 border border-current">
              {{ isSyncing ? 'جاري المزامنة...' : 'جاهز للمزامنة' }}
            </span>
          </div>

          <!-- Queue Stats Grid -->
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div class="p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-800 text-center">
              <span class="block text-2xl font-extrabold text-slate-900 dark:text-white">{{ pendingSummary.total || 0 }}</span>
              <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">إجمالي العمليات المعلقة</span>
            </div>

            <div class="p-3.5 bg-amber-500/10 rounded-xl border border-amber-500/20 text-center">
              <span class="block text-2xl font-extrabold text-amber-600 dark:text-amber-400">{{ pendingSummary.patients || 0 }}</span>
              <span class="text-xs text-amber-700 dark:text-amber-400 font-medium">مرضى بانتظار الرفع</span>
            </div>

            <div class="p-3.5 bg-blue-500/10 rounded-xl border border-blue-500/20 text-center">
              <span class="block text-2xl font-extrabold text-blue-600 dark:text-blue-400">{{ pendingSummary.files || 0 }}</span>
              <span class="text-xs text-blue-700 dark:text-blue-400 font-medium">ملفات وفيديوهات</span>
            </div>

            <div class="p-3.5 bg-purple-500/10 rounded-xl border border-purple-500/20 text-center">
              <span class="block text-2xl font-extrabold text-purple-600 dark:text-purple-400">{{ pendingSummary.notes || 0 }}</span>
              <span class="text-xs text-purple-700 dark:text-purple-400 font-medium">ملاحظات طبية</span>
            </div>

            <div class="p-3.5 bg-rose-500/10 rounded-xl border border-rose-500/20 text-center">
              <span class="block text-2xl font-extrabold text-rose-600 dark:text-rose-400">{{ pendingSummary.deletes || 0 }}</span>
              <span class="text-xs text-rose-700 dark:text-rose-400 font-medium">عمليات حذف</span>
            </div>

            <div class="p-3.5 bg-emerald-500/10 rounded-xl border border-emerald-500/20 text-center">
              <span class="block text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">{{ lastSyncResult?.stats?.successful_items || 0 }}</span>
              <span class="text-xs text-emerald-700 dark:text-emerald-400 font-medium">تم رفعها بنجاح</span>
            </div>
          </div>

          <!-- Progress Bar during sync -->
          <div v-if="isSyncing" class="space-y-2">
            <div class="flex items-center justify-between text-xs font-bold text-slate-600 dark:text-slate-400">
              <span>جاري المزامنة عبر خط الأنابيب (Sync Pipeline)...</span>
              <span>100%</span>
            </div>
            <div class="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
              <div class="h-full bg-teal-500 rounded-full animate-pulse w-full"></div>
            </div>
          </div>

          <!-- Last Sync Time & Message -->
          <div v-if="lastSyncResult" class="p-4 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-200/80 dark:border-slate-700/80 text-xs space-y-1">
            <div class="flex items-center justify-between font-bold text-slate-700 dark:text-slate-300">
              <span>نتيجة المزامنة الأخيرة:</span>
              <span class="text-slate-400">{{ formattedLastSyncTime }}</span>
            </div>
            <p :class="lastSyncResult.success ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" class="font-medium">
              {{ lastSyncResult.message || (lastSyncResult.success ? 'تمت المزامنة بنجاح' : lastSyncResult.error) }}
            </p>
          </div>
        </div>

        <!-- Footer Actions -->
        <div class="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-850 flex items-center gap-3">
          <button
            @click="handleManualSync"
            :disabled="isSyncing"
            class="flex-1 py-3 px-4 bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white font-bold rounded-xl text-sm transition-all shadow-md flex items-center justify-center gap-2"
          >
            <svg v-if="!isSyncing" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <svg v-else class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ isSyncing ? 'جاري المزامنة...' : 'مزامنة الآن (Sync Now)' }}</span>
          </button>
          <button @click="$emit('close')" class="py-3 px-4 bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold rounded-xl text-sm hover:bg-slate-300 dark:hover:bg-slate-700 transition-colors">
            إغلاق
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'
import { useSyncEngine } from '@/Composables/useSyncEngine'
import { useToast } from '@/Composables/useToast'

defineProps({
  show: Boolean,
})

defineEmits(['close'])

const toast = useToast()
const { isOnline, isSyncing, lastSyncResult, pendingSummary, triggerSync } = useSyncEngine()

const formattedLastSyncTime = computed(() => {
  if (!lastSyncResult.value?.timestamp) return 'لم تتم المزامنة بعد'
  return new Date(lastSyncResult.value.timestamp).toLocaleTimeString()
})

async function handleManualSync() {
  const result = await triggerSync()
  if (result?.success) {
    toast.success('تمت المزامنة بنجاح')
  } else if (result?.error) {
    toast.error(result.error)
  }
}
</script>
