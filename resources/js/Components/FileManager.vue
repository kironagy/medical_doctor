<template>
  <div>
    <!-- Dropzone (Hidden if Read-Only) -->
    <div 
      v-if="canEdit"
      class="border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-colors mb-6 select-none"
      :class="isDragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-slate-300 dark:border-slate-700 hover:border-primary-400 dark:hover:border-primary-500 bg-slate-50 dark:bg-slate-900/50 hover:bg-slate-100 dark:hover:bg-slate-800'"
      @click="openFileDialog"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="handleDrop"
    >
      <input type="file" multiple ref="fileInput" class="hidden" @change="handleFileSelect">
      <div class="mx-auto h-12 w-12 text-slate-400 mb-3 flex items-center justify-center pointer-events-none">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
      </div>
      <h3 class="text-sm font-medium text-slate-900 dark:text-white mb-1 pointer-events-none">{{ $t('files.click_to_upload') }}</h3>
      <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 pointer-events-none">{{ $t('files.upload_hint') }}</p>
      <BaseButton variant="outline" size="sm" @click.stop="openFileDialog">{{ $t('files.select_files') }}</BaseButton>
    </div>

    <!-- Upload progress is now shown in the global Upload Manager (bottom-right) -->

    <!-- File Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div v-for="file in files" :key="file.id" 
           @click="activeMobileFile = file"
           class="group relative border dark:border-slate-800 rounded-xl p-2 hover:shadow-md transition-shadow bg-white dark:bg-slate-900 cursor-pointer md:cursor-default">
        <!-- Thumbnail -->
        <div class="aspect-square bg-slate-100 dark:bg-slate-800 rounded-lg mb-2 overflow-hidden flex items-center justify-center">
          <img v-if="file.thumbnail_url" :src="file.thumbnail_url" class="object-cover w-full h-full">
          <img v-else-if="file.mime_type?.startsWith('image/')" :src="file.url" class="object-cover w-full h-full">
          <div v-else-if="file.mime_type?.startsWith('video/')" class="text-slate-400 flex flex-col items-center">
            <svg class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
            <span v-if="['queued', 'processing', 'optimizing', 'generating_preview'].includes(file.upload_status)" class="text-[10px] font-bold uppercase tracking-wider text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full flex items-center">
              <svg class="animate-spin -ms-1 me-1.5 h-3 w-3 text-amber-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
              {{ file.upload_status.replace('_', ' ') }}
            </span>
            <span v-else-if="file.upload_status === 'failed'" class="text-[10px] font-bold uppercase tracking-wider text-rose-700 bg-rose-100 px-2 py-0.5 rounded-full">
              {{ $t('files.failed') }}
            </span>
          </div>
          <div v-else class="text-slate-400 font-bold uppercase text-lg">
            {{ file.extension || 'FILE' }}
          </div>
        </div>
        <!-- Info -->
        <div class="px-1 mt-1 flex flex-col">
          <p class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate" :title="file.title || file.name">{{ file.title || file.name }}</p>
          <p v-if="file.desc" class="text-[10px] text-slate-500 dark:text-slate-400 truncate" :title="file.desc">{{ file.desc }}</p>
          <p class="text-[10px] text-slate-400">{{ formatSize(file.size) }}</p>
        </div>
        <!-- Desktop Overlay Actions -->
        <div class="hidden absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 transition-opacity rounded-xl md:flex flex-wrap content-center justify-center gap-2 p-2 backdrop-blur-sm">
          <button @click.stop="$emit('preview', file)" class="p-2 bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 rounded-full text-slate-700 dark:text-slate-200 hover:text-primary-600 dark:hover:text-primary-400 shadow-sm transition-colors" :title="$t('common.preview')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
          </button>
          <button v-if="canEdit" @click.stop="openEditModal(file)" class="p-2 bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 rounded-full text-slate-700 dark:text-slate-200 hover:text-primary-600 dark:hover:text-primary-400 shadow-sm transition-colors" :title="$t('common.edit')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
          </button>
          <button @click.stop="downloadFile(file)" class="p-2 bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 rounded-full text-slate-700 dark:text-slate-200 hover:text-primary-600 dark:hover:text-primary-400 shadow-sm transition-colors" :title="$t('common.download')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
          </button>
          <button v-if="canEdit" @click.stop="deleteFile(file)" class="p-2 bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 rounded-full text-slate-700 dark:text-slate-200 hover:text-rose-600 dark:hover:text-rose-400 shadow-sm transition-colors" :title="$t('common.delete')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Mobile Bottom Sheet -->
    <div v-if="activeMobileFile" class="fixed inset-0 z-[70] flex flex-col justify-end md:hidden">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity" @click="activeMobileFile = null"></div>

      <!-- Sheet -->
      <div class="relative bg-white dark:bg-slate-900 rounded-t-2xl p-5 pb-6 shadow-2xl transform transition-transform animate-slide-up max-h-[90vh] overflow-y-auto"
           style="padding-bottom: calc(env(safe-area-inset-bottom) + 5rem);">
        <!-- Drag handle + close (top) -->
        <div class="flex items-center justify-between mb-4">
          <div class="w-10 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
          <button @click="activeMobileFile = null" class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <div class="flex items-center space-x-4 rtl:space-x-reverse mb-5 pb-5 border-b border-slate-100 dark:border-slate-800">
          <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden shrink-0">
            <img v-if="activeMobileFile.thumbnail_url" :src="activeMobileFile.thumbnail_url" class="w-full h-full object-cover">
            <img v-else-if="activeMobileFile.mime_type?.startsWith('image/')" :src="activeMobileFile.url" class="w-full h-full object-cover">
            <svg v-else class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
          </div>
          <div class="min-w-0 flex-1">
            <h4 class="text-sm font-bold text-slate-900 dark:text-white truncate">{{ activeMobileFile.name }}</h4>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ formatSize(activeMobileFile.size) }}</p>
          </div>
        </div>

        <div class="space-y-2.5">
          <button @click="handleMobilePreview(activeMobileFile)" class="w-full flex items-center space-x-3 rtl:space-x-reverse p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-colors text-start">
            <div class="w-9 h-9 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
            </div>
            <span class="font-medium">{{ $t('common.preview') }}</span>
          </button>

          <button v-if="canEdit" @click="handleMobileEdit(activeMobileFile)" class="w-full flex items-center space-x-3 rtl:space-x-reverse p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-colors text-start">
            <div class="w-9 h-9 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </div>
            <span class="font-medium">{{ $t('common.edit') }}</span>
          </button>

          <button @click="handleMobileDownload(activeMobileFile)" class="w-full flex items-center space-x-3 rtl:space-x-reverse p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-colors text-start">
            <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
            </div>
            <span class="font-medium">{{ $t('common.download') }}</span>
          </button>

          <button v-if="canEdit" @click="handleMobileDelete(activeMobileFile)" class="w-full flex items-center space-x-3 rtl:space-x-reverse p-3.5 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 active:bg-rose-100 dark:active:bg-rose-900/40 transition-colors text-start">
            <div class="w-9 h-9 rounded-full bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </div>
            <span class="font-medium">{{ $t('common.delete') }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Edit Details Modal -->
    <div v-if="showEditModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="closeEditModal"></div>
      <div class="relative bg-white dark:bg-slate-900 border dark:border-slate-800 rounded-2xl w-full max-w-md shadow-2xl p-6">
        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-4">{{ $t('files.edit_details') }}</h3>
        
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('files.title_name') }}</label>
            <input type="text" v-model="editForm.title" class="input-field">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('files.desc_optional') }}</label>
            <textarea v-model="editForm.desc" rows="3" class="input-field" :placeholder="$t('files.desc_placeholder')"></textarea>
          </div>
        </div>

        <div class="flex items-center justify-end gap-3 mt-6">
          <button @click="closeEditModal" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors">{{ $t('common.cancel') }}</button>
          <BaseButton @click="saveEditForm" :disabled="savingEdit">
            <span v-if="savingEdit">{{ $t('common.saving') }}...</span>
            <span v-else>{{ $t('common.save') }}</span>
          </BaseButton>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import BaseButton from '@/Components/BaseButton.vue';
