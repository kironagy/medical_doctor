<template>
  <div class="h-[100dvh] flex bg-slate-50 dark:bg-slate-950 overflow-hidden" :dir="isRtl ? 'rtl' : 'ltr'" @keydown.escape="closeAllMenus" @touchstart="handleTouchStart" @touchmove="handleTouchMove" @touchend="handleTouchEnd" @touchcancel="handleTouchEnd">
    <!-- Sidebar Overlay (Mobile) -->
    <div v-if="mobilePatientListOpen && isMobile" class="fixed inset-0 bg-slate-900/50 z-30" @click="mobilePatientListOpen = false"></div>

    <!-- Sidebar -->
    <div
      v-show="!isMobile || mobilePatientListOpen"
      class="flex-shrink-0 z-100 transition-all duration-300"
      :class="[
        isMobile && mobilePatientListOpen ? (isRtl ? 'fixed inset-y-0 right-0 w-full' : 'fixed inset-y-0 left-0 w-full') : '',
        !isMobile ? 'hidden md:block' : '',
        !isMobile && sidebarOpen ? 'w-[300px] lg:w-[320px]' : '',
        !isMobile && !sidebarOpen ? 'w-0 overflow-hidden' : ''
      ]"
    >
      <PatientListSidebar
        :user="user"
        :mobileOpen="mobilePatientListOpen"
        :collapsed="!isMobile && !sidebarOpen"
        @close="mobilePatientListOpen = false"
        @add-patient="showAddPatient = true"
      />
    </div>

    <!-- Main Workspace -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Header -->
      <WorkspaceHeader
        v-if="!selectedPatient"
        class="md:hidden"
        @toggle-patients="mobilePatientListOpen = !mobilePatientListOpen"
        @toggle-actions="toggleActionMenu"
      />

      <!-- Three-dot Dropdown -->
      <Teleport to="body">
        <div v-if="showActionMenu && selectedPatient" class="fixed inset-0 z-[200]" @click="showActionMenu = false"></div>
        <div
          v-if="showActionMenu && selectedPatient"
          class="fixed z-[200] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl py-1.5 min-w-[200px]"
          :style="actionMenuStyle"
        >
          <button v-if="!isReadOnly" @click="openEditPatient" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            {{ $t('workspace.edit_patient') }}
          </button>
          <button v-if="!isReadOnly" @click="openShareModal" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
            {{ $t('workspace.share') }}
          </button>
          <button @click="handlePrint" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
            {{ $t('workspace.print_record') }}
          </button>
          <button @click="handleExport" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
            {{ $t('workspace.export_pdf') }}
          </button>
          <button @click="handleDownloadFiles" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-3 3m0 0l-3-3m3 3V4" /></svg>
            {{ $t('workspace.download_files') || 'Download Files' }}
          </button>
          <template v-if="!isReadOnly">
            <hr class="my-1 border-slate-100 dark:border-slate-700" />
            <button v-if="selectedPatient?.status === 'archived'" @click="handleRestore" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition-colors text-start">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
              {{ $t('common.restore') }}
            </button>
            <button v-else @click="handleArchive" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-start">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
              {{ $t('workspace.archive') }}
            </button>
            <button v-if="selectedPatient?.status !== 'archived'" @click="handleDelete" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-start">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
              {{ $t('workspace.delete') }}
            </button>
          </template>
        </div>
      </Teleport>

      <!-- PTR Indicator (fixed at viewport top) -->
      <div v-if="ptrVisible" class="fixed left-0 right-0 flex flex-col items-center justify-center pointer-events-none z-50" style="top:0;height:64px;padding-top:8px" :style="{ transform: `translateY(${pullDistance - 64}px)` }">
        <div class="w-9 h-9 rounded-full bg-white dark:bg-slate-800 shadow-lg flex items-center justify-center text-primary-600 dark:text-primary-400" :style="{ transform: `scale(${ptrScale})`, opacity: ptrOpacity }">
          <svg v-if="!isRefreshing" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" opacity="0.15"/>
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :stroke-dasharray="`${ptrArcLen} ${ptrCircumference}`" transform="rotate(-90 12 12)"/>
            <g :style="{ transform: `rotate(${ptrArrowRotation}deg)`, transformOrigin: '12px 12px' }">
              <path d="M12 5 L12 17 M8 13 L12 17 L16 13" stroke-linejoin="round"/>
            </g>
          </svg>
          <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" class="animate-spin">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-dasharray="35 75" stroke-linecap="round" transform="rotate(-90 12 12)"/>
          </svg>
        </div>
        <span v-if="thresholdReached && !isRefreshing" class="text-xs font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap mt-0.5">{{ $t('workspace.release_to_refresh') || 'Release to refresh' }}</span>
        <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap mt-0.5">{{ $t('workspace.refreshing') || 'Refreshing' }}</span>
      </div>
      <!-- Scrollable Content -->
      <div ref="scrollContainer" class="flex-1 overflow-y-auto overscroll-contain" :class="isMobile ? 'pb-20' : ''" :style="ptrContentStyle">
        <!-- Loading Skeleton -->
        <div v-if="loadingPatient" class="max-w-4xl mx-auto px-3 md:px-6 py-4 md:py-6 space-y-5">
          <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-5 animate-pulse">
            <div class="flex items-center gap-4">
              <div class="w-16 h-16 rounded-full bg-slate-200 dark:bg-slate-700"></div>
              <div class="flex-1 space-y-3">
                <div class="h-5 bg-slate-200 dark:bg-slate-700 rounded w-1/3"></div>
                <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-1/2"></div>
              </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 mt-4">
              <div v-for="i in 15" :key="i" class="h-12 bg-slate-200 dark:bg-slate-700 rounded-lg"></div>
            </div>
          </div>
          <div class="flex gap-2">
            <div v-for="i in 6" :key="i" class="h-9 w-24 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
          </div>
          <div v-for="i in 3" :key="i" class="h-24 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
        </div>

        <!-- No Patient Selected -->
        <div v-else-if="!selectedPatient" class="flex-1 flex flex-col items-center justify-center bg-[#f0fcf9] dark:bg-slate-950 px-4 min-h-[85vh]">
          <div class="text-center flex flex-col items-center justify-center">
            <!-- Large Overlapping Sheets/Document Plus Icon -->
            <div class="w-60 h-60 text-[#79f3db] dark:text-teal-600 flex items-center justify-center">
              <svg viewBox="0 0 100 100" class="w-full h-full" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M35 48 V61 A 8 8 0 0 0 43 69 H56" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round" />
                <rect x="42" y="31" width="34" height="34" rx="9" fill="currentColor" />
                <path d="M52 48 H66 M59 41 V55" stroke="white" stroke-width="5" stroke-linecap="round" />
              </svg>
            </div>
            <!-- Heading -->
            <h3 class="text-2xl md:text-3xl font-extrabold text-[#115e59] dark:text-teal-400 text-center leading-relaxed">
              قم باختيار مريض من القائمة لعرض بياناته
            </h3>
            <!-- Mobile Toggle Button -->
            <button v-if="isMobile" @click="mobilePatientListOpen = true" class="mt-6 px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold shadow-md transition-all">
              عرض قائمة المرضى
            </button>
          </div>
        </div>

        <!-- Workspace Content -->
        <div v-else class="w-full">
          <!-- Mobile Back Button Header -->
          <div v-if="isMobile" class="sticky top-0 bg-white dark:bg-slate-900 border-b border-teal-100 dark:border-slate-850 z-30 px-4 py-3.5 flex items-center justify-center">
            <button @click="closePatient" class="flex items-center gap-2 text-teal-800 dark:text-teal-400 font-extrabold text-base hover:text-teal-900 transition-colors">
              <span>العودة لقائمة المرضى</span>
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
              </svg>
            </button>
          </div>

          <div class="px-3 md:px-6 py-4 md:py-6 space-y-5">
            <!-- Section 1: Patient Summary (Header) -->
            <div ref="summaryRef" class="workspace-section">
              <PatientSummary :patient="currentPatient" :isPrimaryDoctor="isPrimaryDoctor" @edit="openEditPatient" @delete="handleDelete" @share="showShareModal = true" @download="handleDownloadFiles" />
            </div>

            <!-- Section 2: Dynamic Categories -->
            <div ref="recordsRef" class="workspace-section space-y-4">
              <CategoryBlock
                v-for="cat in categories"
                :key="cat.slug"
                :slug="cat.slug"
                :name="cat.name"
                :icon="getCategoryIcon(cat.icon)"
                :color="cat.color || '#0d9488'"
                :allCategories="categories"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile Bottom Bar -->
    <MobileBottomBar
      v-if="!isMobile || !selectedPatient"
      @toggle-patients="mobilePatientListOpen = !mobilePatientListOpen"
      @scroll-to="scrollToSection"
    />

    <!-- Inline Preview -->
    <InlineFilePreview />

    <!-- Note Modal -->
    <WorkspaceModal :modelValue="showNoteModal" @update:modelValue="showNoteModal = false" :title="editingNote ? $t('workspace.edit_note') : $t('workspace.add_note')" size="sm">
      <form @submit.prevent="submitNoteForm" class="space-y-4">
        <textarea v-model="noteFormContent" class="input-field w-full" rows="4" :placeholder="$t('workspace.note_placeholder')" required></textarea>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showNoteModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
          <BaseButton type="submit">{{ editingNote ? $t('workspace.save') : $t('workspace.add_note') }}</BaseButton>
        </div>
      </form>
    </WorkspaceModal>

    <!-- Visit Modal -->
    <WorkspaceModal :modelValue="showAddVisit" @update:modelValue="closeVisitModal()" :title="editingVisit ? $t('workspace.edit_visit_title') : $t('workspace.add_visit_title')" size="sm">
      <form @submit.prevent="submitVisitForm" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('workspace.visit_type_label') }}</label>
          <input v-model="visitForm.visit_type" class="input-field" :placeholder="$t('workspace.visit_type_label')" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('workspace.visit_date_label') }} *</label>
          <input v-model="visitForm.visit_date" type="date" class="input-field" required />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('workspace.next_visit_date_label') }}</label>
          <input v-model="visitForm.next_visit_date" type="date" class="input-field" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('workspace.visit_reason') }}</label>
          <textarea v-model="visitForm.reason" class="input-field w-full" rows="3" :placeholder="$t('workspace.visit_reason')"></textarea>
        </div>
        <div class="flex justify-end gap-3">
          <button type="button" @click="closeVisitModal()" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
          <BaseButton type="submit" :loading="savingVisit">{{ $t('workspace.save') }}</BaseButton>
        </div>
      </form>
    </WorkspaceModal>

    <!-- Modals -->
    <AddPatientModal v-model="showAddPatient" @saved="onPatientSaved" />
    <EditPatientModal v-model="showEditPatient" :patient="currentPatient" @saved="onPatientUpdated" />
    <CategoryManagerModal v-model="showCategoryManager" @saved="onCategoriesUpdated" />
    <SharePatientModal
      v-model="showShareModal"
      :patient="currentPatient"
      :existingShare="editingShare"
      @shared="onShareCreated"
      @updated="onShareUpdated"
    />
    <SettingsModal v-model="showSettings" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import axios from 'axios'
