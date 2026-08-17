<template>
  <AppLayout :title="`Doctor: ${doctor.name}`">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 flex items-center justify-center text-xl font-bold font-heading shadow-sm">
          {{ doctor.name.charAt(0) }}
        </div>
        <div>
          <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">{{ doctor.name }}</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400">{{ doctor.specialization || $t('doctors.general') }} &bull; {{ doctor.code }}</p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span
          class="px-3 py-1 text-xs font-semibold rounded-full uppercase tracking-wider"
          :class="doctor.status === 'active' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-400' : 'bg-rose-100 dark:bg-rose-900/30 text-rose-800 dark:text-rose-400'"
        >
          {{ doctor.status === 'active' ? $t('doctors.status_active') : $t('doctors.status_suspended') }}
        </span>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      <BaseCard class="dark:bg-slate-800 dark:border-slate-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $t('dashboard.total_patients') }}</p>
            <p class="text-2xl font-bold font-heading text-slate-900 dark:text-white mt-1">{{ stats.total_patients }}</p>
          </div>
          <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg">
            <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
          </div>
        </div>
      </BaseCard>

      <BaseCard class="dark:bg-slate-800 dark:border-slate-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $t('doctors_show.files_uploaded') }}</p>
            <p class="text-2xl font-bold font-heading text-slate-900 dark:text-white mt-1">{{ stats.total_files }}</p>
          </div>
          <div class="p-3 bg-emerald-50 dark:bg-emerald-900/30 rounded-lg">
            <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
          </div>
        </div>
      </BaseCard>

      <BaseCard class="dark:bg-slate-800 dark:border-slate-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $t('doctors_show.storage') }}</p>
            <p class="text-2xl font-bold font-heading text-slate-900 dark:text-white mt-1">{{ formatBytes(stats.storage_bytes) }}</p>
          </div>
          <div class="p-3 bg-sky-50 dark:bg-sky-900/30 rounded-lg">
            <svg class="w-6 h-6 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" /></svg>
          </div>
        </div>
      </BaseCard>
    </div>

    <!-- Tabs Navigation -->
    <div class="border-b border-slate-200 dark:border-slate-700 mb-6 overflow-x-auto hide-scrollbar">
      <nav class="-mb-px flex gap-0 min-w-max">
        <button
          @click="activeTab = 'patients'"
          :class="[activeTab === 'patients' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600', 'whitespace-nowrap pb-4 px-4 border-b-2 font-medium text-sm transition-colors']"
        >
          {{ $t('nav.patients') }} ({{ stats.total_patients }})
        </button>
        <button
          @click="activeTab = 'files'"
          :class="[activeTab === 'files' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600', 'whitespace-nowrap pb-4 px-4 border-b-2 font-medium text-sm transition-colors']"
        >
          {{ $t('doctors_show.uploaded_files') }} ({{ stats.total_files }})
        </button>
      </nav>
    </div>

    <!-- Patients Tab -->
    <div v-show="activeTab === 'patients'">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-heading font-semibold text-slate-900 dark:text-white">{{ $t('doctors_show.doctor_patients') }}</h3>
        <div class="relative w-64">
          <input type="text" v-model="patientsSearch" @input="handlePatientSearch" :placeholder="$t('patients.search_placeholder')" class="w-full ps-10 pe-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white rounded-lg focus:ring-primary-500 focus:border-primary-500 text-sm">
          <svg class="w-5 h-5 text-slate-400 absolute start-3 top-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </div>
      </div>

      <BaseCard :padding="false" class="relative min-h-[300px]">
        <div v-if="loadingPatients" class="absolute inset-0 bg-white/60 dark:bg-slate-900/60 backdrop-blur-sm z-10 flex items-center justify-center">
          <div class="w-8 h-8 border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-start text-slate-500 dark:text-slate-400">
            <thead class="text-xs text-slate-700 dark:text-slate-300 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
              <tr>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.name') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.id') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.diagnosis') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('show.files') }}</th>
                <th scope="col" class="px-6 py-4 font-medium text-end whitespace-nowrap">{{ $t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="patient in patients" :key="patient.id" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                <td class="px-6 py-4 font-medium text-slate-900 dark:text-white flex items-center whitespace-nowrap">
                  <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold me-3 flex-shrink-0">
                    {{ patient.name.charAt(0) }}
                  </div>
                  {{ patient.name }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-start text-slate-700 dark:text-slate-300">{{ patient.code }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-slate-500 dark:text-slate-400 truncate max-w-xs text-start">{{ patient.diagnosis || $t('patients.not_specified') }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-start text-slate-700 dark:text-slate-300">{{ patient.files_count || 0 }}</td>
                <td class="px-6 py-4 text-end whitespace-nowrap">
                  <Link :href="`/patients/${patient.uuid}`" class="text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium text-sm">{{ $t('patients.view_profile') }}</Link>
                </td>
              </tr>
              <tr v-if="patients.length === 0 && !loadingPatients">
                <td colspan="5" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                  <p v-if="patientsSearch">{{ $t('doctors_show.no_match_patients') }}</p>
                  <p v-else>{{ $t('doctors_show.no_patients_yet') }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>
    </div>

    <!-- Files Tab -->
    <div v-show="activeTab === 'files'">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-heading font-semibold text-slate-900 dark:text-white">{{ $t('doctors_show.all_files') }}</h3>
        <div class="relative w-64">
          <input type="text" v-model="filesSearch" @input="handleFileSearch" :placeholder="$t('doctors_show.search_files')" class="w-full ps-10 pe-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white rounded-lg focus:ring-primary-500 focus:border-primary-500 text-sm">
          <svg class="w-5 h-5 text-slate-400 absolute start-3 top-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </div>
      </div>

      <BaseCard :padding="false" class="relative min-h-[300px]">
        <div v-if="loadingFiles" class="absolute inset-0 bg-white/60 dark:bg-slate-900/60 backdrop-blur-sm z-10 flex items-center justify-center">
          <div class="w-8 h-8 border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-start text-slate-500 dark:text-slate-400">
            <thead class="text-xs text-slate-700 dark:text-slate-300 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
              <tr>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors_show.file_name') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('patients.name') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors_show.size') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors_show.type') }}</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap text-start">{{ $t('doctors_show.uploaded') }}</th>
                <th scope="col" class="px-6 py-4 font-medium text-end whitespace-nowrap">{{ $t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="file in files" :key="file.id" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                <td class="px-6 py-4 font-medium text-slate-900 dark:text-white whitespace-nowrap max-w-[200px] truncate text-start" :title="file.file_name">
                  {{ file.file_name }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-indigo-600 dark:text-indigo-400 font-medium text-start">
                  <Link :href="`/patients/${file.patient.uuid}`">{{ file.patient.name }}</Link>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-start text-slate-700 dark:text-slate-300">{{ formatBytes(file.file_size) }}</td>
                <td class="px-6 py-4 whitespace-nowrap uppercase text-xs font-semibold text-start text-slate-700 dark:text-slate-300">{{ file.mime_type.split('/')[1] || file.mime_type }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-start text-slate-500 dark:text-slate-400">{{ new Date(file.created_at).toLocaleDateString() }}</td>
                <td class="px-6 py-4 text-end whitespace-nowrap">
                  <button @click="activeMedia = file" class="text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium text-sm me-3">{{ $t('common.preview') }}</button>
                  <!-- ?download=1 + download attribute: without them this
                       endpoint serves media inline and the link only opened
                       the image in a new tab instead of saving it. -->
                  <a :href="`/api/v1/files/${file.uuid}?download=1`" download class="text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 text-sm">{{ $t('common.download') }}</a>
                </td>
              </tr>
              <tr v-if="files.length === 0 && !loadingFiles">
                <td colspan="6" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                  <p v-if="filesSearch">{{ $t('doctors_show.no_match_files') }}</p>
                  <p v-else>{{ $t('doctors_show.no_files_yet') }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>
    </div>
    
    <UnifiedMediaViewer 
      :show="!!activeMedia" 
      :file="activeMedia" 
      @close="activeMedia = null" 
    />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';
import BaseButton from '@/Components/BaseButton.vue';
import UnifiedMediaViewer from '@/Components/UnifiedMediaViewer.vue';

const props = defineProps({
  doctor: Object,
  stats: Object,
});

const activeTab = ref('patients');
const activeMedia = ref(null);

// Patients State
const patients = ref([]);
const patientsPage = ref(1);
const patientsTotal = ref(0);
const patientsSearch = ref('');
const loadingPatients = ref(false);

// Files State
const files = ref([]);
const filesPage = ref(1);
const filesTotal = ref(0);
const filesSearch = ref('');
const loadingFiles = ref(false);

const loadPatients = async () => {
  loadingPatients.value = true;
  try {
    const res = await axios.get(`/admin/doctors/${props.doctor.id}/patients`, {
      params: { page: patientsPage.value, search: patientsSearch.value }
    });
    patients.value = res.data.data;
    patientsTotal.value = res.data.total;
  } catch (e) {
    console.error(e);
  } finally {
    loadingPatients.value = false;
  }
};

const loadFiles = async () => {
  loadingFiles.value = true;
  try {
    const res = await axios.get(`/admin/doctors/${props.doctor.id}/files`, {
      params: { page: filesPage.value, search: filesSearch.value }
    });
    files.value = res.data.data;
    filesTotal.value = res.data.total;
  } catch (e) {
    console.error(e);
  } finally {
    loadingFiles.value = false;
  }
};

onMounted(() => {
  loadPatients();
  loadFiles();
});

watch(activeTab, (val) => {
  if (val === 'patients' && patients.value.length === 0) loadPatients();
  if (val === 'files' && files.value.length === 0) loadFiles();
});

let patientSearchTimeout;
const handlePatientSearch = () => {
  clearTimeout(patientSearchTimeout);
  patientSearchTimeout = setTimeout(() => {
    patientsPage.value = 1;
    loadPatients();
  }, 300);
};

let fileSearchTimeout;
const handleFileSearch = () => {
  clearTimeout(fileSearchTimeout);
  fileSearchTimeout = setTimeout(() => {
    filesPage.value = 1;
    loadFiles();
  }, 300);
};

const formatBytes = (bytes, decimals = 2) => {
  if (!+bytes) return '0 Bytes';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
};
</script>
