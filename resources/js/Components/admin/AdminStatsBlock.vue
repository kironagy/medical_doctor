<template>
  <div class="border-2 border-primary-300 dark:border-primary-800 rounded-xl overflow-hidden bg-white dark:bg-slate-900" dir="rtl">
    <div class="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
      <button @click="toggleSection" class="flex items-center gap-2 flex-1 text-right text-primary-700 dark:text-primary-400">
        <h3 class="text-base font-bold">{{ title }}</h3>
      </button>
    </div>

    <Transition name="accordion">
      <div v-if="expanded" class="border-t border-slate-100 dark:border-slate-800">
        <div class="p-4">
          <!-- Stats Grid -->
          <div v-if="stats.length > 0" class="flex flex-wrap gap-2.5 md:gap-3.5">
            <div
              v-for="stat in stats"
              :key="stat.key"
              class="bg-[#e6fbf7] dark:bg-slate-900 border-2 border-[#ccfbf1] dark:border-teal-950/40 rounded-[20px] p-3 flex flex-col w-[calc(50%-5px)] sm:w-[170px] md:w-[180px] lg:w-[190px] xl:w-[200px] min-h-[160px] transition-all hover:shadow-md"
            >
              <!-- Preview Area (Icon) -->
              <div class="aspect-[4/3] w-full bg-white/50 dark:bg-slate-950 border border-teal-100 dark:border-slate-800 rounded-2xl flex items-center justify-center relative overflow-hidden mb-2.5">
                <div class="text-4xl">{{ stat.icon }}</div>
              </div>

              <!-- Label & Value -->
              <div class="flex flex-col items-center text-center mt-auto">
                <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ stat.value }}</span>
                <span class="text-xs font-medium text-slate-600 dark:text-slate-400 mt-1">{{ stat.label }}</span>
              </div>
            </div>
          </div>

          <!-- Empty State -->
          <div v-else class="text-center py-8 px-4">
            <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center">
              <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
            </div>
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">لا توجد إحصائيات</p>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  title: { type: String, default: 'إحصائيات' },
  stats: { type: Array, default: () => [] },
  defaultExpanded: { type: Boolean, default: true }
})

const expanded = ref(props.defaultExpanded)

function toggleSection() {
  expanded.value = !expanded.value
}
</script>
