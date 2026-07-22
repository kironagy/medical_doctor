import { router } from "@inertiajs/vue3";
import axios from "axios";
import { computed, ref, watch } from "vue";

const patients = ref([]);
const patientsMeta = ref(null);
const archivedPatients = ref([]);
const archivedPatientsMeta = ref(null);
const selectedPatientId = ref(null);
const workspaceData = ref(null);
const loading = ref(false);
const loadingPatient = ref(false);
const loadingPatients = ref(false);
const loadingArchived = ref(false);
const searchQuery = ref("");

// Debounced API search: when searchQuery changes with >= 2 chars,
// trigger a fresh API search across the entire dataset.
watch(searchQuery, () => {
  clearTimeout(window._searchDebounceTimer);
  window._searchDebounceTimer = setTimeout(() => {
    refreshPatientList(1).catch(() => {});
  }, 400);
});

const sidebarOpen = ref(
    typeof window !== "undefined"
        ? localStorage.getItem("sidebarOpen") !== "false"
        : true,
);
const mobilePatientListOpen = ref(false);
const activeSection = ref("overview");
const expandedCategories = ref({});
const previewFile = ref(null);
const showPreview = ref(false);
const isMobile = ref(typeof window !== "undefined" && window.innerWidth < 768);

const showAddPatient = ref(false);
const showEditPatient = ref(false);
const showCategoryManager = ref(false);
const showActionMenu = ref(false);
const showSettings = ref(false);

const lazyLoadedCategories = ref({});

/** Dedup guard for refreshPatientList — prevents parallel API calls */
let refreshPatientsInProgress = null;

/** Dedup guard for refreshWorkspaceData — prevents parallel API calls */
let refreshWorkspaceInProgress = null;

if (typeof window !== "undefined") {
    let resizeTimer;
    window.addEventListener("resize", () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            isMobile.value = window.innerWidth < 768;
        }, 100);
    });

    window.addEventListener("popstate", (e) => {
        if (showPreview.value) {
            showPreview.value = false;
            previewFile.value = null;
            previewSiblings.value = [];
            loadMoreSiblings.value = null;
        } else if (selectedPatientId.value) {
            selectedPatientId.value = null;
            workspaceData.value = null;
            mobilePatientListOpen.value = true;
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    });
}

const selectedPatient = computed(() => {
    if (!selectedPatientId.value) return null;
    return (
        patients.value.find((p) => p.uuid === selectedPatientId.value) ||
        archivedPatients.value.find(
            (p) => p.uuid === selectedPatientId.value,
        ) ||
        null
    );
});

const filteredPatients = computed(() => {
    if (!searchQuery.value) return patients.value;
    const q = searchQuery.value.toLowerCase();
    return patients.value.filter(
        (p) =>
            p.name?.toLowerCase().includes(q) ||
            (p.phone && p.phone.toLowerCase().includes(q)) ||
            (p.code && p.code.toLowerCase().includes(q)) ||
            (p.uuid && p.uuid.toLowerCase().includes(q)),
    );
});

const isPrimaryDoctor = computed(() => {
    if (!workspaceData.value) return false;
    return workspaceData.value.permissions?.is_primary;
});

const canEdit = computed(() => {
    if (!workspaceData.value) return false;
    return workspaceData.value.permissions?.can_edit;
});

const canDelete = computed(() => {
    if (!workspaceData.value) return false;
    return workspaceData.value.permissions?.can_delete;
});

const canShare = computed(() => {
    if (!workspaceData.value) return false;
    return workspaceData.value.permissions?.can_share;
});

const isShared = computed(() => workspaceData.value?.permissions?.is_shared ?? false);
const accessLevel = computed(() => workspaceData.value?.permissions?.access_level ?? 'write');
const sharedByName = computed(() => workspaceData.value?.permissions?.shared_by_name ?? null);
const isReadOnly = computed(() => isShared.value && accessLevel.value === 'read');

const categories = computed(() => {
    return workspaceData.value?.categories || [];
});

const allFiles = computed(() => {
    return workspaceData.value?.files || [];
});

