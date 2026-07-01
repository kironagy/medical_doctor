<template>
  <div>
    <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white mb-3 flex items-center gap-2">
      <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
      Quick Dashboard
    </h3>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div v-for="widget in visibleWidgets" :key="widget.key"
        class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5 hover:shadow-sm transition-shadow"
      >
        <div class="flex items-center justify-between mb-2">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center" :class="widget.iconBg">
            <svg class="w-4 h-4" :class="widget.iconColor" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path :d="widget.iconPath" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>
          <span class="text-xs text-slate-400">{{ widget.label }}</span>
        </div>
        <p class="text-sm font-semibold text-slate-900 dark:text-white truncate">{{ widget.value }}</p>
        <p v-if="widget.subtitle" class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">{{ widget.subtitle }}</p>
      </div>

      <div v-if="visibleWidgets.length === 0" class="col-span-full text-center py-6 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <svg class="mx-auto h-6 w-6 text-slate-300 dark:text-slate-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" /></svg>
        <p class="text-xs text-slate-400">No data yet</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { workspaceData } = useWorkspace()

const widgets = computed(() => {
  const data = workspaceData.value
  if (!data) return []

  const items = [
    {
      key: 'files',
      label: 'Total Files',
      iconBg: 'bg-emerald-50 dark:bg-emerald-900/30',
      iconColor: 'text-emerald-600 dark:text-emerald-400',
      iconPath: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
      value: `${data.stats?.total_files || 0} files`,
      subtitle: 'Uploaded documents',
    },
    {
      key: 'notes',
      label: 'Notes',
      iconBg: 'bg-blue-50 dark:bg-blue-900/30',
      iconColor: 'text-blue-600 dark:text-blue-400',
      iconPath: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
      value: `${data.stats?.total_notes || 0} notes`,
      subtitle: 'Clinical notes',
    },
    {
      key: 'visits',
      label: 'Visits',
      iconBg: 'bg-purple-50 dark:bg-purple-900/30',
      iconColor: 'text-purple-600 dark:text-purple-400',
      iconPath: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
      value: `${data.stats?.total_visits || 0} visits`,
      subtitle: 'Patient visits',
    },
    {
      key: 'recent',
      label: 'Recent Uploads',
      iconBg: 'bg-amber-50 dark:bg-amber-900/30',
      iconColor: 'text-amber-600 dark:text-amber-400',
      iconPath: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12',
      value: `${data.stats?.recent_uploads?.length || 0} recent`,
      subtitle: data.stats?.recent_uploads?.[0]?.title || 'No recent uploads',
    },
  ]

  return items
})

const visibleWidgets = computed(() => widgets.value)
</script>
