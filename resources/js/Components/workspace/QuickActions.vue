<template>
  <div class="flex flex-wrap gap-2">
    <button @click="$emit('share')" class="flex items-center gap-1.5 px-3.5 py-2 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded-xl text-xs font-medium transition-all active:scale-95 border border-emerald-200 dark:border-emerald-800">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
       {{ $t('quick_share') }}
     </button>
    <button @click="$emit('appointment')" class="flex items-center gap-1.5 px-3.5 py-2 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-xl text-xs font-medium transition-all active:scale-95 border border-blue-200 dark:border-blue-800">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
       {{ $t('quick_appointment') }}
     </button>
    <button @click="$emit('notes')" class="flex items-center gap-1.5 px-3.5 py-2 bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded-xl text-xs font-medium transition-all active:scale-95 border border-amber-200 dark:border-amber-800">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
       {{ $t('quick_notes') }}
     </button>
     <button @click="$emit('upload')" class="flex items-center gap-1.5 px-3.5 py-2 bg-purple-50 dark:bg-purple-900/20 hover:bg-purple-100 dark:hover:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-xl text-xs font-medium transition-all active:scale-95 border border-purple-200 dark:border-purple-800">
       <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
       {{ $t('quick_upload') }}
     </button>
     <button @click="$emit('history')" class="flex items-center gap-1.5 px-3.5 py-2 bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-medium transition-all active:scale-95 border border-slate-200 dark:border-slate-700">
       <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
       {{ $t('quick_history') }}
     </button>
     <button @click="$emit('categories')" class="flex items-center gap-1.5 px-3.5 py-2 bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 rounded-xl text-xs font-medium transition-all active:scale-95 border border-indigo-200 dark:border-indigo-800">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
       {{ $t('quick_categories') }}
     </button>
  </div>
</template>

<script setup>
defineEmits(['share', 'appointment', 'notes', 'upload', 'history', 'categories'])
</script>
