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
// trigger a fresh API search across the entire dataset (not just loaded patients).
// Clears to page 1 on every new search query.
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

/** Tracks whether the initial background sync has completed at least once. */
const initialSyncDone = ref(false);

/** Dedup guard for syncAndRefresh — prevents parallel calls */
let syncInProgress = null;

/** Dedup guard for refreshPatientList — prevents parallel API calls */
let refreshPatientsInProgress = null;

/** Dedup guard for refreshWorkspaceData — prevents parallel API calls */
let refreshWorkspaceInProgress = null;

/** Set of patient UUIDs created locally (not yet confirmed in API refresh).
 *  Prevents refreshPatientList() from overwriting these patients out of the list.
 *  Capped at 100 entries to prevent unbounded memory growth. */
const locallyCreatedPatients = new Set();

/** Set of file UUIDs added locally (not yet confirmed by API workspace refresh).
 *  Prevents refreshWorkspaceData() from discarding these files.
 *  Capped at 100 entries to prevent unbounded memory growth. */
const locallyAddedFileUuids = new Set();

/** Set of note UUIDs added locally. Same protection as files.
 *  Capped at 100 entries to prevent unbounded memory growth. */
const locallyAddedNoteUuids = new Set();

/**
 * Cap a tracking Set at the given max size, removing oldest entries.
 * Prevents unbounded memory growth from records that permanently fail
 * to sync (so their UUID is never confirmed and removed from the Set).
 */
function capTrackingSet(set, maxSize = 100) {
    if (set.size >= maxSize) {
        const toDelete = [...set].slice(0, set.size - maxSize + 1);
        for (const uuid of toDelete) {
            set.delete(uuid);
        }
    }
}

// Listen for sync-completed events dispatched by app.js after background sync finishes.
// This ensures the Vue reactive state is refreshed whenever SQLite cache is updated.
// COORDINATED with syncAndRefresh/refreshPatientList dedup guards to prevent parallel writes.
if (typeof window !== "undefined") {
    window.addEventListener('sync-completed', () => {
        console.log('[useWorkspace] sync-completed event received — checking if refresh is needed');
        // Debounce: ignore rapid successive events (app.js fires this on every periodic sync)
        clearTimeout(window._syncRefreshTimer);
        window._syncRefreshTimer = setTimeout(() => {
            // SKIP if any refresh operation is already in progress.
            // syncAndRefresh() handles its own refresh internally, so we don't need to
            // duplicate the work here. This prevents race conditions where the sync-completed
            // event fires in the middle of a refresh cycle and overwrites state non-deterministically.
            if (syncInProgress || refreshPatientsInProgress || refreshWorkspaceInProgress) {
                console.log('[useWorkspace] sync-completed: SKIPPING refresh (sync/refresh already in progress)');
                return;
            }
            refreshPatientList(patientsMeta.value?.current_page || 1).catch(() => {});
            if (selectedPatientId.value) {
                refreshWorkspaceData();
            }
        }, 500);
    });

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
    console.log(`[WRITE] setPatients() wrote ${patientList.length} patients to patients.value at ${new Date().toISOString()}`);
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
        const serverData = res.data;
        const fileCount = serverData?.files?.length || 0;
        const noteCount = serverData?.notes?.length || 0;
        console.log(`[WRITE] selectPatient() API returned ${fileCount} files, ${noteCount} notes at ${new Date().toISOString()}`);
        
        // MERGE: Same protection as refreshWorkspaceData() — preserve locally-added
        // files and notes that the server doesn't know about yet.
        // This prevents selectPatient() from discarding items added via addFileLocally/addNoteLocally
        // when a user switches patients and comes back.
        if (workspaceData.value && workspaceData.value.patient?.uuid === uuid) {
            // Only merge if we already have this patient's workspace data (not a fresh load)
            if (locallyAddedFileUuids.size > 0 && serverData?.files) {
                const serverUuids = new Set(serverData.files.map(f => f.uuid));
                const localFiles = (workspaceData.value.files || []).filter(
                    f => f.uuid && !serverUuids.has(f.uuid) && locallyAddedFileUuids.has(f.uuid)
                );
                if (localFiles.length > 0) {
                    serverData.files = [...localFiles, ...serverData.files];
                    console.log(`[WRITE] selectPatient() merged ${localFiles.length} locally-added files (API-first, protected)`);
                }
            }
            if (locallyAddedNoteUuids.size > 0 && serverData?.notes) {
                const serverNoteUuids = new Set(serverData.notes.map(n => n.uuid));
                const localNotes = (workspaceData.value.notes || []).filter(
                    n => n.uuid && !serverNoteUuids.has(n.uuid) && locallyAddedNoteUuids.has(n.uuid)
                );
                if (localNotes.length > 0) {
                    serverData.notes = [...localNotes, ...serverData.notes];
                    console.log(`[WRITE] selectPatient() merged ${localNotes.length} locally-added notes (API-first, protected)`);
                }
            }
            
            // Clean up confirmed UUIDs from tracking sets
            serverData.files?.forEach(f => { if (f.uuid) locallyAddedFileUuids.delete(f.uuid); });
            serverData.notes?.forEach(n => { if (n.uuid) locallyAddedNoteUuids.delete(n.uuid); });
        }
        
        workspaceData.value = serverData;
        const cats = serverData.categories || [];
        cats.forEach((c) => {
            expandedCategories.value[c.slug] = true;
        });
    } catch (e) {
        console.error("Failed to load patient data", e);
        console.log(`[WRITE] selectPatient() set workspaceData=null (error) at ${new Date().toISOString()}`);
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
        console.log(`[WRITE] closePatient() set workspaceData=null, selectedPatientId=null at ${new Date().toISOString()}`);
        selectedPatientId.value = null;
        workspaceData.value = null;
        mobilePatientListOpen.value = true;
    }
}

