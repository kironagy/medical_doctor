<template>
  <div>
    <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white mb-4 flex items-center gap-2">
      <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
      Medical History
    </h3>

    <div class="space-y-3">
      <div v-for="item in medicalHistoryItems" :key="item.id" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-4">
        <div class="flex items-start justify-between mb-2">
          <h4 class="text-sm font-semibold text-slate-900 dark:text-white">{{ item.title }}</h4>
          <span class="text-xs text-slate-400 whitespace-nowrap">{{ item.date }}</span>
        </div>
        <p v-if="item.details" class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">{{ item.details }}</p>
        <div v-if="item.attachments && item.attachments.length > 0" class="mt-3 flex flex-wrap gap-1.5">
          <span v-for="att in item.attachments" :key="att" class="text-[10px] px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-full flex items-center gap-1">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
            {{ att }}
          </span>
        </div>
      </div>
      <div v-if="medicalHistoryItems.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <p class="text-sm text-slate-500 dark:text-slate-400">No medical history recorded</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { workspaceData } = useWorkspace()

const medicalHistoryItems = computed(() => {
  const data = workspaceData.value
  if (!data) return []

  const items = []

  if (data.files) {
    data.files
      .filter(f => f.category === 'medical_history')
      .forEach(f => {
        items.push({
          id: `mh-${f.id}`,
          title: f.title || f.file_name,
          details: f.desc || '',
          date: new Date(f.created_at).toLocaleDateString(),
          attachments: f.file_name ? [f.file_name] : [],
        })
      })
  }

  if (data.visits) {
    data.visits.forEach(v => {
      items.push({
        id: `mh-visit-${v.id}`,
        title: v.visit_type || 'Medical Visit',
        details: v.reason || v.diagnosis || '',
        date: new Date(v.visit_date || v.created_at).toLocaleDateString(),
        attachments: [],
      })
    })
  }

  items.sort((a, b) => new Date(b.date) - new Date(a.date))
  return items
})
</script>