import PatientListSidebar from '@/Components/workspace/PatientListSidebar.vue'
import WorkspaceHeader from '@/Components/workspace/WorkspaceHeader.vue'
import PatientSummary from '@/Components/workspace/PatientSummary.vue'
import QuickActions from '@/Components/workspace/QuickActions.vue'
import CategoryBlock from '@/Components/workspace/CategoryBlock.vue'
import MobileBottomBar from '@/Components/workspace/MobileBottomBar.vue'
import InlineFilePreview from '@/Components/workspace/InlineFilePreview.vue'
import AddPatientModal from '@/Components/workspace/AddPatientModal.vue'
import EditPatientModal from '@/Components/workspace/EditPatientModal.vue'
import CategoryManagerModal from '@/Components/workspace/CategoryManagerModal.vue'
import SettingsModal from '@/Components/workspace/SettingsModal.vue'
import SharePatientModal from '@/Components/workspace/SharePatientModal.vue'
import WorkspaceModal from '@/Components/workspace/WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import { usePullToRefresh } from '@/Composables/usePullToRefresh'
import { apiUrl, getApiConfig } from '@/Utils/api'

const props = defineProps({
  patients: Array,
  categories: Array,
  user: Object,
})

const {
selectedPatient,
setPatients,
selectedPatientId,
closePatient,
workspaceData,
loadingPatient,
isMobile,
sidebarOpen,
mobilePatientListOpen,
canShare,
canEdit,
allFiles,
allNotes,
visits,
shares,
stats,
categories,
isPrimaryDoctor,
isReadOnly,
isShared,
sharedByName,
openPreview,
refreshWorkspaceData,
showAddPatient,
showEditPatient,
showCategoryManager,
showActionMenu,
showSettings,
closeSettings,
expandedCategories,
selectPatient,
refreshPatientList,
toggleSidebar,
showArchived,
archivedPatients,
fetchArchivedPatients,
archivePatient,
restorePatient,
addNoteLocally,
} = useWorkspace()

