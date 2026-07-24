import { router } from "@inertiajs/vue3";
import axios from "axios";
import { computed, ref, shallowRef } from "vue";

const trace = (msg) => { try { fetch('/_native/api/debug/trace', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({message:msg})}).catch(()=>{}); } catch(e) {} };
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
    let list = patients.value.filter(p => (p.sync_status ?? 'synced') !== 'pending_delete');
    if (!searchQuery.value) return list;
    const q = searchQuery.value.toLowerCase();
    return list.filter(
        (p) =>
            p.name?.toLowerCase().includes(q) ||
            (p.phone && p.phone.toLowerCase().includes(q)) ||
            (p.code && p.code.toLowerCase().includes(q)) ||
            (p.uuid && p.uuid.toLowerCase().includes(q)),
    );
});

const pendingSyncPatients = computed(() => {
    return patients.value.filter(p => p.sync_status && p.sync_status !== 'synced');
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

    // ── Phase 7: Rehydrate offline pending uploads after workspace loads ──
    // This ensures pending uploads survive app restart, process death,
    // WebView recreation, and offline launch.
    try {
        const offlineRes = await axios.get(`/_native/api/offline/uploads`, {
            params: { patient_uuid: uuid },
        });
        const pendingFiles = offlineRes.data?.data || [];
        if (pendingFiles.length > 0) {
            for (const entry of pendingFiles) {
                if (entry.sync_status === 'synced') continue;
                const type = entry.mime_type?.startsWith('image/')
                    ? 'image'
                    : entry.mime_type?.startsWith('video/')
                        ? 'video'
                        : entry.mime_type?.startsWith('audio/')
                            ? 'audio'
                            : entry.mime_type === 'application/pdf'
                                ? 'pdf'
                                : 'document';
                addFileLocally({
                    uuid:          entry.uuid,
                    patient_id:    entry.patient_uuid || uuid,
                    title:         entry.original_name || '',
                    file_name:     entry.original_name || '',
                    mime_type:     entry.mime_type || null,
                    extension:     entry.extension || null,
                    size:          parseInt(entry.size) || 0,
                    type:          type,
                    sync_status:   entry.sync_status || 'pending_upload',
                    local_path:    entry.local_path || null,
                    upload_status: entry.sync_status || 'pending_upload',
                    created_at:    entry.created_at || new Date().toISOString(),
                    updated_at:    entry.updated_at || new Date().toISOString(),
                });
            }
        }
    } catch (e) {
        // Silently fail — offline API may be unreachable (e.g. first load while online)
        // This is expected and non-fatal.
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
    trace('[TRACE_P3] useWorkspace.addPatient() ENTERED')
    loading.value = true;
    try {
        const online = typeof navigator !== 'undefined' ? navigator.onLine : true;
        let res;
        if (online) {
            const token = localStorage.getItem('np_api_token');
            trace('[TRACE_P4a] ONLINE - POST to production API')
            res = await axios.post('/api/v1/mobile/patients', formData, {
                headers: token ? { Authorization: 'Bearer ' + token } : {},
            });
        } else {
            trace('[TRACE_P4b] OFFLINE - POST to embedded Laravel')
            res = await axios.post('/api/v1/workspace/patients', formData);
        }
        trace('[TRACE_P8] axios.post response status: ' + res.status + ' data: ' + JSON.stringify(res.data).substring(0, 500))
        const patient = res.data?.patient || res.data;
        console.log('[DIAG] addPatient - patient.uuid:', patient?.uuid, 'has keys:', Object.keys(patient || {}).join(','))
        if (patient?.uuid) {
            console.log('[DIAG] addPatient - CALLING upsertPatient for uuid:', patient.uuid)
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
        const errData = e.response?.data || {};
        trace('[TRACE_P9] addPatient() CATCH - status: ' + e.response?.status + ' data: ' + JSON.stringify(errData).substring(0,500) + ' message: ' + e.message)
        console.error('[DIAG] addPatient FAILED:', { status: e.response?.status, data: errData, message: e.message, code: e.code });
        const enhancedErrors = {
            ...(errData.errors || {}),
            _server_error: errData.error_detail || errData.message || e.message,
            _error_id: errData.error_id || null,
        };
        return { success: false, errors: enhancedErrors, errorId: errData.error_id };
    } finally {
        loading.value = false;
    }
}

async function updatePatient(uuid, formData) {
    loading.value = true;
    try {
        const online = typeof navigator !== 'undefined' ? navigator.onLine : true;
        if (online) {
            const token = localStorage.getItem('np_api_token');
            await axios.put(`/api/v1/mobile/patients/${uuid}`, formData, {
                headers: token ? { Authorization: 'Bearer ' + token } : {},
            });
        } else {
            await axios.put(`/api/v1/workspace/patients/${uuid}`, formData);
        }
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

async function refreshPatientList(page = 1) {
    loadingPatients.value = true;
    try {
        // ═══════════════════════════════════════════════════════════════
        //  STEP 1: Upload pending local patients to server
        // ═══════════════════════════════════════════════════════════════
        // The phone's embedded Laravel has pending patients in local SQLite
        // (sync_status = pending_create / pending_update). They must be
        // uploaded to the production server BEFORE we fetch the patient list.
        // Without this step, the server doesn't know about them and returns
        // an incomplete list, causing pending patients to disappear.
        try {
            const syncRes = await axios.post('/_native/api/sync/patients', {}, { timeout: 60000 });
            console.log('[Sync] Pending patients synced:', syncRes.data?.message);
        } catch (syncErr) {
            // Expected when online — request goes EXTERNAL, not LOCAL.
            // The _native/* routes go LOCAL even when online, so this
            // should work. Log but don't block the refresh.
            console.log('[Sync] Sync endpoint note:', syncErr.message);
        }

        // ═══════════════════════════════════════════════════════════════
        //  STEP 2: Load pending patients from local SQLite
        // ═══════════════════════════════════════════════════════════════
        // After app restart, patients.value is empty. The merge logic below
        // checks patients.value for pending patients — but they're empty on
        // restart. We need to query the local SQLite to find them.
        let sqlitePending = [];
        try {
            const pendingRes = await axios.get('/_native/api/patients/pending');
            sqlitePending = pendingRes.data?.data || [];
            if (sqlitePending.length > 0) {
                console.log('[Sync] Loaded', sqlitePending.length, 'pending patients from local SQLite:', sqlitePending.map(p => p.name + '(' + (p.sync_status || '?') + ')').join(', '));
            }
        } catch (e) {
            // Local endpoint may be unavailable — fall through gracefully
            console.log('[Sync] Could not load pending from SQLite:', e.message);
        }

        // ═══════════════════════════════════════════════════════════════
        //  STEP 3: Fetch patient list from API
        // ═══════════════════════════════════════════════════════════════
        const url = "/api/v1/workspace/patients-list";
        const res = await axios.get(url, {
            params: { page },
        });
        const count = res.data?.data?.length || 0;
        const total = res.data?.meta?.total || 0;
        const firstUuid = res.data?.data?.[0]?.uuid || 'none';
        console.log('[DIAG] refreshPatientList - count:', count, 'total:', total, 'first uuid:', firstUuid, 'has pending_create:', res.data?.data?.some(p => p.sync_status === 'pending_create'))

        if (res.data?.data) {
            // ═══════════════════════════════════════════════════════════
            //  STEP 4: Merge — preserve pending local patients
            // ═══════════════════════════════════════════════════════════
            // Three sources of pending patients:
            //   1. patients.value — may have pending from current session
            //   2. sqlitePending — from local SQLite (survives app restart)
            //   3. API data — from the server
            //
            // After sync, some pending patients may have been uploaded and
            // are now included in the API response. They'll have sync_status
            // 'synced' from the server. Those that failed sync remain pending
            // locally and must be preserved.
            const apiUuids = new Set(res.data.data.map(p => p.uuid));

            // Collect all local pending patients from both sources
            const localPendingMap = new Map();

            // Source 1: Current session's patients.value
            for (const p of patients.value) {
                if (p.sync_status && p.sync_status !== 'synced' && !apiUuids.has(p.uuid)) {
                    localPendingMap.set(p.uuid, p);
                }
            }

            // Source 2: Local SQLite (survives restart)
            for (const p of sqlitePending) {
                if (p.sync_status !== 'synced' && !apiUuids.has(p.uuid)) {
                    if (!localPendingMap.has(p.uuid)) {
                        localPendingMap.set(p.uuid, p);
                    }
                }
            }

            const localPending = Array.from(localPendingMap.values());
            if (localPending.length > 0) {
                console.log('[DIAG] refreshPatientList - preserving', localPending.length, 'local pending patients not in API response:', localPending.map(p => p.name + '(' + p.sync_status + ')').join(', '));
            }

            patients.value = [...localPending, ...res.data.data];
            patientsMeta.value = res.data.meta;
        }
    } catch (e) {
        console.error("[PatientSidebar] Failed to refresh patient list", e);
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
        const count = res.data?.data?.length || 0;
        const total = res.data?.meta?.total || 0;
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

// ---------------------------------------------------------------
//  Phase 6 — Offline File Cache
// ---------------------------------------------------------------

/** Reactive set of cached file UUIDs — hydrated on demand. */
const cachedFiles = ref({}); // { [uuid]: true }

/**
 * Check whether a file is cached locally.
 * Falls back gracefully if the local cache server is unreachable.
 */
async function checkCacheStatus(fileUuid) {
    if (!fileUuid) return false;
    try {
        const res = await axios.get(`/_native/cache/files/${fileUuid}/status`);
        const isCached = res.data?.cached === true;
        cachedFiles.value[fileUuid] = isCached;
        // trigger reactivity
        cachedFiles.value = { ...cachedFiles.value };
        return isCached;
    } catch {
        return false;
    }
}

/**
 * Cache a file locally for offline viewing.
 * Returns { success, status }.
 */
async function cacheForOffline(fileUuid) {
    if (!fileUuid) return { success: false, message: 'Missing file UUID.' };
    try {
        const res = await axios.post(`/_native/cache/files/${fileUuid}/cache`);
        const success = res.data?.status === 'cached';
        if (success) {
            cachedFiles.value[fileUuid] = true;
            cachedFiles.value = { ...cachedFiles.value };
        }
        return { success, ...res.data };
    } catch (e) {
        const message = e.response?.data?.message || e.message || 'Cache request failed.';
        return { success: false, message };
    }
}

/**
 * Remove a single file from the local cache.
 */
async function removeFromCache(fileUuid) {
    if (!fileUuid) return;
    try {
        await axios.delete(`/_native/cache/files/${fileUuid}`);
        delete cachedFiles.value[fileUuid];
        cachedFiles.value = { ...cachedFiles.value };
    } catch {
        // silently ignore — cache miss is not a user-facing error
    }
}

/**
 * Remove all cached files for a patient.
 */
async function clearPatientCache(patientUuid) {
    if (!patientUuid) return;
    try {
        await axios.delete(`/_native/cache/patient/${patientUuid}`);
        // Re-check all currently tracked files
        for (const uuid of Object.keys(cachedFiles.value)) {
            cachedFiles.value[uuid] = false;
        }
        cachedFiles.value = { ...cachedFiles.value };
    } catch {
        // silently ignore
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
        pendingSyncPatients,
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
        // Phase 6 — Offline File Cache
        cachedFiles,
        checkCacheStatus,
        cacheForOffline,
        removeFromCache,
        clearPatientCache,
    };
}
