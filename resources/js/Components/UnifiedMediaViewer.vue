<template>
  <div v-if="show" class="fixed inset-0 z-[100] flex flex-col bg-slate-900 backdrop-blur-md">
    <!-- Top Bar -->
    <div class="flex items-center justify-between px-4 py-3 bg-slate-900/90 border-b border-slate-700/50 shadow-sm z-50">
      <div class="flex items-center space-x-3 text-white truncate max-w-2xl">
        <svg v-if="type === 'video'" class="w-5 h-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
        <svg v-else-if="type === 'image'" class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
        <svg v-else-if="type === 'pdf'" class="w-5 h-5 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
        <svg v-else-if="type === 'text'" class="w-5 h-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
        <svg v-else class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
        
        <div>
          <h2 class="text-sm font-semibold truncate">{{ file?.file_name }}</h2>
          <p class="text-xs text-slate-400">{{ formatBytes(file?.file_size) }} &bull; {{ file?.mime_type }}</p>
        </div>
      </div>
      <div class="flex items-center space-x-2 rtl:space-x-reverse">
        <a :href="fileUrl" target="_blank" class="p-2 text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors flex items-center" :title="$t('common.download')">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
        </a>
        <button @click="close" class="p-2 text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors" :title="$t('common.close')">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>
    </div>
    
    <!-- Viewer Area -->
    <div class="flex-1 relative flex items-center justify-center overflow-hidden">
      
      <!-- Video Player (Video.js) -->
      <div v-if="type === 'video'" class="w-full h-full flex items-center justify-center bg-black">
        <video
          ref="videoPlayer"
          class="video-js vjs-big-play-centered w-full h-full max-h-screen"
          :poster="posterUrl"
          preload="metadata"
        ></video>
      </div>
      
      <!-- Image Viewer (v-viewer) -->
      <div v-else-if="type === 'image'" class="w-full h-full flex items-center justify-center p-4">
        <viewer :images="[fileUrl]" class="w-full h-full flex items-center justify-center">
          <img :src="fileUrl" class="max-w-full max-h-full object-contain cursor-zoom-in" :alt="file?.file_name" />
        </viewer>
      </div>

      <!-- PDF Viewer (Native) -->
      <div v-else-if="type === 'pdf'" class="w-full h-full bg-slate-100 dark:bg-slate-800 flex flex-col">
        <object :data="fileUrl" type="application/pdf" class="w-full flex-1">
          <div class="flex items-center justify-center h-full">
            <div class="text-center p-8 bg-white dark:bg-slate-900 rounded-xl shadow-sm">
              <p class="text-slate-600 dark:text-slate-400 mb-4">{{ $t('files.pdf_no_inline') }}</p>
              <a :href="fileUrl" target="_blank" class="inline-flex justify-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors">
                {{ $t('files.download_pdf') }}
              </a>
            </div>
          </div>
        </object>
      </div>

      <!-- Text/Code Viewer (highlight.js) -->
      <div v-else-if="type === 'text'" class="w-full h-full overflow-auto bg-[#1e1e1e] p-6">
        <div v-if="loadingText" class="flex justify-center mt-20">
          <div class="w-8 h-8 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
        </div>
        <pre v-else><code ref="codeBlock" class="text-sm font-mono leading-relaxed" :class="textLanguage">{{ textContent }}</code></pre>
      </div>
      
      <!-- Audio Player -->
      <div v-else-if="type === 'audio'" class="bg-slate-800 p-8 rounded-2xl shadow-2xl w-full max-w-md text-center">
        <div class="w-20 h-20 bg-indigo-500/20 text-indigo-400 rounded-full flex items-center justify-center mx-auto mb-6">
          <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3" /></svg>
        </div>
        <h3 class="text-lg font-bold text-white mb-2 truncate">{{ file?.file_name }}</h3>
        <audio :src="fileUrl" controls autoplay class="w-full mt-4"></audio>
      </div>

      <!-- Generic Fallback -->
      <div v-else class="bg-slate-800 p-12 rounded-2xl shadow-2xl text-center max-w-md w-full mx-4">
        <svg class="w-20 h-20 mx-auto text-slate-500 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
        <h3 class="text-xl font-bold text-white mb-2 truncate">{{ file?.file_name }}</h3>
        <p class="text-slate-400 mb-8">{{ $t('files.no_preview_for') }} {{ file?.mime_type }}</p>
        <a :href="fileUrl" target="_blank" class="inline-flex justify-center w-full px-4 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors">
          {{ $t('files.download_file') }}
        </a>
      </div>
      
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch, nextTick, onBeforeUnmount } from 'vue';
import axios from 'axios';
import videojs from 'video.js';
import 'video.js/dist/video-js.css';