import { useDialog } from '@/Composables/useDialog';
import { useUploads } from '@/Composables/useUploads';

const props = defineProps({
  patientId: String,
  category: String,
  files: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: true }
});

const emit = defineEmits(['preview', 'uploaded']);

const { enqueue, onUploaded } = useUploads();

const isDragging = ref(false);
const fileInput = ref(null);
const dialog = useDialog();
const activeMobileFile = ref(null);

const showEditModal = ref(false);
const savingEdit = ref(false);
const editForm = ref({ id: null, uuid: null, title: '', desc: '' });

let pollInterval = null;
let offUploaded = () => {};

const checkPolling = () => {
  const needsPolling = props.files.some(f =>
    ['queued', 'processing', 'optimizing', 'generating_preview'].includes(f.upload_status)
  );

  if (needsPolling && !pollInterval) {
    pollInterval = setInterval(() => {
      router.reload({ only: ['files'] });
    }, 3000);
  } else if (!needsPolling && pollInterval) {
    clearInterval(pollInterval);
    pollInterval = null;
  }
};

watch(() => props.files, () => { checkPolling(); }, { deep: true });

onMounted(() => {
  checkPolling();
  // Reload the file grid whenever the global store reports a finished upload
  // (so newly-uploaded files appear without manual refresh).
  offUploaded = onUploaded(() => emit('uploaded'));
});

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval);
  offUploaded();
});

