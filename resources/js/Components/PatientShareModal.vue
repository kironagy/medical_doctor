<template>
  <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" @click="close"></div>
    
    <!-- Modal Content -->
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col max-h-[90vh] border dark:border-slate-800">
      <!-- Header -->
      <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-800/50">
        <h3 class="text-lg font-heading font-semibold text-slate-900 dark:text-white">{{ $t('patients.share_record') }}</h3>
        <button @click="close" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-1 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>

      <!-- Body -->
      <div class="p-6 overflow-y-auto flex-1">
        
        <!-- Search -->
        <div class="mb-6 relative">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.search_doctor') }}</label>
          <div class="relative">
            <input 
              type="text" 
              v-model="searchQuery"
              @input="debouncedSearch"
              :placeholder="$t('patients.search_doctor_placeholder')" 
              class="input-field ps-10 pe-4"
            >
            <svg class="w-5 h-5 text-slate-400 absolute left-3 rtl:left-auto rtl:right-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
          </div>
          
          <!-- Dropdown Results -->
          <div v-if="searchResults.length > 0 && searchQuery" class="absolute z-10 w-full mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg overflow-hidden max-h-48 overflow-y-auto">
            <button 
              v-for="doc in searchResults" :key="doc.id"
              @click="selectDoctor(doc)"
              class="w-full text-start px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors border-b dark:border-slate-700 last:border-0 flex items-center justify-between"
            >
              <div>
                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ doc.name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ doc.specialization || $t('common.general') }} &bull; {{ doc.code }}</p>
              </div>
              <div v-if="selectedDoctor?.id === doc.id" class="text-primary-600 dark:text-primary-400">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
              </div>
            </button>
          </div>
        </div>

        <!-- Selected Doctor Context -->
        <div v-if="selectedDoctor" class="bg-primary-50 dark:bg-primary-900/20 border border-primary-100 dark:border-primary-900/50 rounded-xl p-4 mb-6">
          <p class="text-xs text-primary-600 dark:text-primary-400 font-semibold uppercase tracking-wider mb-2">{{ $t('patients.selected_doctor') }}</p>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-slate-900 dark:text-white">{{ selectedDoctor.name }}</p>
              <p class="text-xs text-slate-500 dark:text-slate-400">{{ selectedDoctor.code }}</p>
            </div>
            <button @click="selectedDoctor = null" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium bg-white dark:bg-slate-800 px-2 py-1 rounded shadow-sm border border-primary-100 dark:border-primary-800 transition-colors">{{ $t('common.change') }}</button>
          </div>
        </div>

        <!-- Permissions -->
        <div class="mb-6" :class="{ 'opacity-50 pointer-events-none': !selectedDoctor }">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">{{ $t('patients.access_level') }}</label>
          <div class="grid grid-cols-2 gap-3">
            <label class="cursor-pointer border rounded-xl p-3 transition-colors flex items-start" :class="accessLevel === 'read' ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'">
              <input type="radio" v-model="accessLevel" value="read" class="sr-only">
              <div class="flex-shrink-0 mt-0.5">
                <div class="w-4 h-4 rounded-full border flex items-center justify-center" :class="accessLevel === 'read' ? 'border-primary-500' : 'border-slate-300 dark:border-slate-600'">
                  <div v-if="accessLevel === 'read'" class="w-2 h-2 rounded-full bg-primary-600 dark:bg-primary-500"></div>
                </div>
              </div>
              <div class="ms-3">
                <p class="text-sm font-medium" :class="accessLevel === 'read' ? 'text-primary-900 dark:text-primary-400' : 'text-slate-700 dark:text-slate-300'">{{ $t('patients.read_only') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $t('patients.read_only_desc') }}</p>
              </div>
            </label>

            <label class="cursor-pointer border rounded-xl p-3 transition-colors flex items-start" :class="accessLevel === 'read_write' ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-900/20' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'">
              <input type="radio" v-model="accessLevel" value="read_write" class="sr-only">
              <div class="flex-shrink-0 mt-0.5">
                <div class="w-4 h-4 rounded-full border flex items-center justify-center" :class="accessLevel === 'read_write' ? 'border-primary-500' : 'border-slate-300 dark:border-slate-600'">
                  <div v-if="accessLevel === 'read_write'" class="w-2 h-2 rounded-full bg-primary-600 dark:bg-primary-500"></div>
                </div>
              </div>
              <div class="ms-3">
                <p class="text-sm font-medium" :class="accessLevel === 'read_write' ? 'text-primary-900 dark:text-primary-400' : 'text-slate-700 dark:text-slate-300'">{{ $t('patients.read_write') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $t('patients.read_write_desc') }}</p>
              </div>
            </label>
          </div>
        </div>
        
        <!-- Active Shares List -->
        <div v-if="activeShares.length > 0" class="pt-6 border-t border-slate-100 dark:border-slate-800">
          <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3">{{ $t('patients.currently_shared') }}</h4>
          <div class="space-y-2">
            <div v-for="share in activeShares" :key="share.id" class="flex items-center justify-between p-2 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg transition-colors border border-transparent hover:border-slate-100 dark:hover:border-slate-700">
              <div class="flex items-center space-x-3 rtl:space-x-reverse">
                <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs shrink-0">
                  {{ share.doctor.name.charAt(0) }}
                </div>
                <div>
                  <p class="text-sm font-medium text-slate-900 dark:text-white">{{ share.doctor.name }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">{{ share.access_level === 'read_write' ? $t('patients.read_write') : $t('patients.read_only') }}</p>
                </div>
              </div>
              <button @click="revokeAccess(share)" class="text-xs text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-medium px-2 py-1 rounded hover:bg-rose-50 dark:hover:bg-rose-900/30 transition-colors">
                {{ $t('common.revoke') }}
              </button>
            </div>
          </div>
        </div>

      </div>

      <!-- Footer -->
      <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-3 bg-slate-50 dark:bg-slate-800/50">
        <button @click="close" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors">{{ $t('common.cancel') }}</button>
        <button 
          @click="sharePatient" 
          :disabled="!selectedDoctor || isSharing"
          class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
        >
          <svg v-if="isSharing" class="animate-spin -ms-1 me-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
          {{ $t('patients.share_access') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import axios from 'axios';
import { useDialog } from '@/Composables/useDialog';

const props = defineProps({
  show: Boolean,
  patientUuid: String,
});

const emit = defineEmits(['close']);

const searchQuery = ref('');
const searchResults = ref([]);
const selectedDoctor = ref(null);
const accessLevel = ref('read');
const activeShares = ref([]);
const isSharing = ref(false);
const dialog = useDialog();

let searchTimeout = null;

const debouncedSearch = () => {
  if (searchTimeout) clearTimeout(searchTimeout);
  
  if (searchQuery.value.length < 2) {
    searchResults.value = [];
    return;
  }
  
  searchTimeout = setTimeout(async () => {
    try {
      const res = await axios.get('/api/v1/doctors/search', { params: { q: searchQuery.value } });
      searchResults.value = res.data;
    } catch (e) {
      console.error(e);
    }
  }, 300);
};

const selectDoctor = (doc) => {
  selectedDoctor.value = doc;
  searchQuery.value = '';
  searchResults.value = [];
};

const loadShares = async () => {
  if (!props.patientUuid) return;
  try {
    const res = await axios.get(`/api/v1/patients/${props.patientUuid}/shares`);
    activeShares.value = res.data;
  } catch (e) {
    console.error(e);
  }
};

const sharePatient = async () => {
  if (!selectedDoctor.value) return;
  
  isSharing.value = true;
  try {
    await axios.post(`/api/v1/patients/${props.patientUuid}/shares`, {
      doctor_id: selectedDoctor.value.id,
      access_level: accessLevel.value
    });
    
    selectedDoctor.value = null;
    searchQuery.value = '';
    await loadShares();
  } catch (e) {
    console.error(e);
    dialog.alert({
      title: 'Sharing Failed',
      message: e.response?.data?.message || 'Failed to share this patient.',
      style: 'danger'
    });
  } finally {
    isSharing.value = false;
  }
};

const revokeAccess = async (share) => {
  const confirmed = await dialog.confirm({
    title: 'Revoke Access',
    message: `Are you sure you want to revoke access for Dr. ${share.doctor.name}?`,
    confirmText: 'Revoke Access',
    style: 'danger'
  });

  if (!confirmed) return;
  
  try {
    await axios.delete(`/api/v1/patients/${props.patientUuid}/shares/${share.id}`);
    activeShares.value = activeShares.value.filter(s => s.id !== share.id);
  } catch (e) {
    console.error(e);
    dialog.alert({
      title: 'Error',
      message: 'Failed to revoke access.',
      style: 'danger'
    });
  }
};

watch(() => props.show, (newVal) => {
  if (newVal) {
    loadShares();
    searchQuery.value = '';
    selectedDoctor.value = null;
  }
});

const close = () => {
  emit('close');
};
</script>
