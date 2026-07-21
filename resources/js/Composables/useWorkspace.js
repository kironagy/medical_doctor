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

/** Tracks whether the initial background sync has completed at least once. */
const initialSyncDone = ref(false);

/** Dedup guard for syncAndRefresh — prevents parallel calls */
let syncInProgress = null;

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
        const patientUuid = selectedPatientId.value;
        loadingPatient.value = true;
        axios
            .get(`/api/v1/workspace/${patientUuid}`)
            .then((res) => {
                // Only update if still viewing the same patient
                if (selectedPatientId.value === patientUuid) {
                    // API-FIRST: Server response is authoritative when online.
                    // Directly set workspace data from the server response.
                    // Locally-created items not yet synced are preserved by the
                    // create flow which calls addNoteLocally()/addFileLocally()
                    // to insert them directly into workspaceData.value.
                    workspaceData.value = res.data;
                }
            })
            .catch((e) => {
                if (selectedPatientId.value === patientUuid) {
                    // Only clear on 404 (patient was deleted).
                    // Preserve current data on transient errors (timeout, network blip).
                    if (e?.response?.status === 404) {
                        console.warn('[refreshWorkspaceData] Patient not found (404), clearing data');
                        workspaceData.value = null;
                    } else {
                        console.warn('[refreshWorkspaceData] Transient error, preserving current data:', e?.message || e);
                    }
                }
            })
            .finally(() => {
                if (selectedPatientId.value === patientUuid) {
                    loadingPatient.value = false;
                }
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
    workspaceData.value = { ...workspaceData.value };
    console.log('[useWorkspace] Note added locally:', note.uuid);
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
            // OFFLINE-FIRST: Add the patient to the local list immediately.
            // The patient is already saved to local SQLite by the server.
            // Adding it to the reactive list ensures instant UI update.
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
            
            // Background: refresh the patient list from the API to ensure
            // the sidebar shows the latest data. The patient was just created
            // on the API synchronously, so it will be in the API response.
            refreshPatientList(patientsMeta.value?.current_page || 1).catch(() => {});
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
        // Update the local list without waiting for server refresh
        const localIdx = patients.value.findIndex((p) => p.uuid === uuid);
        if (localIdx !== -1) {
            patients.value[localIdx] = { ...patients.value[localIdx], ...formData };
        }
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

/**
 * SYNC-AND-REFRESH: Push pending changes, then refresh from the API.
 *
 * Sequence:
 *   1. POST /api/native/sync to push local changes + pull latest data
 *   2. Refresh patient list from the API (API-first)
 *   3. Refresh workspace data for the current patient (if any)
 *
 * Has built-in dedup: if a sync is already in progress, subsequent calls
 * wait for the in-progress one to finish and reuse its result.
 *
 * When offline: skips sync, refreshes patient list from SQLite directly.
 */
async function syncAndRefresh(page = 1) {
  // Dedup: if a sync is already in progress, return its promise
  if (syncInProgress) {
    return syncInProgress;
  }

  if (typeof navigator === 'undefined' || !navigator.onLine) {
    // Offline: just refresh from local SQLite
    return refreshPatientList(page);
  }

  syncInProgress = (async () => {
    try {
      await axios.post('/api/native/sync', {}, {
        headers: { 'Accept': 'application/json' },
        timeout: 30000, // 30s timeout for sync
      });
      console.log('[syncAndRefresh] Background sync completed');
    } catch (e) {
      console.warn('[syncAndRefresh] Background sync failed (non-fatal):', e?.message || e);
      // Continue to refresh from local SQLite even if sync failed
    }

    await refreshPatientList(page);

    // Also refresh workspace data if a patient is open
    if (selectedPatientId.value) {
      refreshWorkspaceData();
    }

    initialSyncDone.value = true;
  })();

  try {
    return await syncInProgress;
  } finally {
    syncInProgress = null;
  }
}

 async function refreshPatientList(page = 1) {
  loadingPatients.value = true;
  try {
    const url = "/api/v1/workspace/patients-list";
    const res = await axios.get(url, {
      params: { page },
    });
    
    const count = res.data?.data?.length || 0;
    const total = res.data?.meta?.total || 0;
    const listUuids = (res.data?.data || []).map(p => `${p.uuid}:${p.name}:${p.code}>`);
    console.log(
      `[refreshPatientList] GET ${url}?page=${page} | Status: ${res.status} | Patients: ${count} | Total: ${total}\n` +
      `[refreshPatientList] UUIDs on page: ${JSON.stringify(listUuids)}`
    );
    
    // DEBUG: Log response structure to identify format issues
    const respType = typeof res.data;
    const respIsArray = Array.isArray(res.data);
    const respKeys = res.data ? Object.keys(res.data).join(',') : 'null';
    console.log(`[refreshPatientList] Response type=${respType} isArray=${respIsArray} keys=[${respKeys}]`);
    
    // Determine the patients array from the response (handle multiple formats)
    let serverPatients = null;
    if (Array.isArray(res.data)) {
      // Response is a raw array of patients
      serverPatients = res.data;
    } else if (res.data?.data && Array.isArray(res.data.data)) {
      // Standard paginated format: { data: [...], meta: {...} }
      serverPatients = res.data.data;
    } else if (res.data?.patients && Array.isArray(res.data.patients)) {
      // Alternative format: { patients: [...] }
      serverPatients = res.data.patients;
    }
    
    if (serverPatients) {
      // API-FIRST: When online, the server response is authoritative.
      // Overwrite the patient list with the server data directly.
      // Patients saved locally but not yet synced will be re-added by
      // the create/update flow which calls upsertPatient().
      patients.value = serverPatients;
      
      const meta = res.data?.meta || {};
      patientsMeta.value = { ...meta };
      
      console.log(`[refreshPatientList] Set ${serverPatients.length} patients from server (API-first, no merge)`);
    } else {
      console.warn('[refreshPatientList] No patients array found in response. res.data keys:', respKeys, 'res.data:', res.data);
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
        syncAndRefresh,
        initialSyncDone,
    };
}