// ---------- Dropzone ----------
const openFileDialog = () => {
  if (!props.canEdit) return;
  fileInput.value?.click();
};

const handleDrop = (e) => {
  if (!props.canEdit) return;
  isDragging.value = false;
  handleFiles(Array.from(e.dataTransfer.files));
};

const handleFileSelect = (e) => {
  if (!props.canEdit) return;
  handleFiles(Array.from(e.target.files));
  e.target.value = null; // Reset input
};

// Delegate uploads to the global background store so they survive navigation.
const handleFiles = (selectedFiles) => {
  enqueue(selectedFiles, {
    patientId: props.patientId,
    category: props.category,
  });
};

// ---------- Helpers ----------
const formatSize = (bytes) => {
  if (!bytes || bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const downloadFile = (file) => {
  window.open(file.url, '_blank');
};

const deleteFile = async (file) => {
  const confirmed = await dialog.confirm({
    title: 'Delete File',
    message: `Are you sure you want to delete ${file.name}? This action cannot be undone.`,
    confirmText: 'Delete',
    style: 'danger'
  });
  
  if (confirmed) {
    try {
      await axios.delete(`/api/v1/files/${file.uuid}`);
      emit('uploaded'); // trigger reload
    } catch (e) {
      console.error('Failed to delete', e);
      dialog.alert({
        title: 'Error',
        message: 'Failed to delete the file.',
        style: 'danger'
      });
    }
  }
};

const handleMobilePreview = (file) => {
  activeMobileFile.value = null;
  emit('preview', file);
};

const handleMobileEdit = (file) => {
  activeMobileFile.value = null;
  openEditModal(file);
};

const openEditModal = (file) => {
  editForm.value = {
    uuid: file.uuid,
    title: file.title || file.name,
    desc: file.desc || ''
  };
  showEditModal.value = true;
};

const closeEditModal = () => {
  showEditModal.value = false;
};

const saveEditForm = async () => {
  savingEdit.value = true;
  try {
    await axios.put(`/api/v1/files/${editForm.value.uuid}`, {
      title: editForm.value.title,
      desc: editForm.value.desc
    });
    showEditModal.value = false;
    emit('uploaded'); // trigger reload
  } catch (e) {
    console.error('Failed to update details', e);
    dialog.alert({
      title: 'Error',
      message: 'Failed to update file details.',
      style: 'danger'
    });
  } finally {
    savingEdit.value = false;
  }
};

const handleMobileDownload = (file) => {
  activeMobileFile.value = null;
  downloadFile(file);
};

const handleMobileDelete = (file) => {
  activeMobileFile.value = null;
  deleteFile(file);
};

// Allow the parent header "Upload File" button to open the picker
defineExpose({
  triggerUpload: openFileDialog,
});
</script>

<style>
@keyframes slide-up {
  0% { transform: translateY(100%); }
  100% { transform: translateY(0); }
}
.animate-slide-up {
  animation: slide-up 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>