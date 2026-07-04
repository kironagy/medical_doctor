import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, ref, shallowRef } from 'vue'

const patients = ref([])
const patientsMeta = ref(null)
const archivedPatients = ref([])
const archivedPatientsMeta = ref(null)
const selectedPatientId = ref(null)
const workspaceData = shallowRef(null)
const loading = ref(false)
const loadingPatient = ref(false)
const loadingPatients = ref(false)
const loadingArchived = ref(false)
const searchQuery = ref('')
const sidebarOpen = ref(typeof window !== 'undefined' ? localStorage.getItem('sidebarOpen') !== 'false' : true)
const mobilePatientListOpen = ref(false)
const activeSection = ref('overview')
const expandedCategories = ref({})
const previewFile = ref(null)
const showPreview = ref(false)
const isMobile = ref(typeof window !== 'undefined' && window.innerWidth < 768)

const showAddPatient = ref(false)
const showEditPatient = ref(false)
const showCategoryManager = ref(false)
const showActionMenu = ref(false)
const showSettings = ref(false)

const lazyLoadedCategories = ref({})

if (typeof window !== 'undefined') {
  let resizeTimer
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer)
    resizeTimer = setTimeout(() => {
      isMobile.value = window.innerWidth < 768
    }, 100)
  })
}

const selectedPatient = computed(() => {
  if (!selectedPatientId.value) return null
  return patients.value.find(p => p.uuid === selectedPatientId.value)
    || archivedPatients.value.find(p => p.uuid === selectedPatientId.value)
    || null
})

const filteredPatients = computed(() => {
  if (!searchQuery.value) return patients.value
  const q = searchQuery.value.toLowerCase()
  return patients.value.filter(p =>
    p.name?.toLowerCase().includes(q) ||
    (p.phone && p.phone.toLowerCase().includes(q)) ||
    (p.code && p.code.toLowerCase().includes(q)) ||
    (p.uuid && p.uuid.toLowerCase().includes(q))
  )
})

const isPrimaryDoctor = computed(() => {
  if (!workspaceData.value) return false
  return workspaceData.value.permissions?.is_primary
})

const canEdit = computed(() => {
  if (!workspaceData.value) return false
  return workspaceData.value.permissions?.can_edit
})

const canShare = computed(() => {
  if (!workspaceData.value) return false
  return workspaceData.value.permissions?.can_share
})

const categories = computed(() => {
  return workspaceData.value?.categories || []
})

const allFiles = computed(() => {
  return workspaceData.value?.files || []
})

const allNotes = computed(() => {
  return workspaceData.value?.notes || []
})

const visits = computed(() => workspaceData.value?.visits || [])
const shares = computed(() => workspaceData.value?.shares || [])
const stats = computed(() => workspaceData.value?.stats || {})

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
  if (typeof window !== 'undefined') {
    localStorage.setItem('sidebarOpen', sidebarOpen.value)
  }
}

function toggleCategory(slug) {
  expandedCategories.value[slug] = !expandedCategories.value[slug]
}

function isCategoryExpanded(slug) {
  return expandedCategories.value[slug] !== false
}

function markCategoryLoaded(slug) {
  lazyLoadedCategories.value[slug] = true
}

function isCategoryLoaded(slug) {
  return lazyLoadedCategories.value[slug] === true
}

function setPatients(patientList) {
  patients.value = patientList
}

async function selectPatient(uuid) {
  if (!uuid) return
  selectedPatientId.value = uuid
  loadingPatient.value = true
  expandedCategories.value = {}
  lazyLoadedCategories.value = {}
  try {
    const res = await axios.get(`/api/v1/workspace/${uuid}`)
    workspaceData.value = res.data
    const cats = res.data.categories || []
    cats.forEach(c => {
      expandedCategories.value[c.slug] = c.order <= 3
    })
  } catch (e) {
    console.error('Failed to load patient data', e)
    workspaceData.value = null
  } finally {
    loadingPatient.value = false
  }
}

function openPreview(file) {
  previewFile.value = file
  showPreview.value = true
}

function closePreview() {
  showPreview.value = false
  previewFile.value = null
}

function refreshWorkspaceData() {
  if (selectedPatientId.value) {
    loadingPatient.value = true
    axios.get(`/api/v1/workspace/${selectedPatientId.value}`)
      .then(res => { workspaceData.value = res.data })
      .catch(() => { workspaceData.value = null })
      .finally(() => { loadingPatient.value = false })
  }
}

function updateFileLocally(updatedFile) {
  if (!workspaceData.value || !workspaceData.value.files) return
  const idx = workspaceData.value.files.findIndex(f => f.uuid === updatedFile.uuid)
  if (idx !== -1) {
    workspaceData.value.files[idx] = { ...workspaceData.value.files[idx], ...updatedFile }
    workspaceData.value = { ...workspaceData.value }
  }
}