const dialog = useDialog()
const toast = useToast()

const { t, locale } = useI18n()
const isRtl = computed(() => locale.value === 'ar')

const scrollContainer = ref(null)

const {
  pullDistance, pullProgress, isPulling, isRefreshing, thresholdReached,
  handleTouchStart, handleTouchMove, handleTouchEnd,
} = usePullToRefresh({
  scrollContainer,
  onRefresh: async () => {
    if (refreshPromise) return
    refreshPromise = (async () => {
      await refreshPatientList()
      await refreshWorkspaceData()
    })()
    try { await refreshPromise } finally { refreshPromise = null }
  },
})

const PTR_RADIUS = 12
const PTR_CIRCUMFERENCE = 2 * Math.PI * PTR_RADIUS

const ptrVisible = computed(() => pullDistance.value > 0 || isRefreshing.value)
const ptrScale = computed(() => 0.3 + pullProgress.value * 0.7)
const ptrOpacity = computed(() => Math.min(pullProgress.value * 2, 1))
const ptrArcLen = computed(() => pullProgress.value * PTR_CIRCUMFERENCE)
const ptrArrowRotation = computed(() => pullProgress.value * 180)
const ptrContentStyle = computed(() => ({
  transform: `translateY(${pullDistance.value}px)`,
  willChange: 'transform',
}))

