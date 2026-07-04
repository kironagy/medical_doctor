<template>
  <!-- Desktop Overlay -->
  <div v-if="mode === 'overlay'" class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 transition-opacity rounded-xl flex items-center justify-center gap-1.5 p-2 backdrop-blur-sm">
    <button @click.stop="viewFile" class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 dark:text-slate-200 hover:text-primary-600 dark:hover:text-primary-400 shadow-sm transition-colors hover:scale-110 active:scale-95" title="View">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
    </button>
    <button v-if="canEdit" @click.stop="openEdit" class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 dark:text-slate-200 hover:text-primary-600 dark:hover:text-primary-400 shadow-sm transition-colors hover:scale-110 active:scale-95" title="Edit">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
    </button>
    <button v-if="canEdit" @click.stop="openMove" class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 dark:text-slate-200 hover:text-amber-600 dark:hover:text-amber-400 shadow-sm transition-colors hover:scale-110 active:scale-95" title="Move">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
    </button>
    <a v-if="file.url" :href="file.url" target="_blank" @click.stop class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 dark:text-slate-200 hover:text-emerald-600 dark:hover:text-emerald-400 shadow-sm transition-colors hover:scale-110 active:scale-95" title="Download">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
    </a>
    <button v-if="canEdit" :disabled="deleting" @click.stop="confirmDelete" class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 dark:text-slate-200 hover:text-rose-600 dark:hover:text-rose-400 shadow-sm transition-colors hover:scale-110 active:scale-95 disabled:opacity-60 disabled:cursor-not-allowed" title="Delete">
      <svg v-if="!deleting" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
      <svg v-else class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
    </button>
  </div>

  <!-- Mobile Bottom Sheet -->
  <Teleport to="body">
    <Transition name="backdrop-fade">
      <div v-if="mode === 'sheet' && file" class="fixed inset-0 z-[70] flex flex-col justify-end md:hidden" @click.self="close">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="close" />
        <div class="relative bg-white dark:bg-slate-900 rounded-t-2xl shadow-2xl transform transition-transform animate-slide-up max-h-[85vh] overflow-y-auto overscroll-contain"
             style="padding-bottom: calc(env(safe-area-inset-bottom) + 1rem);">
          <div class="sticky top-0 bg-white dark:bg-slate-900 z-10 flex items-center justify-between px-5 pt-4 pb-2">
            <div class="w-10 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full" />
            <button @click="close" class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors shrink-0">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <div class="px-5 pb-4">
            <div class="flex items-center gap-4 mb-4 pb-4 border-b border-slate-100 dark:border-slate-800">
              <div class="w-14 h-14 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden shrink-0 relative">
                <img v-if="file.thumbnail_url" :src="file.thumbnail_url" loading="lazy" decoding="async" class="w-full h-full object-cover absolute inset-0 z-0" @error="e => {
                  const relativeUrl = '/api/v1/files/' + file.uuid + '/thumbnail';
                  if (!e.target.src.endsWith(relativeUrl)) {
                    e.target.src = relativeUrl;
                  } else {
                    e.target.style.display='none';
                    e.target.nextElementSibling?.classList.remove('hidden');
                  }
                }" />
                <div :class="{ 'hidden': file.thumbnail_url }" class="w-full h-full flex items-center justify-center z-10 bg-slate-100 dark:bg-slate-800">
                  <img v-if="file.mime_type?.startsWith('image/')" :src="file.url" loading="lazy" decoding="async" class="w-full h-full object-cover" />
                  <svg v-else class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                </div>
              </div>
              <div class="min-w-0 flex-1">
                <h4 class="text-sm font-bold text-slate-900 dark:text-white truncate">{{ file.title || file.file_name }}</h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ fileType }} · {{ formatSize(file.size) }}</p>
                <p v-if="file.created_at" class="text-xs text-slate-400">{{ $t('files.uploaded_date') }}: {{ formatDate(file.created_at) }}</p>
              </div>
            </div>

            <div class="space-y-1">
              <button @click="viewFile" class="w-full flex items-center gap-4 p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-all text-start active:scale-[0.98]">
                <div class="w-9 h-9 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                  <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                </div>
                <span class="font-medium text-sm">View</span>
              </button>

              <button v-if="canEdit" @click="openEdit" class="w-full flex items-center gap-4 p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-all text-start active:scale-[0.98]">
                <div class="w-9 h-9 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                  <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </div>
                <span class="font-medium text-sm">Edit</span>
              </button>

              <button v-if="canEdit" @click="openMove" class="w-full flex items-center gap-4 p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-all text-start active:scale-[0.98]">
                <div class="w-9 h-9 rounded-full bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                  <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                </div>
                <span class="font-medium text-sm">Move</span>
              </button>

              <a v-if="file.url" :href="file.url" target="_blank" class="w-full flex items-center gap-4 p-3.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:bg-slate-100 dark:active:bg-slate-700 transition-all text-start active:scale-[0.98]">
                <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                  <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                </div>
                <span class="font-medium text-sm">Download</span>
              </a>

              <button v-if="canEdit" :disabled="deleting" @click="confirmDelete" class="w-full flex items-center gap-4 p-3.5 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 active:bg-rose-100 dark:active:bg-rose-900/40 transition-all text-start active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed">
                <div class="w-9 h-9 rounded-full bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                  <svg v-if="!deleting" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                  <svg v-else class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                </div>
                <span class="font-medium text-sm">{{ deleting ? 'Deleting...' : 'Delete' }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Edit Dialog -->
  <Teleport to="body">
    <Transition name="backdrop-fade">
      <div v-if="showEdit" class="fixed inset-0 z-[200] flex items-end md:items-center justify-center" @click.self="closeEdit">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="closeEdit" />
        <div class="relative bg-white dark:bg-slate-900 border dark:border-slate-800 w-full md:max-w-lg max-h-[90vh] md:rounded-2xl rounded-t-2xl shadow-2xl flex flex-col overflow-hidden"
             style="padding-bottom: env(safe-area-inset-bottom, 0px)">
          <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 sticky top-0 z-10">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">Edit File</h3>
            <button @click="closeEdit" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="overflow-y-auto overscroll-contain flex-1 p-5 space-y-4">
            <div>
              <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Title</label>
              <input v-model="editForm.title" type="text" class="input-field w-full" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
              <textarea v-model="editForm.desc" rows="3" class="input-field w-full" />
            </div>
          </div>
          <div class="sticky bottom-0 bg-white dark:bg-slate-900 border-t border-slate-100 dark:border-slate-800 px-5 py-4 flex justify-end gap-3">
            <button @click="closeEdit" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">Cancel</button>
            <BaseButton @click="saveEdit" :disabled="saving" size="sm">
              <svg v-if="saving" class="w-4 h-4 animate-spin me-1.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
              {{ saving ? 'Saving...' : 'Save' }}
            </BaseButton>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Move Dialog -->
  <Teleport to="body">
    <Transition name="backdrop-fade">
      <div v-if="showMove" class="fixed inset-0 z-[200] flex items-end md:items-center justify-center" @click.self="closeMove">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="closeMove" />
        <div class="relative bg-white dark:bg-slate-900 border dark:border-slate-800 w-full md:max-w-sm max-h-[90vh] md:rounded-2xl rounded-t-2xl shadow-2xl flex flex-col overflow-hidden"
             style="padding-bottom: env(safe-area-inset-bottom, 0px)">
          <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 sticky top-0 z-10">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">Move File</h3>
            <button @click="closeMove" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="overflow-y-auto overscroll-contain flex-1 p-5">
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Select a destination category for "{{ file?.title || file?.file_name }}"</p>
            <div class="space-y-1">
              <button
                v-for="cat in categories"
                :key="cat.slug"
                @click="moveToCategory(cat)"
                :disabled="cat.slug === file?.category"
                class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all text-start"
                :class="cat.slug === file?.category
                  ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed bg-slate-50 dark:bg-slate-800/50'
                  : 'text-slate-700 dark:text-slate-200 hover:bg-primary-50 dark:hover:bg-primary-900/20 hover:text-primary-700 dark:hover:text-primary-300 active:scale-[0.98]'"
              >
                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-base flex-shrink-0" :style="{ backgroundColor: cat.color + '20' }">
                  <span>{{ cat.icon || '📁' }}</span>
                </div>
                <span class="font-medium">{{ cat.name }}</span>
                <span v-if="cat.slug === file?.category" class="ms-auto text-[10px] text-slate-400 bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded-full">Current</span>
              </button>
            </div>
          </div>
          <div class="sticky bottom-0 bg-white dark:bg-slate-900 border-t border-slate-100 dark:border-slate-800 px-5 py-4 flex justify-end gap-3">
            <button @click="closeMove" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">Cancel</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import BaseButton from '@/Components/BaseButton.vue'
import axios from 'axios'

const props = defineProps({
  file: { type: Object, default: null },
  canEdit: { type: Boolean, default: true },
  mode: { type: String, default: 'overlay' },
  categories: { type: Array, default: () => [] },
  patientUuid: { type: String, default: null },
})

const emit = defineEmits(['file-updated', 'file-moved', 'file-deleted', 'close'])

const dialog = useDialog()
const toast = useToast()

const showEdit = ref(false)
const showMove = ref(false)
const saving = ref(false)
const deleting = ref(false)
const editForm = ref({ title: '', desc: '' })

const fileType = computed(() => {
  if (!props.file?.mime_type) return 'File'
  if (props.file.mime_type.startsWith('image/')) return 'Image'
  if (props.file.mime_type.startsWith('video/')) return 'Video'
  if (props.file.mime_type.startsWith('audio/')) return 'Audio'
  if (props.file.mime_type === 'application/pdf') return 'PDF'
  return props.file.extension?.toUpperCase() || 'File'
})

function formatSize(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function close() {
  emit('close')
}

function viewFile() {
  emit('preview', props.file)
  if (props.mode === 'sheet') emit('close')
}

function downloadFile() {
  if (props.file?.url) {
    window.open(props.file.url, '_blank')
  }
  if (props.mode === 'sheet') emit('close')
}

function openEdit() {
  editForm.value = {
    title: props.file?.title || props.file?.file_name || '',
    desc: props.file?.desc || '',
  }
  showEdit.value = true
}

function closeEdit() {
  showEdit.value = false
}

async function saveEdit() {
  if (!props.file?.uuid) return
  const nextTitle = editForm.value.title?.trim() || ''
  const nextDesc = editForm.value.desc?.trim() || ''
  if (!nextTitle) {
    toast.error('Title is required')
    return
  }
  saving.value = true
  try {
    const payload = {}
    if (nextTitle !== (props.file?.title || props.file?.file_name || '').trim()) payload.title = nextTitle
    if (nextDesc !== (props.file?.desc || '').trim()) payload.desc = nextDesc
    if (Object.keys(payload).length === 0) {
      showEdit.value = false
      return
    }
    const res = await axios.put(`/api/v1/files/${props.file.uuid}`, payload)
    emit('file-updated', res.data)
    showEdit.value = false
    toast.success('File updated')
  } catch (e) {
    console.error('Edit failed:', e)
    toast.error(e.response?.data?.message || 'Failed to update file')
  } finally {
    saving.value = false
  }
}

function openMove() {
  showMove.value = true
}

function closeMove() {
  showMove.value = false
}

async function moveToCategory(cat) {
  if (cat.slug === props.file?.category) return
  try {
    await axios.put(`/api/v1/files/${props.file.uuid}`, { category: cat.slug })
    emit('file-moved', { ...props.file, category: cat.slug })
    showMove.value = false
    toast.success(`File moved to ${cat.name}`)
  } catch (e) {
    console.error('Move failed:', e)
    toast.error('Failed to move file')
  }
}

async function confirmDelete() {
  if (deleting.value) return
  const confirmed = await dialog.confirm({
    title: 'Delete File',
    message: `Delete "${props.file?.title || props.file?.file_name}"? This action cannot be undone.`,
    confirmText: 'Delete',
    style: 'danger',
  })
  if (!confirmed) return
  deleting.value = true
  try {
    await axios.delete(`/api/v1/files/${props.file.uuid}`)
    emit('file-deleted', props.file)
    if (props.mode === 'sheet') emit('close')
    toast.success('File deleted')
  } catch (e) {
    console.error('Delete failed:', e)
    toast.error(e.response?.data?.message || 'Failed to delete file')
  } finally {
    deleting.value = false
  }
}
</script>

<style scoped>
.backdrop-fade-enter-active,
.backdrop-fade-leave-active {
  transition: opacity 0.2s ease;
}
.backdrop-fade-enter-from,
.backdrop-fade-leave-to {
  opacity: 0;
}
:global(.animate-slide-up) {
  animation: slide-up 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes slide-up {
  0% { transform: translateY(100%); }
  100% { transform: translateY(0); }
}
</style>
