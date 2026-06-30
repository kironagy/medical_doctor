<template>
  <div class="relative w-full max-w-md">
    <div class="relative">
      <div class="absolute inset-y-0 left-0 rtl:left-auto rtl:right-0 ps-3 flex items-center pointer-events-none">
        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
      </div>
      <input 
        type="text" 
        v-model="query"
        @input="handleInput"
        @focus="showResults = true"
        :placeholder="$t('nav.search_placeholder')" 
        class="w-full ps-10 pe-4 py-2 border border-slate-200 dark:border-slate-700 rounded-full focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-sm bg-slate-50/50 dark:bg-slate-900/50 hover:bg-slate-50 dark:hover:bg-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
      >
      <div v-if="loading" class="absolute inset-y-0 right-0 rtl:right-auto rtl:left-0 pe-3 flex items-center">
        <svg class="animate-spin h-4 w-4 text-primary-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
      </div>
    </div>

    <!-- Backdrop -->
    <div v-if="showResults && (results.length > 0 || query.length >= 2)" class="fixed inset-0 z-40" @click="showResults = false"></div>

    <!-- Results Dropdown -->
    <div v-if="showResults && (results.length > 0 || (query.length >= 2 && !loading))" class="absolute z-50 w-full mt-2 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-100 dark:border-slate-700 overflow-hidden">
      <div class="max-h-96 overflow-y-auto py-2">
        
        <div v-if="results.length === 0 && !loading" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400 text-sm">
          {{ $t('nav.no_results') }} "{{ query }}"
        </div>

        <Link 
          v-for="item in results" 
          :key="item.type + item.id"
          :href="item.url"
          class="flex items-center px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors border-b border-slate-50 dark:border-slate-700/50 last:border-0"
          @click="showResults = false"
        >
          <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 me-3" :class="item.type === 'Doctor' ? 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400' : 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400'">
            <svg v-if="item.icon === 'badge'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
          </div>
          <div>
            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ item.title }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ item.subtitle }}</p>
          </div>
          <div class="ms-auto text-xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded-full">
            {{ item.type }}
          </div>
        </Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const query = ref('');
const results = ref([]);
const loading = ref(false);
const showResults = ref(false);
let searchTimeout = null;

const handleInput = () => {
  showResults.value = true;
  
  if (searchTimeout) clearTimeout(searchTimeout);
  
  if (query.value.length < 2) {
    results.value = [];
    loading.value = false;
    return;
  }
  
  loading.value = true;
  searchTimeout = setTimeout(async () => {
    try {
      const res = await axios.get('/api/v1/search', { params: { q: query.value } });
      results.value = res.data;
    } catch (e) {
      console.error(e);
    } finally {
      loading.value = false;
    }
  }, 300);
};
</script>
