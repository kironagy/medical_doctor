<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="modelValue" class="fixed inset-0 z-[150] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="$emit('update:modelValue', false)"></div>

        <!-- Modal Box -->
        <div class="relative bg-white dark:bg-slate-900 rounded-[28px] shadow-2xl w-full max-w-md p-8 flex flex-col border border-slate-100 dark:border-slate-800 animate-scale-up text-start">
          <!-- Close Button -->
          <button @click="$emit('update:modelValue', false)" class="absolute top-4 left-4 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <!-- Header -->
          <h2 class="text-xl font-black text-teal-600 dark:text-teal-400 mb-6 border-b border-slate-100 dark:border-slate-800 pb-3">إضافة (Add)</h2>

          <form @submit.prevent="submit" class="space-y-5">
            <!-- Tabs (كتابة نص / إرفاق ملف) -->
            <div class="grid grid-cols-2 gap-4">
              <!-- Tab: كتابة نص -->
              <button
                type="button"
                @click="activeTab = 'text'"
                class="flex flex-col items-center justify-center py-4 px-3 rounded-2xl border-2 transition-all"
                :class="activeTab === 'text'
                  ? 'border-teal-500 bg-teal-50/40 text-teal-700 dark:bg-teal-950/30 dark:text-teal-400'
                  : 'border-slate-200 dark:border-slate-800 text-slate-500 bg-white dark:bg-slate-900 hover:bg-slate-50'"
              >
                <svg class="w-7 h-7 mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16m-7 6h7" />
                </svg>
                <span class="text-sm font-bold">كتابة نص</span>
              </button>

              <!-- Tab: إرفاق ملف -->
              <button
                type="button"
                @click="activeTab = 'file'"
                class="flex flex-col items-center justify-center py-4 px-3 rounded-2xl border-2 transition-all"
                :class="activeTab === 'file'
                  ? 'border-teal-500 bg-teal-50/40 text-teal-700 dark:bg-teal-950/30 dark:text-teal-400'
                  : 'border-slate-200 dark:border-slate-800 text-slate-500 bg-white dark:bg-slate-900 hover:bg-slate-50'"
              >
                <svg class="w-7 h-7 mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span class="text-sm font-bold">إرفاق ملف</span>
              </button>
            </div>

            <!-- Upload Area (Only for file tab) -->
            <div v-if="activeTab === 'file'" class="space-y-3">
              <!-- Dashed Upload Zone -->
              <div
                @click="triggerFileInput"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="handleDrop"
                class="border-2 border-dashed border-teal-300 dark:border-teal-800 rounded-2xl p-6 text-center cursor-pointer hover:border-teal-500 hover:bg-teal-50/20 transition-all flex flex-col items-center justify-center min-h-[120px]"
                :class="dragging ? 'border-teal-500 bg-teal-50 dark:bg-teal-900/10' : 'bg-slate-50/50 dark:bg-slate-950/30'"
              >
                <input type="file" ref="fileInput" multiple class="hidden" accept="image/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx" @change="handleFileSelect" />
                
                <div class="w-12 h-12 bg-teal-50 dark:bg-teal-950/50 rounded-full flex items-center justify-center mb-2">
                  <svg class="w-6 h-6 text-teal-600 dark:text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                </div>
                <span class="text-xs font-bold text-teal-700 dark:text-teal-400">اضغط لاختيار صورة، PDF، أو فيديو</span>
              </div>

              <!-- Selected Files List -->
              <div v-if="selectedFiles.length > 0" class="max-h-24 overflow-y-auto space-y-1.5">
                <div v-for="(file, idx) in selectedFiles" :key="idx" class="flex items-center justify-between p-2 bg-slate-50 dark:bg-slate-800 rounded-xl text-xs">
                  <span class="font-bold truncate max-w-[80%] text-slate-700 dark:text-slate-300">{{ file.name }}</span>
                  <button type="button" @click="removeFile(idx)" class="text-rose-500 hover:text-rose-700 font-bold">حذف</button>
                </div>
              </div>
            </div>

            <!-- Notes Area -->
            <div>
              <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">التفاصيل أو الملاحظات:</label>
              <textarea
                v-model="notes"
                rows="4"
                class="input-field w-full rounded-2xl border-slate-200 dark:border-slate-800 bg-slate-50/50 focus:ring-teal-500 focus:border-teal-500 placeholder-slate-400"
                placeholder="..."
              ></textarea>
            </div>

            <!-- Action Buttons Footer -->
            <div class="flex justify-start gap-3 flex-row-reverse pt-2">
              <button
                type="submit"
                :disabled="saving"
                class="btn-primary !px-8 text-sm font-bold shadow-sm"
              >
                <span v-if="saving">جاري الحفظ...</span>
                <span v-else>حفظ</span>
              </button>
              <button
                type="button"
                @click="$emit('update:modelValue', false)"
                class="px-8 py-2.5 bg-teal-50/50 hover:bg-teal-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-teal-600 dark:text-teal-400 rounded-xl text-sm font-bold transition-all"
              >
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useToast } from '@/Composables/useToast'
import { useUploads } from '@/Composables/useUploads'
import { useOfflineUploads } from '@/Composables/useOfflineUploads'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useSyncEngine } from '@/Composables/useSyncEngine'
import axios from 'axios'