function refreshWorkspaceData() {
    // DEDUP GUARD: If a workspace data refresh is already in progress, return its promise.
    // This prevents parallel writes to workspaceData when refreshWorkspaceData() is called
    // from multiple sources (syncAndRefresh, sync-completed event, callbacks, PTR).
    if (refreshWorkspaceInProgress) {
        console.log('[refreshWorkspaceData] Dedup: returning in-progress workspace refresh');
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
            const serverData = response.data;
            const fileCount = serverData?.files?.length || 0;
            const noteCount = serverData?.notes?.length || 0;
            console.log(`[WRITE] refreshWorkspaceData() API returned ${fileCount} files, ${noteCount} notes at ${new Date().toISOString()}`);
            if (workspaceData.value) {
                if (locallyAddedFileUuids.size > 0 && serverData?.files) {
                    let serverUuids = new Set(serverData.files.map(f => f.uuid));
                    let localFiles = (workspaceData.value.files || []).filter(f => f.uuid && !serverUuids.has(f.uuid) && locallyAddedFileUuids.has(f.uuid));
                    if (localFiles.length > 0) {
                        serverData.files = localFiles.concat(serverData.files);
                        console.log(`[WRITE] refreshWorkspaceData() merged ${localFiles.length} locally-added files into workspaceData (API-first, protected)`);
                    }
                }
                if (locallyAddedNoteUuids.size > 0 && serverData?.notes) {
                    let serverNoteUuids = new Set(serverData.notes.map(n => n.uuid));
                    let localNotes = (workspaceData.value.notes || []).filter(n => n.uuid && !serverNoteUuids.has(n.uuid) && locallyAddedNoteUuids.has(n.uuid));
                    if (localNotes.length > 0) {
                        serverData.notes = localNotes.concat(serverData.notes);
                        console.log(`[WRITE] refreshWorkspaceData() merged ${localNotes.length} locally-added notes into workspaceData (API-first, protected)`);
                    }
                }
            }
            if (serverData?.files) {
                serverData.files.forEach(function(f) { if (f.uuid) locallyAddedFileUuids.delete(f.uuid); });
            }
            if (serverData?.notes) {
                serverData.notes.forEach(function(n) { if (n.uuid) locallyAddedNoteUuids.delete(n.uuid); });
            }
            workspaceData.value = serverData;
            resolve();
        }).catch(function(e) {
            if (selectedPatientId.value === patientUuid) {
                if (e?.response?.status === 404) {
                    console.log(`[WRITE] refreshWorkspaceData() set workspaceData=null (404) at ${new Date().toISOString()}`);
                    workspaceData.value = null;
                } else {
                    console.log(`[WRITE] refreshWorkspaceData() PRESERVED workspaceData (transient error: ${e?.message || e}) at ${new Date().toISOString()}`);
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
}

function addFileLocally(file) {
    if (!file?.uuid) return;
    // Track this UUID so refreshWorkspaceData() won't discard it
    capTrackingSet(locallyAddedFileUuids);
    locallyAddedFileUuids.add(file.uuid);
    if (!workspaceData.value) {
        workspaceData.value = {
            files: [file],
            notes: [],
            visits: [],
            shares: [],
            categories: [],
            stats: {},
        };
        console.log(`[WRITE] addFileLocally() set workspaceData with 1 file ${file.uuid} (new workspace) at ${new Date().toISOString()}`);
        return;
    }
    if (!workspaceData.value.files) workspaceData.value.files = [];
    const existingIndex = workspaceData.value.files.findIndex(
        (f) => f.uuid === file.uuid,
    );
    if (existingIndex === -1) {
        workspaceData.value.files = [file, ...workspaceData.value.files];
        console.log(`[WRITE] addFileLocally() prepended file ${file.uuid} to workspaceData.files (total=${workspaceData.value.files.length}) at ${new Date().toISOString()}`);
        syncWorkspaceStats(1);
    } else {
        workspaceData.value.files[existingIndex] = {
            ...workspaceData.value.files[existingIndex],
            ...file,
        };
        console.log(`[WRITE] addFileLocally() updated file ${file.uuid} in workspaceData.files at ${new Date().toISOString()}`);
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
    // Track this UUID so refreshWorkspaceData() won't discard it
    capTrackingSet(locallyAddedNoteUuids);
    locallyAddedNoteUuids.add(note.uuid);
    if (!workspaceData.value) {
        workspaceData.value = { notes: [note], files: [], visits: [], shares: [], categories: [], stats: {} };
        console.log(`[WRITE] addNoteLocally() set workspaceData with 1 note ${note.uuid} (new workspace) at ${new Date().toISOString()}`);
        return;
    }
    if (!workspaceData.value.notes) workspaceData.value.notes = [];
    const existingIndex = workspaceData.value.notes.findIndex(
        (n) => n.uuid === note.uuid,
    );
    if (existingIndex === -1) {
        workspaceData.value.notes = [note, ...workspaceData.value.notes];
        console.log(`[WRITE] addNoteLocally() prepended note ${note.uuid} to workspaceData.notes (total=${workspaceData.value.notes.length}) at ${new Date().toISOString()}`);
    } else {
        workspaceData.value.notes[existingIndex] = {
            ...workspaceData.value.notes[existingIndex],
            ...note,
        };
        console.log(`[WRITE] addNoteLocally() updated note ${note.uuid} in workspaceData.notes at ${new Date().toISOString()}`);
    }
}

function removeFileLocally(fileUuid) {
    // Clean up tracking so a future refreshWorkspaceData doesn't try to re-merge it
    locallyAddedFileUuids.delete(fileUuid);
    if (!workspaceData.value || !workspaceData.value.files) return;
    const before = workspaceData.value.files.length;        workspaceData.value.files = workspaceData.value.files.filter(
            (f) => f.uuid !== fileUuid,
        );
        if (workspaceData.value.files.length < before) {
            syncWorkspaceStats(-1);
        }
    }

function upsertPatient(patient) {
    if (!patient?.uuid) return;
    const existingIndex = patients.value.findIndex(
        (p) => p.uuid === patient.uuid,
    );
    if (existingIndex === -1) {
        patients.value = [patient, ...patients.value];
        console.log(`[WRITE] upsertPatient() prepended patient ${patient.uuid} to patients.value (new, total=${patients.value.length}) at ${new Date().toISOString()}`);
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
        console.log(`[WRITE] upsertPatient() updated patient ${patient.uuid} in patients.value at ${new Date().toISOString()}`);
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
            // Track this as locally created so refreshPatientList doesn't overwrite it out
            capTrackingSet(locallyCreatedPatients);
            locallyCreatedPatients.add(patient.uuid);
            
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
            
            // NOTE: We do NOT call refreshPatientList() here because:
            // 1) upsertPatient() already added the patient to patients.value (UI shows it immediately)
            // 2) refreshPatientList() OVERWRITES patients.value with API data. If the API paginates
            //    and the new patient is on page 2+, it DISAPPEARS from the UI.
            // 3) The newly-created patient UUID is tracked in locallyCreatedPatients so future
            //    refreshPatientList() calls will merge it back if the API doesn't return it.
            // 4) The periodic background sync (every 2 min) will eventually push the patient
            //    to the API and it will appear in normal paginated responses.
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

    // Save pre-update snapshot for rollback on failure
    const preUpdateIdx = patients.value.findIndex((p) => p.uuid === uuid);
    const preUpdateSnapshot = preUpdateIdx !== -1
        ? { ...patients.value[preUpdateIdx] }
        : null;

    // Apply optimistic UI update immediately
    if (preUpdateSnapshot) {
        patients.value[preUpdateIdx] = { ...patients.value[preUpdateIdx], ...formData };
    }
    // If the edited patient is currently selected, update workspaceData too
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
        // Rollback optimistic update on failure
        if (preUpdateSnapshot && preUpdateIdx !== -1) {
            patients.value[preUpdateIdx] = preUpdateSnapshot;
        }
        // Rollback workspaceData if it was updated
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
  // DEDUP GUARD: If a refresh is already in progress, return its promise.
  // This prevents multiple parallel writers to patients.value from different
  // call sites (syncAndRefresh, sync-completed event, PTR, pagination, callbacks).
  if (refreshPatientsInProgress) {
    console.log(`[refreshPatientList] Dedup: returning in-progress refresh for page ${page}`);
    return refreshPatientsInProgress;
  }

  refreshPatientsInProgress = (async () => {
    loadingPatients.value = true;
    try {
      const url = "/api/v1/workspace/patients-list";
      // When searchQuery is set, pass it to the API so search queries the
      // entire dataset, not just the currently loaded patients (T024).
      const params = { page };
      if (searchQuery.value && searchQuery.value.length >= 2) {
        params.search = searchQuery.value;
      }
      const res = await axios.get(url, {
        params,
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
      // BUT: We MERGE locally-created patients that aren't in the API response yet.
      // This prevents newly-created patients from disappearing when pagination
      // or sync delay means the API doesn't include them yet.
      const serverUuids = new Set(serverPatients.map(p => p.uuid));
      const pendingLocalPatients = patients.value.filter(
        p => p.uuid && !serverUuids.has(p.uuid) && locallyCreatedPatients.has(p.uuid)
      );
      
      // Confirm which server patients we now know about
      serverPatients.forEach(p => { if (p.uuid) locallyCreatedPatients.delete(p.uuid); });
      
      if (pendingLocalPatients.length > 0) {
        // Merge: prepend locally-created patients not yet in API response
        serverPatients = [...pendingLocalPatients, ...serverPatients];
        console.log(`[WRITE] refreshPatientList() merged ${pendingLocalPatients.length} locally-created patients into list (API-first, protected)`);
      }
      
      console.log(`[WRITE] refreshPatientList() set patients.value = ${serverPatients.length} patients (${serverPatients.length - (pendingLocalPatients?.length || 0)} from API, ${pendingLocalPatients?.length || 0} local-pending) at ${new Date().toISOString()}`);
      patients.value = serverPatients;
      
      const meta = res.data?.meta || {};
      patientsMeta.value = { ...meta };
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
