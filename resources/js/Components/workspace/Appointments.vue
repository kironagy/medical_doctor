<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
        Appointments
      </h3>
      <div class="flex gap-1">
        <button v-for="filter in filters" :key="filter.key" @click="activeFilter = filter.key"
          class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-colors"
          :class="activeFilter === filter.key
            ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400'
            : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700'"
        >{{ filter.label }}</button>
      </div>
    </div>

    <div class="space-y-2">
      <div v-for="apt in filteredAppointments" :key="apt.id" class="flex items-center gap-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5">
        <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
          :class="apt.status === 'completed' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600' :
                  apt.status === 'cancelled' ? 'bg-rose-100 dark:bg-rose-900/30 text-rose-600' :
                  'bg-primary-100 dark:bg-primary-900/30 text-primary-600'"
        >
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-slate-900 dark:text-white">{{ apt.visit_type || 'Appointment' }}</p>
          <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ apt.reason || '—' }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ new Date(apt.visit_date || apt.created_at).toLocaleDateString() }}</p>
          <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-full"
            :class="apt.status === 'completed' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700' :
                    apt.status === 'cancelled' ? 'bg-rose-100 dark:bg-rose-900/30 text-rose-700' :
                    'bg-primary-100 dark:bg-primary-900/30 text-primary-700'"
          >{{ apt.status || 'upcoming' }}</span>
        </div>
      </div>
      <div v-if="filteredAppointments.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <p class="text-sm text-slate-500 dark:text-slate-400">No appointments found</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { workspaceData } = useWorkspace()

const activeFilter = ref('all')

const filters = [
  { key: 'all', label: 'All' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
]

const allAppointments = computed(() => {
  const data = workspaceData.value
  if (!data?.visits) return []
  return data.visits.map(v => ({
    ...v,
    status: v.next_visit_date ? 'upcoming' : (v.deleted_at ? 'cancelled' : 'completed'),
  }))
})

const filteredAppointments = computed(() => {
  if (activeFilter.value === 'all') return allAppointments.value
  return allAppointments.value.filter(a => a.status === activeFilter.value)
})
</script>
