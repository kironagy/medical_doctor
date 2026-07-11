<template>
  <div class="h-[100dvh] flex bg-slate-50 dark:bg-slate-950 overflow-hidden" :dir="isRtl ? 'rtl' : 'ltr'" @keydown.escape="closeAllMenus">
    <!-- Sidebar Overlay (Mobile) -->
    <div v-if="mobileDoctorListOpen && isMobile" class="fixed inset-0 bg-slate-900/50 z-30" @click="mobileDoctorListOpen = false"></div>

    <!-- Sidebar -->
    <div
      v-show="!isMobile || mobileDoctorListOpen"
      class="flex-shrink-0 z-100 transition-all duration-300"
      :class="[
        isMobile && mobileDoctorListOpen ? (isRtl ? 'fixed inset-y-0 right-0 w-full' : 'fixed inset-y-0 left-0 w-full') : '',
        !isMobile ? 'hidden md:block' : '',
        !isMobile && sidebarOpen ? 'w-[300px] lg:w-[320px]' : '',
        !isMobile && !sidebarOpen ? 'w-0 overflow-hidden' : ''
      ]"
    >
      <AdminSidebar
        :mobileOpen="mobileDoctorListOpen"
        :collapsed="!isMobile && !sidebarOpen"
        :doctors="doctors"
        @close="mobileDoctorListOpen = false"
        @add-doctor="showAddDoctor = true"
        @open-settings="openSettings"
        @select="handleSelectDoctor"
      />
    </div>

    <!-- Main Workspace -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Mobile Header (Only when no doctor selected) -->
      <header v-if="!selectedDoctor && isMobile" class="sticky top-0 z-30 bg-white/80 dark:bg-slate-950/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between px-3 h-14">
          <button @click="mobileDoctorListOpen = !mobileDoctorListOpen" class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
          </button>
          <h2 class="text-lg font-bold font-heading text-slate-900 dark:text-white">{{ $t('nav.doctors_dir') }}</h2>
          <div style="width: 36px;"></div>
        </div>
      </header>

      <!-- Scrollable Content -->
      <div ref="scrollContainer" class="flex-1 overflow-y-auto overscroll-contain" :class="isMobile ? 'pb-20' : ''">
        <!-- Loading Skeleton -->
        <div v-if="loadingDoctor" class="max-w-4xl mx-auto px-3 md:px-6 py-4 md:py-6 space-y-5">
          <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-5 animate-pulse">
            <div class="flex items-center gap-4">
              <div class="w-16 h-16 rounded-full bg-slate-200 dark:bg-slate-700"></div>
              <div class="flex-1 space-y-3">
                <div class="h-5 bg-slate-200 dark:bg-slate-700 rounded w-1/3"></div>
                <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-1/2"></div>
              </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 mt-4">
              <div v-for="i in 5" :key="i" class="h-12 bg-slate-200 dark:bg-slate-700 rounded-lg"></div>
            </div>
          </div>
          <div class="flex gap-2">
            <div v-for="i in 3" :key="i" class="h-9 w-24 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
          </div>
        </div>

        <!-- No Doctor Selected -->
        <div v-else-if="!selectedDoctor" class="flex-1 flex flex-col items-center justify-center bg-[#f0fcf9] dark:bg-slate-950 px-4 min-h-[85vh]">
          <div class="text-center flex flex-col items-center justify-center">
            <div class="w-60 h-60 text-[#79f3db] dark:text-teal-600 flex items-center justify-center">
              <svg viewBox="0 0 100 100" class="w-full h-full" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M35 48 V61 A 8 8 0 0 0 43 69 H56" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round" />
                <rect x="42" y="31" width="34" height="34" rx="9" fill="currentColor" />
                <path d="M52 48 H66 M59 41 V55" stroke="white" stroke-width="5" stroke-linecap="round" />
              </svg>
            </div>
            <h3 class="text-2xl md:text-3xl font-extrabold text-[#115e59] dark:text-teal-400 text-center leading-relaxed">
              قم باختيار طبيب من القائمة لعرض بياناته
            </h3>
            <button v-if="isMobile" @click="mobileDoctorListOpen = true" class="mt-6 px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold shadow-md transition-all">
              عرض قائمة الأطباء
            </button>
          </div>
        </div>

        <!-- Workspace Content -->
        <div v-else class="w-full">
          <!-- Mobile Back Button Header -->
          <div v-if="isMobile" class="sticky top-0 bg-white dark:bg-slate-900 border-b border-teal-100 dark:border-slate-850 z-30 px-4 py-3.5 flex items-center justify-center">
            <button @click="selectedDoctorId = null; mobileDoctorListOpen = true" class="flex items-center gap-2 text-teal-800 dark:text-teal-400 font-extrabold text-base hover:text-teal-900 transition-colors">
              <span>العودة لقائمة الأطباء</span>
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
              </svg>
            </button>
          </div>

          <div class="px-3 md:px-6 py-4 md:py-6 space-y-5">
            <!-- Doctor Summary Card -->
            <div class="mb-6">
              <DoctorSummary :doctor="currentDoctor" @edit="openEditDoctor" @delete="handleDelete" @suspend="handleSuspend" />
            </div>

            <!-- Statistics Block -->
            <div class="mb-6 space-y-4">
              <AdminStatsBlock
                title="إحصائيات الطبيب"
                :stats="doctorStats"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile Bottom Bar -->
    <MobileBottomBar
      v-if="!isMobile || !selectedDoctor"
      @toggle-patients="mobileDoctorListOpen = !mobileDoctorListOpen"
    />

    <!-- Modals -->
    <!-- Add Doctor Modal -->
    <WorkspaceModal :modelValue="showAddDoctor" @update:modelValue="showAddDoctor = false" :title="$t('doctors.add_new')" size="lg">
      <form @submit.prevent="submitAddDoctor" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.name') }}</label>
          <input v-model="addForm.name" type="text" class="input-field" required />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.email') }}</label>
          <input v-model="addForm.email" type="email" class="input-field" required />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.specialization') }}</label>
          <input v-model="addForm.specialization" type="text" class="input-field" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.phone') }}</label>
          <input v-model="addForm.phone" type="text" class="input-field" dir="ltr" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.password') }}</label>
          <input v-model="addForm.password" type="password" class="input-field" required minlength="8" autocomplete="new-password" />
        </div>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showAddDoctor = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
          <BaseButton type="submit" :loading="addingDoctor">{{ $t('common.save') }}</BaseButton>
        </div>
      </form>
    </WorkspaceModal>

    <!-- Edit Doctor Modal -->
    <WorkspaceModal :modelValue="showEditDoctor" @update:modelValue="showEditDoctor = false" :title="$t('common.edit')" size="lg">
      <form @submit.prevent="submitEditDoctor" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.name') }}</label>
          <input v-model="editForm.name" type="text" class="input-field" required />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.email') }}</label>
          <input v-model="editForm.email" type="email" class="input-field" required />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.specialization') }}</label>
          <input v-model="editForm.specialization" type="text" class="input-field" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('doctors.phone') }}</label>
          <input v-model="editForm.phone" type="text" class="input-field" dir="ltr" />
        </div>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showEditDoctor = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
          <BaseButton type="submit" :loading="editingDoctor">{{ $t('common.save') }}</BaseButton>
        </div>
      </form>
    </WorkspaceModal>

    <!-- Delete Confirmation -->
    <Teleport to="body">
      <div v-if="showDeleteConfirm" class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4" @click="showDeleteConfirm = false">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl max-w-md w-full p-6" @click.stop>
          <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">{{ $t('common.delete') }}</h3>
          <p class="text-slate-600 dark:text-slate-300 mb-6">{{ $t('doctors.delete_confirm', { name: currentDoctor?.name }) }}</p>
          <div class="flex gap-3 justify-end">
            <button @click="showDeleteConfirm = false" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 rounded-lg transition-colors">
              {{ $t('common.cancel') }}
            </button>
            <button @click="confirmDelete" class="px-4 py-2 text-sm font-medium bg-rose-600 hover:bg-rose-700 text-white rounded-lg transition-colors">
              {{ $t('common.delete') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Suspend/Activate Confirmation -->
      <div v-if="showSuspendConfirm" class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4" @click="showSuspendConfirm = false">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl max-w-md w-full p-6" @click.stop>
          <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">{{ suspendActionTitle }}</h3>
          <p class="text-slate-600 dark:text-slate-300 mb-6">{{ suspendConfirmMessage }}</p>
          <div class="flex gap-3 justify-end">
            <button @click="showSuspendConfirm = false" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 rounded-lg transition-colors">
              {{ $t('common.cancel') }}
            </button>
            <button @click="confirmSuspend" :class="[
              'px-4 py-2 text-sm font-medium rounded-lg text-white',
              currentDoctor?.status === 'active' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'
            ]">
              {{ suspendActionTitle }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Settings Modal -->
    <SettingsModal v-model="showSettings" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AdminSidebar from '@/Components/admin/AdminSidebar.vue'
import DoctorSummary from '@/Components/admin/DoctorSummary.vue'
import AdminStatsBlock from '@/Components/admin/AdminStatsBlock.vue'
import MobileBottomBar from '@/Components/workspace/MobileBottomBar.vue'
import WorkspaceModal from '@/Components/workspace/WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import SettingsModal from '@/Components/workspace/SettingsModal.vue'

const { t, locale } = useI18n()
const isRtl = computed(() => locale.value === 'ar')

const props = defineProps({
  doctors: { type: Object, required: true }
})

// State
const selectedDoctorId = ref(null)
const loadingDoctor = ref(false)
const sidebarOpen = ref(true)
const mobileDoctorListOpen = ref(false)
const isMobile = ref(false)
const doctorDetails = ref(null) // Full doctor details when selected

// Modals
const showAddDoctor = ref(false)
const showEditDoctor = ref(false)
const showDeleteConfirm = ref(false)
const showSuspendConfirm = ref(false)
const showSettings = ref(false)

function openSettings() {
  showSettings.value = true
}

// Forms
const addForm = ref({ name: '', email: '', specialization: '', phone: '', password: '' })
const editForm = ref({ name: '', email: '', specialization: '', phone: '' })
const addingDoctor = ref(false)
const editingDoctor = ref(false)

// Computed
const selectedDoctor = computed(() => {
  if (!selectedDoctorId.value) return null
  // If we have detailed data from API, use that
  if (doctorDetails.value) return doctorDetails.value
  // Fall back to list data
  return props.doctors.data.find(d => d.id === selectedDoctorId.value) || null
})

const currentDoctor = computed(() => selectedDoctor.value || {})

const doctorStats = computed(() => {
  if (!selectedDoctor.value) return []
  return [
    { key: 'patients', label: 'عدد المرضى', value: selectedDoctor.value.patients_count || 0, icon: '👥' },
    { key: 'files', label: 'الملفات', value: 0, icon: '📁' },
    { key: 'notes', label: 'الملاحظات', value: 0, icon: '📝' },
    { key: 'visits', label: 'الزيارات', value: 0, icon: '📅' },
    { key: 'shares', label: 'المشاركات', value: 0, icon: '🔗' },
  ]
})

const suspendActionTitle = computed(() => {
  if (!currentDoctor.value) return ''
  return currentDoctor.value.status === 'active' ? t('doctors.suspend') : t('doctors.activate')
})

const suspendConfirmMessage = computed(() => {
  if (!currentDoctor.value) return ''
  const isSuspending = currentDoctor.value.status === 'active'
  return `${isSuspending ? t('doctors.confirm_suspend') : t('doctors.confirm_activate')} ${currentDoctor.value.name}؟`
})

// Watch mobile
window.addEventListener('resize', () => {
  isMobile.value = window.innerWidth < 768
})

onMounted(() => {
  if (isMobile.value && !selectedDoctorId.value) {
    mobileDoctorListOpen.value = true
  }
})

function closeAllMenus() {
  // close any open menus if needed
}

function openEditDoctor() {
  if (currentDoctor.value) {
    editForm.value = {
      name: currentDoctor.value.name,
      email: currentDoctor.value.email,
      specialization: currentDoctor.value.specialization || '',
      phone: currentDoctor.value.phone || ''
    }
    showEditDoctor.value = true
  }
}

function handleDelete() {
  showDeleteConfirm.value = true
}

async function confirmDelete() {
  if (!currentDoctor.value) return
  showDeleteConfirm.value = false
  router.delete(`/admin/doctors/${currentDoctor.value.id}`, {
    onSuccess: () => {
      selectedDoctorId.value = null
      doctorDetails.value = null
      router.reload({ preserveState: true })
    }
  })
}

function handleSuspend() {
  showSuspendConfirm.value = true
}

function confirmSuspend() {
  if (!currentDoctor.value) return
  showSuspendConfirm.value = false
  router.post(`/admin/doctors/${currentDoctor.value.id}/suspend`, {}, {
    onSuccess: () => {
      router.reload({ preserveState: true })
    }
  })
}

function submitAddDoctor() {
  addingDoctor.value = true
  const payload = { ...addForm.value }
  router.post('/admin/doctors', payload, {
    onSuccess: () => {
      showAddDoctor.value = false
      addForm.value = { name: '', email: '', specialization: '', phone: '', password: '' }
      addingDoctor.value = false
    },
    onError: () => {
      addingDoctor.value = false
    }
  })
}

async function handleSelectDoctor(id) {
  selectedDoctorId.value = id
  loadingDoctor.value = true
  try {
    const response = await fetch(`/api/v1/admin/doctors/${id}`, {
      headers: {
        'Accept': 'application/json'
      }
    })
    if (response.ok) {
      const data = await response.json()
      doctorDetails.value = data.doctor
    }
  } catch (error) {
    console.error('Failed to fetch doctorDetails:', error)
  } finally {
    loadingDoctor.value = false
  }
}

function submitEditDoctor() {
  if (!currentDoctor.value) return
  editingDoctor.value = true
  const payload = { ...editForm.value }
  router.put(`/admin/doctors/${currentDoctor.value.id}`, payload, {
    onSuccess: () => {
      showEditDoctor.value = false
      editingDoctor.value = false
    },
    onError: () => {
      editingDoctor.value = false
    }
  })
}

function updateIsMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  updateIsMobile()
  window.addEventListener('resize', updateIsMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', updateIsMobile)
})
</script>