const allNotes = computed(() => {
    return workspaceData.value?.notes || [];
});

const visits = computed(() => workspaceData.value?.visits || []);
const shares = computed(() => workspaceData.value?.shares || []);
const stats = computed(() => workspaceData.value?.stats || {});

function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value;
    if (typeof window !== "undefined") {
        localStorage.setItem("sidebarOpen", sidebarOpen.value);
    }
}

function toggleCategory(slug) {
    expandedCategories.value[slug] = !expandedCategories.value[slug];
}

function isCategoryExpanded(slug) {
    return expandedCategories.value[slug] !== false;
}

function markCategoryLoaded(slug) {
    lazyLoadedCategories.value[slug] = true;
}

function isCategoryLoaded(slug) {
    return lazyLoadedCategories.value[slug] === true;
}

function setPatients(patientList) {
    patients.value = patientList;
}

async function selectPatient(uuid) {
    if (!uuid) return;
    
    if (typeof window !== "undefined" && !selectedPatientId.value) {
        window.history.pushState({ view: "patient", uuid }, "", "#patient");
    }
    
    showSettings.value = false;
    selectedPatientId.value = uuid;
    loadingPatient.value = true;
    expandedCategories.value = {};
    lazyLoadedCategories.value = {};
    try {
        const res = await axios.get(`/api/v1/workspace/${uuid}`);
        workspaceData.value = res.data;
        const cats = res.data.categories || [];
        cats.forEach((c) => {
            expandedCategories.value[c.slug] = true;
        });
    } catch (e) {
        console.error("Failed to load patient data", e);
        workspaceData.value = null;
    } finally {
        loadingPatient.value = false;
    }
}

const previewSiblings = ref([]);
const loadMoreSiblings = ref(null);

function openPreview(file, siblings = [], loadMoreFn = null) {
    if (typeof window !== "undefined") {
        window.history.pushState({ view: "preview" }, "", "#preview");
    }
    previewFile.value = file;
    previewSiblings.value = siblings;
    loadMoreSiblings.value = loadMoreFn;
    showPreview.value = true;
}

function closePreview() {
    if (typeof window !== "undefined" && window.location.hash === "#preview") {
        window.history.back();
    } else {
        showPreview.value = false;
        previewFile.value = null;
        previewSiblings.value = [];
        loadMoreSiblings.value = null;
    }
}

function closePatient() {
    if (typeof window !== "undefined" && window.location.hash === "#patient") {
        window.history.back();
    } else {
        selectedPatientId.value = null;
        workspaceData.value = null;
        mobilePatientListOpen.value = true;
    }
}

function refreshWorkspaceData() {
    if (refreshWorkspaceInProgress) {
        return refreshWorkspaceInProgress;
    }

    if (!selectedPatientId.value) {
        return Promise.resolve();
    }

    const patientUuid = selectedPatientId.value;
    loadingPatient.value = true;

    refreshWorkspaceInProgress = new Promise(function(resolve) {
        axios.get(`/api/v1/workspace/${patientUuid}`).then(function(response) {
            if (selectedPatientId.value !== patientUuid) { resolve(); return; }
            workspaceData.value = response.data;
            resolve();
        }).catch(function(e) {
            if (selectedPatientId.value === patientUuid) {
                if (e?.response?.status === 404) {
                    workspaceData.value = null;
                }
            }
            resolve();
        }).finally(function() {
            if (selectedPatientId.value === patientUuid) {
                loadingPatient.value = false;
            }
            refreshWorkspaceInProgress = null;
        });
    });

    return refreshWorkspaceInProgress;
}

function addFileLocally(file) {
    if (!file?.uuid) return;
    if (!workspaceData.value) {
        workspaceData.value = {
            files: [file],
            notes: [],
            visits: [],
            shares: [],
            categories: [],
            stats: {},
        };
        return;
    }
    if (!workspaceData.value.files) workspaceData.value.files = [];
    const existingIndex = workspaceData.value.files.findIndex(
        (f) => f.uuid === file.uuid,
    );
    if (existingIndex === -1) {
        workspaceData.value.files = [file, ...workspaceData.value.files];
    } else {
        workspaceData.value.files[existingIndex] = {
            ...workspaceData.value.files[existingIndex],
            ...file,
        };
    }
}