function removeFileLocally(fileUuid) {
  if (!workspaceData.value || !workspaceData.value.files) return
  workspaceData.value.files = workspaceData.value.files.filter(f => f.uuid !== fileUuid)
  workspaceData.value = { ...workspaceData.value }
}

function reloadPatientData() {
  if (selectedPatientId.value) {
    selectPatient(selectedPatientId.value)
  }
}

function navigateTo(path) {
  mobilePatientListOpen.value = false
  router.visit(path)
}

async function addPatient(formData) {
  loading.value = true
  try {
    const res = await axios.post('/api/v1/workspace/patients', formData)
    await refreshPatientList(1)
    if (res.data?.patient?.uuid) {
      selectPatient(res.data.patient.uuid)
    }
    return { success: true }
  } catch (e) {
    return { success: false, errors: e.response?.data?.errors || {} }
  } finally {
    loading.value = false
  }
}

async function updatePatient(uuid, formData) {
  loading.value = true
  try {
    await axios.put(`/api/v1/workspace/patients/${uuid}`, formData)
    await refreshPatientList(patientsMeta.value?.current_page || 1)
    refreshWorkspaceData()
    return { success: true }
  } catch (e) {
    return { success: false, errors: e.response?.data?.errors || {} }
  } finally {
    loading.value = false
  }
}

const showArchived = ref(false)

async function refreshPatientList(page = 1) {
  loadingPatients.value = true
  try {
    const res = await axios.get('/api/v1/workspace/patients-list', { params: { page } })
    if (res.data?.data) {
      patients.value = res.data.data
      patientsMeta.value = res.data.meta
    }
  } catch (e) {
    console.error('Failed to refresh patient list', e)
  } finally {
    loadingPatients.value = false
  }
}

async function fetchArchivedPatients(page = 1) {
  loadingArchived.value = true
  try {
    const res = await axios.get('/api/v1/workspace/patients-list', { params: { status: 'archived', page } })
    if (res.data?.data) {
      archivedPatients.value = res.data.data
      archivedPatientsMeta.value = res.data.meta
    }
  } catch (e) {
    console.error('Failed to fetch archived patients', e)
  } finally {
    loadingArchived.value = false
  }
}

async function archivePatient(uuid) {
  try {
    await axios.delete(`/api/v1/workspace/patients/${uuid}`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    await refreshPatientList(patientsMeta.value?.current_page || 1)
    await fetchArchivedPatients(archivedPatientsMeta.value?.current_page || 1)
    return { success: true }
  } catch (e) {
    return { success: false }
  }
}

async function restorePatient(uuid) {
  try {
    await axios.post(`/api/v1/workspace/patients/${uuid}/restore`)
    await refreshPatientList(patientsMeta.value?.current_page || 1)
    await fetchArchivedPatients(archivedPatientsMeta.value?.current_page || 1)
    return { success: true }
  } catch (e) {
    return { success: false }
  }
}

async function forceDeletePatient(uuid) {
  try {
    await axios.delete(`/api/v1/workspace/patients/${uuid}/force`)
    await fetchArchivedPatients(archivedPatientsMeta.value?.current_page || 1)
    return { success: true }
  } catch (e) {
    return { success: false }
  }
}

function openSettings() {
  showSettings.value = true
}

function closeSettings() {
  showSettings.value = false
}

export function useWorkspace() {
  return {
    patients,
    patientsMeta,
    archivedPatients,
    archivedPatientsMeta,
    selectedPatientId,
    selectedPatient,
    workspaceData,
    loading,
    loadingPatient,
    loadingPatients,
    loadingArchived,
    searchQuery,
    filteredPatients,
    sidebarOpen,
    mobilePatientListOpen,
    activeSection,
    expandedCategories,
    previewFile,
    showPreview,
    isMobile,
    isPrimaryDoctor,
    canEdit,
    canShare,
    categories,
    allFiles,
    allNotes,
    visits,
    shares,
    stats,
    showAddPatient,
    showEditPatient,
    showCategoryManager,
    showActionMenu,
    showSettings,
    openSettings,
    closeSettings,
    setPatients,
    selectPatient,
    toggleSidebar,
    toggleCategory,
    isCategoryExpanded,
    markCategoryLoaded,
    isCategoryLoaded,
    openPreview,
    closePreview,
    refreshWorkspaceData,
    updateFileLocally,
    removeFileLocally,
    reloadPatientData,
    navigateTo,
    addPatient,
    updatePatient,
    archivePatient,
    refreshPatientList,
    showArchived,
    fetchArchivedPatients,
    restorePatient,
    forceDeletePatient,
  }
}