let refreshPromise = null

onMounted(() => {
  // ── 🔥 FIX: Hydrate patients.value from Inertia props IMMEDIATELY ──
  // This MUST run BEFORE refreshPatientList() so offline-created patients
  // are visible on the FIRST render cycle. Without this, patients.value is
  // [] on mount and stays empty until the async API call completes.
  if (props.patients && props.patients.length > 0) {
    setPatients(props.patients);
    console.log('[Hydrate] Seeded', props.patients.length, 'patients from Inertia props (includes pending):',
      props.patients.filter(p => p.sync_status && p.sync_status !== 'synced').length, 'pending');
  }

  // Now refresh asynchronously — will merge with already-seeded data
  refreshPatientList()
  if (isMobile.value && !selectedPatientId.value) {
    mobilePatientListOpen.value = true
  }
})

const summaryRef = ref(null)
const actionsRef = ref(null)
const recordsRef = ref(null)
const appointmentsRef = ref(null)
const visitsRef = ref(null)
const notesRef = ref(null)
const sharingRef = ref(null)
const timelineRef = ref(null)
const timelineScrollRef = ref(null)
const archiveRef = ref(null)

// Lock body scroll when mobile sidebar is open
watch(mobilePatientListOpen, (isOpen) => {
  if (isOpen && isMobile.value) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})

onUnmounted(() => {
  document.body.style.overflow = ''
})

