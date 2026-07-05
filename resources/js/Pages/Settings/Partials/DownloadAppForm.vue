<template>
  <BaseCard>
    <div class="space-y-6">
      <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
        Download App
      </h2>

      <div v-if="loading" class="flex items-center justify-center py-8">
        <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <span class="ms-3 text-slate-500 dark:text-slate-400">Checking for updates...</span>
      </div>

      <div v-else-if="error" class="text-center py-8">
        <p class="text-slate-500 dark:text-slate-400 mb-4">Unable to check for updates at this time.</p>
        <button @click="fetchRelease" class="text-sm text-primary-600 dark:text-primary-400 hover:underline">
          Try again
        </button>
      </div>

      <div v-else-if="release" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Android Version</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ release.versionName }}</p>
          </div>
          <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Version Name</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ release.tagName }}</p>
          </div>
          <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Build Number</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ release.buildNumber }}</p>
          </div>
          <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Release Date</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ release.releaseDate }}</p>
          </div>
        </div>

        <div v-if="release.body" class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
          <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Release Notes</p>
          <div class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-wrap">{{ release.body }}</div>
        </div>

        <button
          @click="startDownload"
          :disabled="downloading"
          class="w-full flex items-center justify-center px-6 py-3 bg-primary-600 hover:bg-primary-700 disabled:bg-primary-400 text-white font-medium rounded-xl transition-colors"
        >
          <svg v-if="!downloading" class="w-5 h-5 me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          <svg v-else class="animate-spin w-5 h-5 me-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          {{ downloading ? 'Downloading...' : 'Download Latest APK' }}
        </button>
      </div>
    </div>
  </BaseCard>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import BaseCard from '@/Components/BaseCard.vue';

const GITHUB_API = 'https://api.github.com/repos/kironagy/medical_doctor/releases/latest';

const loading = ref(true);
const error = ref(false);
const downloading = ref(false);
const release = ref(null);

async function fetchRelease() {
  loading.value = true;
  error.value = false;
  try {
    const res = await fetch(GITHUB_API, {
      headers: {
        'Accept': 'application/vnd.github.v3+json',
        'User-Agent': 'MedicalPlus-App'
      }
    });
    if (!res.ok) throw new Error('GitHub API error');
    const data = await res.json();
    const tagName = (data.tag_name || '').replace(/^v/, '');
    const assets = data.assets || [];
    const apkAsset = assets.find(a => a.name && a.name.endsWith('.apk'));
    const publishedAt = data.published_at || '';
    const dateFormatted = publishedAt.length >= 10 ? publishedAt.substring(0, 10) : publishedAt;
    release.value = {
      versionName: tagName,
      tagName: data.tag_name || '',
      buildNumber: String(data.id || ''),
      releaseDate: dateFormatted,
      downloadUrl: apkAsset ? apkAsset.browser_download_url : '',
      body: data.body || ''
    };
  } catch (e) {
    error.value = true;
  } finally {
    loading.value = false;
  }
}

function startDownload() {
  if (!release.value?.downloadUrl) return;
  downloading.value = true;
  const link = document.createElement('a');
  link.href = release.value.downloadUrl;
  link.download = 'MedicalPlus.apk';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  setTimeout(() => { downloading.value = false; }, 2000);
}

onMounted(fetchRelease);
</script>
