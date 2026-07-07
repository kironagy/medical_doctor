<template>
  <BaseDialog v-model="open" title="مشاركة الملف الطبي" size="md">
    <div dir="rtl" class="text-right space-y-4">
    <div v-if="step === 'search'" class="space-y-4">
      <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input
          ref="searchInput"
          v-model="searchQuery"
          @input="onSearchInput"
          type="text"
          :placeholder="$t('patients.search_doctor_placeholder')"
          class="w-full pl-10 pr-4 py-2.5 text-sm border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition-all"
        />
      </div>

      <div v-if="searchResults.length > 0" class="space-y-2">
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $t('patients.search_results') }} ({{ searchResults.length }})</p>
        <div class="max-h-64 overflow-y-auto space-y-2">
          <button
            v-for="doc in searchResults"
            :key="doc.id"
            @click="selectDoctor(doc)"
            class="w-full flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50 text-start transition-all hover:border-primary-200 dark:hover:border-primary-800"
          >
            <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 flex items-center justify-center font-bold text-sm flex-shrink-0">
              {{ doc.name?.charAt(0) || 'D' }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ doc.name }}</p>
              <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ doc.specialization || $t('common.not_specified') }}</p>
              <p class="text-xs text-slate-400 truncate">{{ doc.email }}</p>
            </div>
            <span class="text-xs font-medium text-primary-600 dark:text-primary-400 flex-shrink-0">{{ $t('patients.share_record') }}</span>
          </button>
        </div>
      </div>

      <div v-else-if="searched && searchQuery.length >= 2" class="text-center py-8">
        <svg class="w-12 h-12 mx-auto text-slate-300 dark:text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $t('common.no_results') }}</p>
      </div>

      <div v-else class="text-center py-8">
        <svg class="w-12 h-12 mx-auto text-slate-300 dark:text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
        </svg>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $t('patients.select_doctor_prompt') }}</p>
      </div>
    </div>

    <!-- Permission Step -->
    <div v-if="step === 'permission'" class="space-y-5">
      <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700">
        <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 flex items-center justify-center font-bold text-sm flex-shrink-0">
          {{ selectedDoctor?.name?.charAt(0) || 'D' }}
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ selectedDoctor?.name }}</p>
          <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ selectedDoctor?.specialization || '' }}</p>
        </div>
      </div>

      <div>
        <p class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-2">{{ $t('patients.access_level') }}</p>
        <div class="space-y-2">
          <button
            @click="accessLevel = 'read'"
            class="w-full p-3 rounded-xl border-2 text-start transition-all"
            :class="accessLevel === 'read'
              ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
              : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'"
          >
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $t('patients.read_only') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $t('patients.read_only_desc') }}</p>
              </div>
              <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0"
                :class="accessLevel === 'read' ? 'border-primary-500' : 'border-slate-300 dark:border-slate-600'"
              >
                <div v-if="accessLevel === 'read'" class="w-2.5 h-2.5 rounded-full bg-primary-500"></div>
              </div>
            </div>
          </button>
          <button
            @click="accessLevel = 'read_write'"
            class="w-full p-3 rounded-xl border-2 text-start transition-all"
            :class="accessLevel === 'read_write'
              ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
              : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'"
          >
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $t('patients.read_write') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $t('patients.read_write_desc') }}</p>
              </div>
              <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0"
                :class="accessLevel === 'read_write' ? 'border-primary-500' : 'border-slate-300 dark:border-slate-600'"
              >
                <div v-if="accessLevel === 'read_write'" class="w-2.5 h-2.5 rounded-full bg-primary-500"></div>
              </div>
            </div>
          </button>
        </div>
      </div>

      <div v-if="existingShare" class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-xl border border-amber-200 dark:border-amber-800">
        {{ $t('patients.permission_updated') }}
      </div>
    </div>

    <!-- Confirmation Step -->
    <div v-if="step === 'confirm'" class="space-y-4">
      <div class="text-center">
        <div class="w-14 h-14 mx-auto rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 flex items-center justify-center mb-3">
          <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
        </div>
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">{{ $t('patients.share_with') }}</p>
        <p class="text-lg font-bold font-heading text-slate-900 dark:text-white mb-1">{{ patient?.name || '' }}</p>
        <div class="flex items-center justify-center gap-2">
          <div class="w-px h-4 bg-slate-300 dark:bg-slate-600"></div>
          <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          <div class="w-px h-4 bg-slate-300 dark:bg-slate-600"></div>
        </div>
        <p class="text-base font-medium text-slate-900 dark:text-white mt-1">{{ selectedDoctor?.name }}</p>
      </div>

      <div class="rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-800">
        <div class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-slate-600 dark:text-slate-400">{{ $t('patients.access_level') }}</span>
          <span class="text-sm font-medium text-slate-900 dark:text-white">{{ accessLevel === 'read' ? $t('patients.read_only') : $t('patients.read_write') }}</span>
        </div>
        <div class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-slate-600 dark:text-slate-400">{{ $t('patients.search_doctor') }}</span>
          <span class="text-sm font-medium text-slate-900 dark:text-white">{{ selectedDoctor?.name }}</span>
        </div>
      </div>
    </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-between w-full gap-3" dir="rtl">
        <div>
          <button
            v-if="step === 'permission'"
            @click="step = 'search'"
            class="px-4 py-2 text-sm font-bold text-slate-500 hover:text-slate-700 transition-colors"
          >
            رجوع
          </button>
        </div>
        <div class="flex gap-3 flex-row-reverse">
          <button
            v-if="step !== 'confirm'"
            @click="open = false"
            class="px-5 py-2 text-sm font-bold border border-primary-500 text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-lg transition-all"
          >
            إلغاء
          </button>
          <button
            v-if="step === 'permission'"
            @click="step = 'confirm'"
            class="px-5 py-2 text-sm font-bold bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-all"
          >
            استمرار
          </button>
          <button
            v-if="step === 'confirm'"
            @click="submitShare"
            :disabled="submitting"
            class="px-6 py-2 text-sm font-bold bg-primary-600 hover:bg-primary-700 disabled:bg-primary-400 text-white rounded-lg transition-all inline-flex items-center gap-2"
          >
            <svg v-if="submitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ mode === 'edit' ? 'حفظ التعديلات' : 'مشاركة الملف' }}
          </button>
        </div>
      </div>
    </template>
  </BaseDialog>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import BaseDialog from '@/Components/BaseDialog.vue'