const showShareModal = ref(false)
const editingShare = ref(null)

const showNoteModal = ref(false)
const editingNote = ref(null)
const noteFormContent = ref('')

const showAddVisit = ref(false)
const savingVisit = ref(false)
const editingVisit = ref(null)
const visitForm = ref({ visit_type: '', visit_date: '', next_visit_date: '', reason: '' })

const actionMenuStyle = ref({})
const timelinePage = ref(1)
const timelineItems = ref([])
const timelineLoading = ref(false)
const timelineHasMore = ref(true)
const timelinePageSize = 20

const currentPatient = computed(() => {
  return workspaceData.value?.patient || selectedPatient.value || {}
})

const displayedTimeline = computed(() => {
  return timelineItems.value
})

function getCategoryIcon(icon) {
  const map = {
    folder: '📁', clipboard: '📋', scissors: '✂️', heart: '❤️',
    pill: '💊', beaker: '🔬', camera: '📷', calendar: '📅',
    document: '📄', chart: '📊', lab: '🧪', xray: '🔍',
  }
  return map[icon] || '📁'
}

function closeAllMenus() {
  showActionMenu.value = false
  showCategoryManager.value = false
}

function toggleActionMenu() {
  showActionMenu.value = !showActionMenu.value
  if (showActionMenu.value && selectedPatient.value) {
    nextTick(() => {
      const btn = document.querySelector('[data-action="actions"]')
      if (btn) {
        const rect = btn.getBoundingClientRect()
        const menuW = 200
        const menuH = 320
        let top = rect.bottom + 4
        let right = window.innerWidth - rect.right
        if (right + menuW > window.innerWidth) {
          right = Math.max(8, window.innerWidth - rect.left - menuW)
        }
        if (top + menuH > window.innerHeight - 8) {
          top = Math.max(8, rect.top - menuH - 4)
        }
        actionMenuStyle.value = {
          top: `${top}px`,
          right: `${right}px`,
        }
      }
    })
  }
}

const upcomingVisits = computed(() => {
  const today = new Date().toISOString().substring(0, 10)
  // Collect entries with next_visit_date in the future
  return visits.value
    .filter(v => v.next_visit_date && v.next_visit_date >= today)
    .sort((a, b) => new Date(a.next_visit_date) - new Date(b.next_visit_date))
})

const pastVisits = computed(() => {
  const today = new Date().toISOString().substring(0, 10)
  return visits.value.filter(v => !v.visit_date || v.visit_date.substring(0, 10) <= today)
})

function scrollToSection(section) {
  const map = {
    summary: summaryRef, actions: actionsRef,
    records: recordsRef, appointments: appointmentsRef,
    visits: visitsRef, notes: notesRef, sharing: sharingRef,
    timeline: timelineRef, archive: archiveRef,
  }
  const el = map[section]?.value
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
  showActionMenu.value = false
}

function openEditPatient() {
  showActionMenu.value = false
  showEditPatient.value = true
}

function handlePrint() {
  showActionMenu.value = false
  if (!selectedPatient.value?.uuid) return
  window.open(`/api/v1/workspace/${selectedPatient.value.uuid}/print`, '_blank')
}

function handleExport() {
  if (selectedPatient.value) {
    showActionMenu.value = false
    window.open(`/workspace/${selectedPatient.value.uuid}/export`, '_blank')
  }
}

async function handleDownloadFiles() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  
  try {
    const res = await axios.post(`/api/v1/workspace/${selectedPatient.value.uuid}/download-files`)
    const jobId = res.data.jobId
    
    toast.info('Preparing files for download...', { timeout: 3000 })
    
    const interval = setInterval(async () => {
      try {
        const statusRes = await axios.get(`/api/v1/workspace/downloads/${jobId}/status`)
        if (statusRes.data.status === 'completed') {
          clearInterval(interval)
          toast.success('Download started!')
          window.location.href = statusRes.data.url
        } else if (statusRes.data.status === 'error') {
          clearInterval(interval)
          toast.error(statusRes.data.message || 'Error creating zip')
        }
      } catch (err) {
        clearInterval(interval)
        toast.error('Error checking download status')
      }
    }, 2000)
  } catch (error) {
    toast.error('Failed to start download')
  }
}