function updateFileLocally(updatedFile) {
    if (!workspaceData.value || !workspaceData.value.files) return;
    const idx = workspaceData.value.files.findIndex(
        (f) => f.uuid === updatedFile.uuid,
    );
    if (idx !== -1) {
        workspaceData.value.files[idx] = {
            ...workspaceData.value.files[idx],
            ...updatedFile,
        };
    }
}

function addNoteLocally(note) {
    if (!note?.uuid) return;
    if (!workspaceData.value) {
        workspaceData.value = { notes: [note], files: [], visits: [], shares: [], categories: [], stats: {} };
        return;
    }
    if (!workspaceData.value.notes) workspaceData.value.notes = [];
    const existingIndex = workspaceData.value.notes.findIndex(
        (n) => n.uuid === note.uuid,
    );
    if (existingIndex === -1) {
        workspaceData.value.notes = [note, ...workspaceData.value.notes];
    } else {
        workspaceData.value.notes[existingIndex] = {
            ...workspaceData.value.notes[existingIndex],
            ...note,
        };
    }
}

function removeFileLocally(fileUuid) {
    if (!workspaceData.value || !workspaceData.value.files) return;
    workspaceData.value.files = workspaceData.value.files.filter(
        (f) => f.uuid !== fileUuid,
    );
}

function upsertPatient(patient) {
    if (!patient?.uuid) return;
    const existingIndex = patients.value.findIndex(
        (p) => p.uuid === patient.uuid,
    );
    if (existingIndex === -1) {
        patients.value = [patient, ...patients.value];
        if (patientsMeta.value?.total !== undefined) {
            patientsMeta.value = {
                ...patientsMeta.value,
                total: Math.max(0, patientsMeta.value.total + 1),
            };
        }
    } else {
        patients.value[existingIndex] = {
            ...patients.value[existingIndex],
            ...patient,
        };
    }
}

function navigateTo(path) {
    mobilePatientListOpen.value = false;
    router.visit(path);
}

async function addPatient(formData) {
    loading.value = true;
    try {
        const res = await axios.post("/api/v1/workspace/patients", formData);
        const patient = res.data?.patient || res.data;
        if (patient?.uuid) {
            // API-first: add the patient to the reactive list immediately
            upsertPatient(patient);
            selectedPatientId.value = patient.uuid;
            workspaceData.value = {
                ...(workspaceData.value || {}),
                patient,
                files: [],
                notes: [],
                visits: [],
                shares: [],
                categories: workspaceData.value?.categories || [],
                stats: { total_files: 0, total_notes: 0, total_visits: 0 },
            };
        }
        return { success: true, patient };
    } catch (e) {
        return { success: false, errors: e.response?.data?.errors || {} };
    } finally {
        loading.value = false;
    }
}

async function updatePatient(uuid, formData) {
    loading.value = true;

    const preUpdateIdx = patients.value.findIndex((p) => p.uuid === uuid);
    const preUpdateSnapshot = preUpdateIdx !== -1
        ? { ...patients.value[preUpdateIdx] }
        : null;

    if (preUpdateSnapshot) {
        patients.value[preUpdateIdx] = { ...patients.value[preUpdateIdx], ...formData };
    }
    if (workspaceData.value && workspaceData.value.patient?.uuid === uuid) {
        workspaceData.value = {
            ...workspaceData.value,
            patient: { ...workspaceData.value.patient, ...formData },
        };
    }

    try {
        await axios.put(`/api/v1/workspace/patients/${uuid}`, formData);
        return { success: true };
    } catch (e) {
        if (preUpdateSnapshot && preUpdateIdx !== -1) {
            patients.value[preUpdateIdx] = preUpdateSnapshot;
        }
        if (workspaceData.value && workspaceData.value.patient?.uuid === uuid && preUpdateSnapshot) {
            workspaceData.value = {
                ...workspaceData.value,
                patient: preUpdateSnapshot,
            };
        }
        return { success: false, errors: e.response?.data?.errors || {} };
    } finally {
        loading.value = false;
    }
}