const props = defineProps({
  modelValue: Boolean,
  patient: Object,
  categorySlug: String,
  initialTab: { type: String, default: 'text' },
})

const emit = defineEmits(['update:modelValue', 'saved', 'noteAdded'])

const toast = useToast()
const { uploadFile: onlineUploadFile } = useUploads()
const { uploadFile: offlineUploadFile } = useOfflineUploads()
const { addNoteLocally } = useWorkspace()
const { triggerSync } = useSyncEngine()

const activeTab = ref('text')
const notes = ref('')
const selectedFiles = ref([])
const fileInput = ref(null)
const dragging = ref(false)
const saving = ref(false)

watch(() => props.modelValue, (val) => {
  if (val) {
    activeTab.value = props.initialTab || 'text'
    notes.value = ''
    selectedFiles.value = []
    saving.value = false
  }
})

function triggerFileInput() {
  fileInput.value?.click()
}

function handleFileSelect(e) {
  const files = Array.from(e.target.files || [])
  if (files.length > 0) {
    selectedFiles.value.push(...files)
  }
}

function handleDrop(e) {
  dragging.value = false
  const files = Array.from(e.dataTransfer?.files || [])
  if (files.length > 0) {
    selectedFiles.value.push(...files)
  }
}

function removeFile(idx) {
  selectedFiles.value.splice(idx, 1)
}

async function submit() {
  if (saving.value) return
  if (!props.patient?.uuid || !props.categorySlug) return

  if (activeTab.value === 'text') {
    if (!notes.value.trim()) {
      toast.error('يرجى كتابة نص الملاحظة')
      return
    }
    saving.value = true
    try {
      const online = typeof navigator !== 'undefined' ? navigator.onLine : true

        if (online) {
          // ═══════════════════════════════════════════════════════════════
          //  ONLINE: Save locally via mobile route — sync engine will push
          // ═══════════════════════════════════════════════════════════════
          const saveRes = await axios.post(`/api/v1/mobile/patients/${props.patient.uuid}/notes`, {
            content: notes.value,
            category: props.categorySlug,
          })
          const createdNote = saveRes.data
          // Add to local UI immediately without waiting for reload
          if (createdNote?.uuid) {
            addNoteLocally(createdNote)
          }
          toast.success('تمت إضافة الملاحظة بنجاح')
          emit('saved')
          emit('update:modelValue', false)
          
          // trigger sync explicitly
          axios.post('/_native/api/sync').catch(() => {})
          
          // Reload the category list AFTER note is confirmed on server
          emit('noteAdded', createdNote)
        } else {
          // ═══════════════════════════════════════════════════════════════
          //  OFFLINE: Save locally — sync engine will push when online
          // ═══════════════════════════════════════════════════════════════
          const saveRes = await axios.post(`/api/v1/mobile/patients/${props.patient.uuid}/notes`, {
            content: notes.value,
            category: props.categorySlug,
          })
          const createdNote = saveRes.data
          if (createdNote?.uuid) {
            addNoteLocally(createdNote)
          }
          toast.success('تمت إضافة الملاحظة (في انتظار الاتصال)')
          emit('saved')
          emit('update:modelValue', false)
        }
    } catch (e) {
      console.error('Note add failed:', e)
      toast.error('فشل إضافة الملاحظة')
    } finally {
      saving.value = false
    }
  } else {
    if (selectedFiles.value.length === 0) {
      toast.error('يرجى اختيار ملف واحد على الأقل')
      return
    }
    saving.value = true
    try {
      const online = typeof navigator !== 'undefined' ? navigator.onLine : true
      const patientId = props.patient.id
      const patientUuid = props.patient.uuid
      for (const file of selectedFiles.value) {
        if (online) {
          // Online: use chunked upload composable
          onlineUploadFile(file, patientUuid, { 
            category: props.categorySlug,
            desc: notes.value
          })
        } else {
          // Offline: save file locally via offline upload composable
          await offlineUploadFile(file, patientUuid, { 
            category: props.categorySlug,
            desc: notes.value
          })
        }
      }
      toast.success('بدء رفع الملفات بنجاح')
      emit('saved')
      emit('update:modelValue', false)
    } catch (e) {
      console.error('Upload failed:', e)
      toast.error('فشل بدء رفع الملفات')
    } finally {
      saving.value = false
    }
  }
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.animate-scale-up {
  animation: scale-up 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
@keyframes scale-up {
  0% { transform: scale(0.95); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
</style>