function toggleShowArchived() {
  showArchived.value = !showArchived.value
  if (showArchived.value && archivedPatients.value.length === 0) {
    fetchArchivedPatients()
  }
}

async function handleArchive() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const uuid = selectedPatient.value.uuid
  const confirmed = await dialog.confirm({
    title: t('workspace.archive_patient_title'),
    message: t('workspace.archive_confirm'),
    confirmText: t('workspace.archive'),
    style: 'warning',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/workspace/patients/${uuid}`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    await refreshPatientList()
    await fetchArchivedPatients()
    if (patients.value.length > 0) {
      selectPatient(patients.value[0].uuid)
    }
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Archive failed', e)
    toast.error(t('common.error'))
  }
}

async function handleRestore() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const uuid = selectedPatient.value.uuid
  const confirmed = await dialog.confirm({
    title: t('common.restore'),
    message: t('workspace.restore_confirm'),
    confirmText: t('common.restore'),
    style: 'info',
  })
  if (!confirmed) return
  try {
    await axios.post(`/api/v1/workspace/patients/${uuid}/restore`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    await refreshPatientList()
    await fetchArchivedPatients()
    selectPatient(uuid)
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Restore failed', e)
    toast.error(t('common.error'))
  }
}

async function handleDelete() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const uuid = selectedPatient.value.uuid
  const confirmed = await dialog.confirm({
    title: t('workspace.delete_patient'),
    message: t('workspace.delete_confirm'),
    confirmText: t('common.delete'),
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/workspace/patients/${uuid}/force`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    await refreshPatientList()
    await fetchArchivedPatients()
    if (patients.value.length > 0) {
      selectPatient(patients.value[0].uuid)
    }
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Delete failed', e)
    toast.error(t('common.error'))
  }
}

function onPatientSaved(patient) {
  console.log('[DIAG] onPatientSaved called, patient.uuid:', patient?.uuid, 'has uuid:', !!patient?.uuid, 'patient keys:', Object.keys(patient || {}).join(','))
  showAddPatient.value = false
  if (patient?.uuid) {
    console.log('[DIAG] onPatientSaved - calling refreshPatientList + selectPatient for uuid:', patient.uuid)
    refreshPatientList()
    selectPatient(patient.uuid)
  } else {
    console.log('[DIAG] onPatientSaved - NO UUID! Patient keys:', Object.keys(patient || {}).join(','))
  }
}

function onPatientUpdated(patient) {
  showEditPatient.value = false
  refreshPatientList()
  if (patient?.uuid) {
    selectPatient(patient.uuid)
  }
}

function onCategoriesUpdated() {
  showCategoryManager.value = false
  refreshWorkspaceData()
}

const allTimelineEvents = computed(() => {
  const events = []
  const files = allFiles.value
  const notes = allNotes.value
  const vs = visits.value
  for (let i = 0; i < files.length; i++) {
    const f = files[i]
    events.push({ id: `file-${f.id}`, type: 'file', title: f.title || f.file_name || 'File uploaded', description: f.desc || '', date: f.created_at })
  }
  for (let i = 0; i < notes.length; i++) {
    const n = notes[i]
    events.push({ id: `note-${n.id}`, type: 'note', title: t('workspace.note_by_doctor', { doctor: n.author?.name || t('doctors.doctor') }), description: (typeof n.content === 'string' ? n.content.replace(/<[^>]*>/g, '') : '').substring(0, 80) || '', date: n.created_at })
  }
  for (let i = 0; i < vs.length; i++) {
    const v = vs[i]
    events.push({ id: `visit-${v.id}`, type: 'visit', title: `${t('workspace.visit')}: ${v.visit_type || t('workspace.visit')}`, description: v.reason || '', date: v.visit_date || v.created_at })
  }
  events.sort((a, b) => new Date(b.date) - new Date(a.date))
  return events
})