const showArchived = ref(false);
const authError = ref(null);

async function refreshPatientList(page = 1) {
  if (refreshPatientsInProgress) {
    return refreshPatientsInProgress;
  }

  refreshPatientsInProgress = (async () => {
    loadingPatients.value = true;
    try {
      const url = "/api/v1/workspace/patients-list";
      const params = { page };
      if (searchQuery.value && searchQuery.value.length >= 2) {
        params.search = searchQuery.value;
      }
      const res = await axios.get(url, { params });
    
      let serverPatients = null;
      if (Array.isArray(res.data)) {
        serverPatients = res.data;
      } else if (res.data?.data && Array.isArray(res.data.data)) {
        serverPatients = res.data.data;
      } else if (res.data?.patients && Array.isArray(res.data.patients)) {
        serverPatients = res.data.patients;
      }
      
      if (serverPatients) {
        patients.value = serverPatients;
        const meta = res.data?.meta || {};
        patientsMeta.value = { ...meta };
      }
      if (res.data?.auth_error) {
        authError.value = res.data?.message || 'Session expired. Please login again.';
      } else {
        authError.value = null;
      }
    } catch (e) {
      console.error("[refreshPatientList] Failed to refresh patient list", e);
    } finally {
      loadingPatients.value = false;
    }
  })();

  try {
    return await refreshPatientsInProgress;
  } finally {
    refreshPatientsInProgress = null;
  }
}

async function fetchArchivedPatients(page = 1) {
    loadingArchived.value = true;
    try {
        const url = "/api/v1/workspace/patients-list";
        const res = await axios.get(url, {
            params: { status: "archived", page },
        });
        if (res.data?.data) {
            archivedPatients.value = res.data.data;
            archivedPatientsMeta.value = res.data.meta;
        }
    } catch (e) {
        console.error("[PatientSidebar] Failed to fetch archived patients", e);
    } finally {
        loadingArchived.value = false;
    }
}

async function archivePatient(uuid) {
    try {
        await axios.delete(`/api/v1/workspace/patients/${uuid}`);
        selectedPatientId.value = null;
        workspaceData.value = null;
        expandedCategories.value = {};
        await refreshPatientList(patientsMeta.value?.current_page || 1);
        await fetchArchivedPatients(
            archivedPatientsMeta.value?.current_page || 1,
        );
        return { success: true };
    } catch (e) {
        return { success: false };
    }
}

async function restorePatient(uuid) {
    try {
        await axios.post(`/api/v1/workspace/patients/${uuid}/restore`);
        await refreshPatientList(patientsMeta.value?.current_page || 1);
        await fetchArchivedPatients(
            archivedPatientsMeta.value?.current_page || 1,
        );
        return { success: true };
    } catch (e) {
        return { success: false };
    }
}

async function forceDeletePatient(uuid) {
    try {
        await axios.delete(`/api/v1/workspace/patients/${uuid}/force`);
        await fetchArchivedPatients(
            archivedPatientsMeta.value?.current_page || 1,
        );
        return { success: true };
    } catch (e) {
        return { success: false };
    }
}

function openSettings() {
    showSettings.value = true;
}

function closeSettings() {
    showSettings.value = false;
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
        previewSiblings,
        loadMoreSiblings,
        showPreview,
        isMobile,
        isPrimaryDoctor,
        canEdit,
        canDelete,
        canShare,
        isShared,
        accessLevel,
        sharedByName,
        isReadOnly,
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
        closePatient,
        refreshWorkspaceData,
        addFileLocally,
        addNoteLocally,
        updateFileLocally,
        removeFileLocally,
        navigateTo,
        addPatient,
        updatePatient,
        archivePatient,
        refreshPatientList,
        showArchived,
        fetchArchivedPatients,
        restorePatient,
        forceDeletePatient,
        authError,
    };
}
