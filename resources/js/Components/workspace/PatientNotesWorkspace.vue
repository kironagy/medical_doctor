<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-bold font-heading text-slate-900 dark:text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
        Patient Notes
      </h3>
      <div class="flex items-center gap-2">
        <div class="relative">
          <svg class="absolute left-2.5 top-2 w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search notes..."
            class="w-40 pl-8 pr-2 py-1.5 text-xs bg-slate-100 dark:bg-slate-800 border-0 rounded-lg text-slate-900 dark:text-white placeholder:text-slate-400 focus:ring-2 focus:ring-primary-500"
          />
        </div>
      </div>
    </div>

    <div class="space-y-2">
      <div v-for="note in filteredNotesList" :key="note.id"
        class="group bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5 hover:shadow-sm transition-shadow"
      >
        <div class="flex items-start justify-between mb-2">
          <div class="flex items-center gap-2">
            <div class="w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 flex items-center justify-center text-[10px] font-bold">
              {{ note.author?.name?.charAt(0) || 'D' }}
            </div>
            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ note.author?.name || 'Doctor' }}</span>
            <span class="text-[10px] px-1.5 py-0.5 rounded-full font-medium"
              :class="getCategoryColor(note.category)"
            >{{ note.category }}</span>
          </div>
          <span class="text-[11px] text-slate-400 whitespace-nowrap">{{ new Date(note.created_at).toLocaleDateString() }}</span>
        </div>
        <div class="text-xs text-slate-600 dark:text-slate-400 prose prose-slate dark:prose-invert max-w-none line-clamp-3" v-html="note.content"></div>
      </div>

      <div v-if="filteredNotesList.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
        <svg class="mx-auto h-6 w-6 text-slate-300 dark:text-slate-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
        <p class="text-xs text-slate-400">No notes found</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'

const { workspaceData } = useWorkspace()
const searchQuery = ref('')

const allNotes = computed(() => workspaceData.value?.notes || [])

const filteredNotesList = computed(() => {
  if (!searchQuery.value) return allNotes.value
  const q = searchQuery.value.toLowerCase()
  return allNotes.value.filter(n =>
    (n.content && n.content.toLowerCase().includes(q)) ||
    (n.category && n.category.toLowerCase().includes(q)) ||
    (n.author?.name && n.author.name.toLowerCase().includes(q))
  )
})

function getCategoryColor(category) {
  const colors = {
    medical_history: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
    pre_op: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
    post_op: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400',
    operation_sheet: 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400',
    medications: 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400',
    notes: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
  }
  return colors[category] || colors.notes
}
</script>