watch(allTimelineEvents, (events) => {
  timelineItems.value = events.slice(0, timelinePageSize)
  timelineHasMore.value = events.length > timelinePageSize
  timelinePage.value = 1
  timelineLoading.value = false
}, { immediate: true })

function onTimelineScroll() {
  const el = timelineScrollRef.value
  if (!el || timelineLoading.value || !timelineHasMore.value) return
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 100) {
    loadMoreTimeline()
  }
}

function loadMoreTimeline() {
  if (timelineLoading.value) return
  timelineLoading.value = true
  setTimeout(() => {
    const nextItems = allTimelineEvents.value.slice(timelinePage.value * timelinePageSize, (timelinePage.value + 1) * timelinePageSize)
    timelineItems.value = [...timelineItems.value, ...nextItems]
    timelinePage.value++
    timelineHasMore.value = timelineItems.value.length < allTimelineEvents.value.length
    timelineLoading.value = false
  }, 200)
}

function openShareModal() {
  editingShare.value = null
  showShareModal.value = true
}

function editSharePermission(share) {
  editingShare.value = share
  showShareModal.value = true
}

async function removeShare(share) {
  if (!selectedPatient.value) return
  const confirmed = await dialog.confirm({
    title: t('common.revoke'),
    message: t('patients.remove_access_confirm'),
    confirmText: t('common.revoke'),
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/shares/${share.id}`))
    refreshWorkspaceData()
    toast.success(t('patients.access_removed'))
  } catch (e) {
    console.error('Revoke failed', e)
    toast.error(t('common.error'))
  }
}

function onShareCreated() {
  showShareModal.value = false
  editingShare.value = null
  refreshWorkspaceData()
  toast.success(t('patients.shared_success'))
}

function onShareUpdated() {
  showShareModal.value = false
  editingShare.value = null
  refreshWorkspaceData()
  toast.success(t('patients.permission_updated'))
}

function editNote(note) {
  editingNote.value = note
  noteFormContent.value = note.content
  showNoteModal.value = true
}

async function deleteNote(note) {
  const confirmed = await dialog.confirm({
    title: t('workspace.delete_note'),
    message: t('workspace.delete_note_confirm'),
    confirmText: t('common.delete'),
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes/${note.uuid}`), getApiConfig())
    
    if (typeof navigator !== 'undefined' ? navigator.onLine : true) {
      axios.post('/_native/api/sync/engine').catch(() => {})
    }
    refreshWorkspaceData()
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Delete note failed', e)
    toast.error(t('common.error'))
  }
}

async function submitNoteForm() {
	if (!noteFormContent.value || !selectedPatient.value?.uuid) return
	try {
		if (editingNote.value) {
			const res = await axios.put(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes/${editingNote.value.uuid}`), {
				content: noteFormContent.value,
			}, getApiConfig())
			let updatedNote = res.data
			axios.post('/_native/api/sync/engine').catch(() => {})
			if (updatedNote?.uuid) addNoteLocally(updatedNote)
			toast.success(t('workspace.note_updated'))
		} else {
			const res = await axios.post(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`), {
				content: noteFormContent.value,
				// FIX-01: category must be sent so the note is stored under
				// the correct category slug. Without it, NoteController defaults
				// to 'notes' and CategoryBlock filters never match the note.
				category: 'notes',
			}, getApiConfig())
			let createdNote = res.data
			
			axios.post('/_native/api/sync/engine').catch(() => {})
			// ── Insert note into workspaceData IMMEDIATELY ──────────────
			// The note is saved in local SQLite with sync_status = 'pending_create'.
			// refreshWorkspaceData() fetches from the production server which
			// doesn't have the new note yet. Without addNoteLocally(), the note
			// would be erased by the production response overwriting workspaceData.
			if (createdNote?.uuid) addNoteLocally(createdNote)
			toast.success(t('workspace.note_added'))
		}
		showNoteModal.value = false
		editingNote.value = null
		noteFormContent.value = ''
		refreshWorkspaceData()

		// ── Trigger sync engine after note mutation ─────────────────────
		// Fire the sync engine in the background to push pending notes to
		// production. Don't await — the note is already visible locally
		// via addNoteLocally() above.
		axios.post('/_native/api/sync/engine', {}, { timeout: 120000 })
			.catch(() => {}); // fire-and-forget
	} catch (e) {
		console.error('Note save failed', e)
		toast.error(t('common.error'))
	}
}