const props = defineProps({
  modelValue: Boolean,
  patient: Object,
  existingShare: Object,
})

const emit = defineEmits(['update:modelValue', 'shared', 'updated'])

const { t } = useI18n()

const open = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val),
})

const searchInput = ref(null)
const searchQuery = ref('')
const searchResults = ref([])
const searched = ref(false)
const step = ref('search')
const selectedDoctor = ref(null)
const accessLevel = ref('read')
const submitting = ref(false)

let searchTimeout = null

const mode = computed(() => props.existingShare ? 'edit' : 'create')

watch(() => props.modelValue, (val) => {
  if (val) {
    step.value = 'search'
    searchQuery.value = ''
    searchResults.value = []
    searched.value = false
    selectedDoctor.value = null
    accessLevel.value = 'read'
    submitting.value = false

    if (props.existingShare) {
      selectedDoctor.value = props.existingShare.doctor
      accessLevel.value = props.existingShare.access_level || 'read'
      step.value = 'permission'
    }

    nextTick(() => {
      if (!props.existingShare) {
        searchInput.value?.focus()
      }
    })
  }
})

function onSearchInput() {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (searchQuery.value.length < 2) {
    searchResults.value = []
    searched.value = false
    return
  }
  searchTimeout = setTimeout(performSearch, 350)
}

async function performSearch() {
  const q = searchQuery.value.trim()
  if (q.length < 2) return
  searched.value = true
  try {
    const res = await axios.get('/api/v1/doctors/search', {
      params: { q },
    })
    searchResults.value = res.data || []
  } catch {
    searchResults.value = []
  }
}

function selectDoctor(doc) {
  selectedDoctor.value = doc
  step.value = 'permission'
}

async function submitShare() {
  if (!props.patient?.uuid || !selectedDoctor.value) return
  submitting.value = true
  try {
    await axios.post(`/api/v1/patients/${props.patient.uuid}/shares`, {
      doctor_id: selectedDoctor.value.id,
      access_level: accessLevel.value,
    })

    if (props.existingShare) {
      emit('updated')
    } else {
      emit('shared')
    }

    open.value = false
  } catch (e) {
    console.error('Share failed', e)
  } finally {
    submitting.value = false
  }
}
</script>
