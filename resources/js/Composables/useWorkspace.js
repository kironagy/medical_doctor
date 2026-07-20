import { router } from "@inertiajs/vue3";
import axios from "axios";
import { computed, ref, shallowRef } from "vue";

const patients = ref([]);
const patientsMeta = ref(null);
const archivedPatients = ref([]);
const archivedPatientsMeta = ref(null);
const selectedPatientId = ref(null);
const workspaceData = shallowRef(null);
const loading = ref(false);
const loadingPatient = ref(false);
const loadingPatients = ref(false);
const loadingArchived = ref(false);
const searchQuery = ref("");
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
            // Close preview and stay on patient page
            showPreview.value = false;
            previewFile.value = null;
            previewSiblings.value = [];
            loadMoreSiblings.value = null;
        } else if (selectedPatientId.value) {
            // Close patient and go back to list
            selectedPatientId.value = null;
            workspaceData.value = null;
            mobilePatientListOpen.value = true;
            // Clear URL hash
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

// Share metadata — available when the current doctor is a guest (not primary)
const isShared = computed(() => workspaceData.value?.permissions?.is_shared ?? false);
const accessLevel = computed(() => workspaceData.value?.permissions?.access_level ?? 'write');
const sharedByName = computed(() => workspaceData.value?.permissions?.shared_by_name ?? null);
// Read-only: shared patients with access_level='read' must not show any write actions
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
        
        // Trigger background sync to fetch latest files for this patient
        console.log('[selectPatient] Triggering background sync for patient', uuid);
        try {
            await axios.post('/api/native/sync').catch(e => {
                console.warn('[selectPatient] Background sync warning:', e?.message || e);
            });
            console.log('[selectPatient] Background sync completed');
            
            // Refresh workspace data after sync to get latest files
            const refreshRes = await axios.get(`/api/v1/workspace/${uuid}`);
            if (refreshRes.data) {
                workspaceData.value = refreshRes.data;
                console.log('[selectPatient] Workspace data refreshed after sync');
            }
        } catch (syncErr) {
            console.warn('[selectPatient] Sync error (non-fatal):', syncErr?.message || syncErr);
        }
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
        window.history.back(); // Triggers popstate which closes it
    } else {
        showPreview.value = false;
        previewFile.value = null;
        previewSiblings.value = [];
        loadMoreSiblings.value = null;
    }
}

function closePatient() {
    if (typeof window !== "undefined" && window.location.hash === "#patient") {
        window.history.back(); // Triggers popstate which closes it
    } else {
        selectedPatientId.value = null;
        workspaceData.value = null;
        mobilePatientListOpen.value = true;
    }
}

function refreshWorkspaceData() {
    if (selectedPatientId.value) {
        loadingPatient.value = true;
        axios
            .get(`/api/v1/workspace/${selectedPatientId.value}`)
            .then((res) => {
                workspaceData.value = res.data;
            })
            .catch(() => {
                workspaceData.value = null;
            })
            .finally(() => {
                loadingPatient.value = false;
            });
    }
}

function syncWorkspaceStats(delta = 0) {
    if (!workspaceData.value?.stats) return;
    const nextStats = { ...workspaceData.value.stats };
    const candidates = ["total_files", "files_count", "files"];
    for (const key of candidates) {
        if (typeof nextStats[key] === "number") {
            nextStats[key] = Math.max(0, nextStats[key] + delta);
            break;
        }
    }
    workspaceData.value.stats = nextStats;
    workspaceData.value = { ...workspaceData.value };
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
        syncWorkspaceStats(1);
    } else {
        workspaceData.value.files[existingIndex] = {
            ...workspaceData.value.files[existingIndex],
            ...file,
        };
    }
    workspaceData.value = { ...workspaceData.value };
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
        workspaceData.value = { ...workspaceData.value };
    }
}

function removeFileLocally(fileUuid) {
    if (!workspaceData.value || !workspaceData.value.files) return;
    const before = workspaceData.value.files.length;
    workspaceData.value.files = workspaceData.value.files.filter(
        (f) => f.uuid !== fileUuid,
    );
    if (workspaceData.value.files.length < before) {
        syncWorkspaceStats(-1);
    }
    workspaceData.value = { ...workspaceData.value };
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

function reloadPatientData() {
    if (selectedPatientId.value) {
        selectPatient(selectedPatientId.value);
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
            upsertPatient(patient);
            selectedPatientId.value = patient.uuid;
            workspaceData.value = {
                ...(workspaceData.value || {}),
                patient,
                files: workspaceData.value?.files || [],
                notes: workspaceData.value?.notes || [],
                visits: workspaceData.value?.visits || [],
                shares: workspaceData.value?.shares || [],
                categories: workspaceData.value?.categories || [],
                stats: workspaceData.value?.stats || {},
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
    try {
        await axios.put(`/api/v1/workspace/patients/${uuid}`, formData);
        await refreshPatientList(patientsMeta.value?.current_page || 1);
        refreshWorkspaceData();
        return { success: true };
    } catch (e) {
        return { success: false, errors: e.response?.data?.errors || {} };
    } finally {
        loading.value = false;
    }
}

const showArchived = ref(false);
const authError = ref(null);

 async function refreshPatientList(page = 1) {
  loadingPatients.value = true;
  try {
    const url = "/api/v1/workspace/patients-list";
    console.log(`[refreshPatientList] Fetching: ${url}?page=${page}`);
    const res = await axios.get(url, {
      params: { page },
    });
    console.log(`[refreshPatientList] Response status: ${res.status}`);
    console.log(`[refreshPatientList] Response data keys: ${Object.keys(res.data || {}).join(', ')}`);
    
    const count = res.data?.data?.length || 0;
    const total = res.data?.meta?.total || 0;
    const listUuids = (res.data?.data || []).map(p => `${p.uuid}:${p.name}:${p.code}>`);
    console.log(
      `[refreshPatientList] GET ${url}?page=${page} | Status: ${res.status} | Patients: ${count} | Total: ${total}\n` +
      `[refreshPatientList] UUIDs on page: ${JSON.stringify(listUuids)}`
    );
    if (res.data?.data) {
      console.log(`[refreshPatientList] Setting patients.value = ${res.data.data.length} patients (was ${patients.value.length})`);
      patients.value = res.data.data;
      patientsMeta.value = res.data.meta;
      console.log(`[refreshPatientList] patients.value now has ${patients.value.length} patients`);
    } else {
      console.warn('[refreshPatientList] res.data?.data is falsy!', res.data);
    }
    if (res.data?.auth_error) {
      authError.value = res.data?.message || 'Session expired. Please login again.';
      console.warn("[refreshPatientList] Auth error:", authError.value);
    } else {
      authError.value = null;
    }
  } catch (e) {
    console.error("[refreshPatientList] Failed to refresh patient list", e);
  } finally {
    loadingPatients.value = false;
    console.log(`[refreshPatientList] Done. loadingPatients=false, patients count=${patients.value.length}`);
  }
}

async function fetchArchivedPatients(page = 1) {
    loadingArchived.value = true;
    try {
        const url = "/api/v1/workspace/patients-list";
        const res = await axios.get(url, {
            params: { status: "archived", page },
        });
        const count = res.data?.data?.length || 0;
        const total = res.data?.meta?.total || 0;
        console.log(`[PatientSidebar] GET ${url}?status=archived&page=${page} | Status: ${res.status} | Archived: ${count} | Total: ${total}`);
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
        authError,
    };
}