function openAddNote() {
  editingNote.value = null
  noteFormContent.value = ''
  showNoteModal.value = true
}

function editVisit(visit) {
  editingVisit.value = visit
  visitForm.value = {
    visit_type: visit.visit_type || '',
    visit_date: visit.visit_date ? visit.visit_date.substring(0, 10) : '',
    next_visit_date: visit.next_visit_date ? visit.next_visit_date.substring(0, 10) : '',
    reason: visit.reason || '',
  }
  showAddVisit.value = true
}

function closeVisitModal() {
  showAddVisit.value = false
  editingVisit.value = null
  visitForm.value = { visit_type: '', visit_date: '', next_visit_date: '', reason: '' }
}

async function submitVisitForm() {
  if (!visitForm.value.visit_date || !selectedPatient.value?.uuid) return
  savingVisit.value = true
  try {
    const payload = {
      visit_type: visitForm.value.visit_type || t('workspace.visit'),
      visit_date: visitForm.value.visit_date,
      next_visit_date: visitForm.value.next_visit_date || null,
      reason: visitForm.value.reason || '',
    }
    if (editingVisit.value) {
      await axios.put(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/visits/${editingVisit.value.uuid}`), payload, getApiConfig())
      toast.success(t('workspace.visit_added'))
    } else {
      await axios.post(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/visits`), payload, getApiConfig())
      toast.success(t('workspace.visit_added'))
    }
    
    // FIX-02: Previous URL '/_native/api/sync' was removed in SYNC-005.
    // The correct endpoint is '/_native/api/sync/engine'.
    axios.post('/_native/api/sync/engine').catch(() => {})
    
    closeVisitModal()
    await refreshWorkspaceData()
  } catch (e) {
    console.error('Visit save failed', e)
    toast.error(t('common.error'))
  } finally {
    savingVisit.value = false
  }
}

let removeStart = null
let removeFinish = null

const handlePopState = (event) => {
  // Prevent back button from exiting if on patient page
  history.pushState(null, null, window.location.href)
}

onMounted(() => {
  history.pushState(null, null, window.location.href)
  window.addEventListener('popstate', handlePopState)

  performance.mark('vue-mount-start')

  try {
    const payloadSize = JSON.stringify(props).length
    console.log(`⏱️ Props Size: ${(payloadSize / 1024).toFixed(2)} KB`)
  } catch (e) {
    console.log('⏱️ Props Size: unable to stringify')
  }

  requestAnimationFrame(() => {
    performance.mark('vue-mount-end')
    performance.measure('Vue Hydration/Mount', 'vue-mount-start', 'vue-mount-end')
    console.log(`⏱️ Vue Mount Time: ${performance.getEntriesByName('Vue Hydration/Mount')[0].duration.toFixed(2)}ms`)
    performance.clearMarks()
    performance.clearMeasures()
  })

  removeStart = router.on('start', () => {
    performance.mark('inertia-nav-start')
  })

  removeFinish = router.on('finish', () => {
    performance.mark('inertia-nav-end')
    performance.measure('Inertia Navigation', 'inertia-nav-start', 'inertia-nav-end')
    const measures = performance.getEntriesByName('Inertia Navigation')
    if (measures.length > 0) {
      console.log(`⏱️ Inertia Nav Overhead: ${measures[measures.length - 1].duration.toFixed(2)}ms`)
    }
  })
})

onUnmounted(() => {
  closeAllMenus()
  showSettings.value = false
  window.removeEventListener('popstate', handlePopState)
  if (removeStart) removeStart()
  if (removeFinish) removeFinish()
})
</script>
