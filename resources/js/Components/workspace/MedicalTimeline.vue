<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
        Medical Timeline
      </h3>
      <button @click="collapsed = !collapsed" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 font-medium flex items-center gap-1">
        {{ collapsed ? 'Expand' : 'Collapse' }}
        <svg class="w-3.5 h-3.5 transition-transform" :class="collapsed ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
      </button>
    </div>

    <div v-if="hasTimelineItems">
      <div class="relative" :class="collapsed ? 'max-h-32 overflow-hidden' : ''">
        <div v-if="collapsed" class="absolute inset-0 bg-gradient-to-b from-transparent to-white dark:to-slate-950 z-10"></div>
        <div class="relative pl-8 border-l-2 border-slate-200 dark:border-slate-700 space-y-0">
          <div v-for="item in timelineItems" :key="item.id" class="relative pb-6 last:pb-0">
            <div class="absolute -left-[25px] w-5 h-5 rounded-full border-4 flex items-center justify-center"
              :class="item.type === 'visit' ? 'bg-primary-500 border-white dark:border-slate-950' : item.type === 'file' ? 'bg-emerald-500 border-white dark:border-slate-950' : 'bg-amber-500 border-white dark:border-slate-950'"
            >
              <svg v-if="item.type === 'visit'" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
              <svg v-else-if="item.type === 'file'" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
              <svg v-else class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5 hover:shadow-sm transition-shadow">
              <div class="flex items-start justify-between mb-1">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-white">{{ item.title }}</h4>
                <span class="text-[11px] text-slate-400 whitespace-nowrap ml-2">{{ item.date }}</span>
              </div>
              <p v-if="item.description" class="text-xs text-slate-600 dark:text-slate-400 line-clamp-2">{{ item.description }}</p>
              <div v-if="item.tags" class="flex flex-wrap gap-1.5 mt-2">
                <span v-for="tag in item.tags" :key="tag" class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                  :class="tag === 'diagnosis' ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400' :
                          tag === 'treatment' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' :
                          tag === 'operation' ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400' :
                          'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'"
                >{{ tag }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
      <svg class="mx-auto h-8 w-8 text-slate-300 dark:text-slate-600 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
      <p class="text-sm text-slate-500 dark:text-slate-400">No timeline entries yet</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { workspaceData } = useWorkspace()
const collapsed = ref(false)

const timelineItems = computed(() => {
  const items = []
  const data = workspaceData.value
  if (!data) return items

  if (data.files) {
    data.files.forEach(f => {
      items.push({
        id: `file-${f.id}`,
        type: 'file',
        title: f.title || f.file_name,
        description: f.desc || '',
        date: new Date(f.created_at).toLocaleDateString(),
        tags: f.category ? [f.category === 'medical_history' ? 'diagnosis' : f.category === 'pre_op' ? 'treatment' : f.category === 'operation_sheet' ? 'operation' : f.category] : [],
      })
    })
  }

  if (data.notes) {
    data.notes.forEach(n => {
      items.push({
        id: `note-${n.id}`,
        type: 'note',
        title: `Note by ${n.author?.name || 'Doctor'}`,
        description: n.content?.replace(/<[^>]*>/g, '').substring(0, 120),
        date: new Date(n.created_at).toLocaleDateString(),
        tags: n.category ? [n.category] : [],
      })
    })
  }

  if (data.visits) {
    data.visits.forEach(v => {
      items.push({
        id: `visit-${v.id}`,
        type: 'visit',
        title: v.visit_type || 'Patient Visit',
        description: v.reason || '',
        date: new Date(v.visit_date || v.created_at).toLocaleDateString(),
        tags: v.diagnosis ? ['diagnosis'] : [],
      })
    })
  }

  items.sort((a, b) => new Date(b.date) - new Date(a.date))
  return items
})

const hasTimelineItems = computed(() => timelineItems.value.length > 0)
</script>
