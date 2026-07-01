import { ref, computed } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'

const patients = ref([])
const selectedPatientId = ref(null)
const workspaceData = ref(null)
const loading = ref(false)
const loadingPatient = ref(false)
const searchQuery = ref('')
const sidebarOpen = ref(true)
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

const lazyLoadedCategories = ref({})

if (typeof window !== 'undefined') {
  window.addEventListener('resize', () => {
    isMobile.value = window.innerWidth < 768
  })
}

const selectedPatient = computed(() => {
  if (!selectedPatientId.value) return null
  return patients.value.find(p => p.uuid === selectedPatientId.value) || null
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

const filesByCategory = computed(() => {
  const map = {}
  for (const f of allFiles.value) {
    const cat = f.category || 'notes'
    if (!map[cat]) map[cat] = []
    map[cat].push(f)
  }
  return map
})

const notesByCategory = computed(() => {
  const map = {}
  for (const n of allNotes.value) {
    const cat = n.category || 'notes'
    if (!map[cat]) map[cat] = []
    map[cat].push(n)
  }
  return map
})

const visits = computed(() => workspaceData.value?.visits || [])
const shares = computed(() => workspaceData.value?.shares || [])
const stats = computed(() => workspaceData.value?.stats || {})

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
    await refreshPatientList()
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
    await refreshPatientList()
    refreshWorkspaceData()
    return { success: true }
  } catch (e) {
    return { success: false, errors: e.response?.data?.errors || {} }
  } finally {
    loading.value = false
  }
}

async function refreshPatientList() {
  try {
    const res = await axios.get('/api/v1/workspace/patients-list')
    if (res.data?.patients) {
      patients.value = res.data.patients
    }
  } catch {
    console.error('Failed to refresh patient list')
  }
}

async function archivePatient(uuid) {
  try {
    await axios.delete(`/patients/${uuid}`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    await refreshPatientList()
    return { success: true }
  } catch (e) {
    return { success: false }
  }
}

export function useWorkspace() {
  return {
    patients,
    selectedPatientId,
    selectedPatient,
    workspaceData,
    loading,
    loadingPatient,
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
    filesByCategory,
    notesByCategory,
    visits,
    shares,
    stats,
    showAddPatient,
    showEditPatient,
    showCategoryManager,
    showActionMenu,
    setPatients,
    selectPatient,
    toggleCategory,
    isCategoryExpanded,
    markCategoryLoaded,
    isCategoryLoaded,
    openPreview,
    closePreview,
    refreshWorkspaceData,
    reloadPatientData,
    navigateTo,
    addPatient,
    updatePatient,
    archivePatient,
    refreshPatientList,
  }
}