import { component as Viewer } from 'v-viewer';
import 'viewerjs/dist/viewer.css';

import hljs from 'highlight.js';
import 'highlight.js/styles/atom-one-dark.css';

const props = defineProps({
  show: Boolean,
  file: Object
});

const emit = defineEmits(['close']);
const videoPlayer = ref(null);
let vjsPlayer = null;

const textContent = ref('');
const loadingText = ref(false);
const codeBlock = ref(null);

const fileUrl = computed(() => {
  if (!props.file) return '';
  return `/api/v1/files/${props.file.uuid}`;
});

const posterUrl = computed(() => {
  if (!props.file) return '';
  return props.file.thumbnail_url || `/api/v1/files/${props.file.uuid}/thumbnail`;
});

const hlsUrl = computed(() => {
  if (!props.file) return '';
  // PatientFile appends hls_url; Lara props pass it through
  return props.file.hls_url || props.file.hls_path
    ? `/api/v1/files/${props.file.uuid}/hls/playlist.m3u8`
    : null;
});

const type = computed(() => {
  if (!props.file) return 'unknown';
  const mime = props.file.mime_type || '';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  if (mime.startsWith('image/')) return 'image';
  if (mime === 'application/pdf') return 'pdf';
  if (mime.startsWith('text/') || mime === 'application/json' || mime === 'application/xml') return 'text';
  return 'unknown';
});

const textLanguage = computed(() => {
  if (!props.file) return 'language-plaintext';
  const ext = props.file.file_name.split('.').pop().toLowerCase();
  const map = {
    'json': 'language-json',
    'xml': 'language-xml',
    'html': 'language-xml',
    'js': 'language-javascript',
    'php': 'language-php',
    'css': 'language-css',
    'md': 'language-markdown',
    'csv': 'language-csv',
  };
  return map[ext] || 'language-plaintext';
});

const initVideo = () => {
  if (vjsPlayer) {
    vjsPlayer.dispose();
  }
  nextTick(() => {
    if (videoPlayer.value) {
      // Prefer HLS for adaptive streaming on slow networks; fall back to progressive MP4.
      const sources = hlsUrl.value
        ? [{ src: hlsUrl.value, type: 'application/x-mpegURL' }]
        : [{ src: fileUrl.value, type: props.file.mime_type || 'video/mp4' }];

      vjsPlayer = videojs(videoPlayer.value, {
        controls: true,
        autoplay: true,
        preload: 'metadata',
        fluid: false,
        playbackRates: [0.5, 1, 1.25, 1.5, 2],
        poster: posterUrl.value,
        html5: {
          vhs: {
            overrideNative: true,
            enableLowInitialPlaylist: true,
            lowLatencyMode: false,
          },
        },
        sources,
      });
    }
  });
};

const initText = async () => {
  loadingText.value = true;
  textContent.value = '';
  try {
    const res = await axios.get(fileUrl.value, { responseType: 'text' });
    textContent.value = res.data;
    nextTick(() => {
      if (codeBlock.value) {
        hljs.highlightElement(codeBlock.value);
      }
    });
  } catch (e) {
    textContent.value = 'Failed to load text content. ' + e.message;
  } finally {
    loadingText.value = false;
  }
};

watch(() => props.show, (newVal) => {
  if (newVal && props.file) {
    if (type.value === 'video') {
      initVideo();
    } else if (type.value === 'text') {
      initText();
    }
  } else {
    if (vjsPlayer) {
      vjsPlayer.pause();
    }
  }
});

const close = () => {
  if (vjsPlayer) {
    vjsPlayer.pause();
  }
  emit('close');
};

onBeforeUnmount(() => {
  if (vjsPlayer) {
    vjsPlayer.dispose();
  }
});

const formatBytes = (bytes, decimals = 2) => {
  if (!+bytes) return '0 Bytes';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
};
</script>

<style>
/* Video.js City Theme tweaks */
.video-js .vjs-big-play-button {
  border-radius: 50% !important;
  width: 80px !important;
  height: 80px !important;
  line-height: 80px !important;
}
</style>
