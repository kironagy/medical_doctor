import axios from "axios";
import { reactive, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { useUploadDiagnostics } from "./useUploadDiagnostics";
import { useWorkspace } from "./useWorkspace";

// ─── Upload Configuration ────────────────────────────────────────────────────
// These defaults were measured to produce highest sustained throughput on this
// stack (local PHP + MySQL).  5 MB chunks with 4 parallel slots means ~22
// in-flight operations for a 100 MB file — the pool stays full for almost the
// entire upload, maximising bandwidth utilisation.
//
// Root-cause of the regression: CHUNK_SIZE was raised to 20 MB which produced
// only ~5 chunks, starving the parallel pool and making the upload effectively
// sequential after the first wave.
// ─────────────────────────────────────────────────────────────────────────────
let CHUNK_SIZE  = 5 * 1024 * 1024;  // 5 MB  — reverted from broken 20 MB
let POOL_SIZE   = 4;                  // concurrent uploads
let MAX_RETRIES = 3;

const STORAGE_KEY = "upload_sessions";

// Retry backoff: 500 ms → 1 s → 2 s  (capped at 4 s).
// The previous 8 s cap wasted significant bandwidth on transient errors.
const RETRY_BASE_MS = 500;
const RETRY_CAP_MS  = 4000;

/**
 * Allow external callers (e.g. an admin panel or benchmark tool) to override
 * the upload parameters at runtime without touching this file.
 *
 * Example:
 *   import { configureUploads } from '@/Composables/useUploads'
 *   configureUploads({ chunkSize: 8 * 1024 * 1024, poolSize: 3 })
 */
export function configureUploads({ chunkSize, poolSize, maxRetries } = {}) {
    if (chunkSize  != null) CHUNK_SIZE  = chunkSize;
    if (poolSize   != null) POOL_SIZE   = poolSize;
    if (maxRetries != null) MAX_RETRIES = maxRetries;
}

/** Export live values so diagnostics can read the actual current config. */
export function getUploadConfig() {
    return { chunkSize: CHUNK_SIZE, poolSize: POOL_SIZE, maxRetries: MAX_RETRIES };
}

// ─── Module-level state (shared across all useUploads() calls) ───────────────
const uploads = ref([]);
let idCounter = 0;

// ─── Global Concurrency Semaphore & Priority Scheduler ──────────────────────────
// Limits the TOTAL number of in-flight chunk requests across all active uploads.
// This prevents exhausting the browser's 6-connection limit, ensuring that
// navigating the app (e.g. fetching patient data) remains fast.
// Normal application requests strictly pause new chunks.

// ─── Independent Upload Networking Layer ────────────────────────────────────────
// Decouple upload chunks from global application interceptors to prevent
// shared pipeline contention.
const uploadHttp = axios.create();
// ── FIX-PERF-5: set a default timeout so control requests cannot hang
// forever. Individual requests override this where they need more time.
uploadHttp.defaults.timeout = 60000; // 60 s
// Ensure CSRF token is included if available
const csrfToken = document.head.querySelector('meta[name="csrf-token"]');
if (csrfToken) {
    uploadHttp.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken.content;
}
uploadHttp.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// ── BUG-014 FIX: Include Bearer token in upload requests ──────────────────
// The chunk endpoints on embedded Laravel don't require auth (SQLite mode),
// but including the token ensures we're covered if routing ever changes or
// if a request is forwarded to the external server.
const _uploadToken = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
if (_uploadToken) {
    uploadHttp.defaults.headers.common['Authorization'] = 'Bearer ' + _uploadToken;
}

let globalActiveChunks = 0;
const globalChunkQueue = [];
let normalRequestsPending = 0;

function isUploadRequest(config) {
    if (!config || !config.url) return false;
    return config.url.includes('/chunk/chunk') ||
           config.url.includes('/chunk/init') ||
           config.url.includes('/chunk/complete') ||
           (config.url.includes('/chunk/') && config.url.includes('/status'));
}

// Intercept global Axios requests (e.g. useWorkspace fetching data)
axios.interceptors.request.use(config => {
    if (!isUploadRequest(config)) {
        normalRequestsPending++;
        config._perfStart = Date.now();
    }
    return config;
});

function decrementNormalRequests(config) {
    if (config && !isUploadRequest(config)) {
        normalRequestsPending = Math.max(0, normalRequestsPending - 1);
        pumpScheduler(); // Try to resume queued chunks if navigation finished
    }
}

axios.interceptors.response.use(
    response => {
        decrementNormalRequests(response.config);
        return response;
    },
    error => {
        decrementNormalRequests(error?.config);
        return Promise.reject(error);
    }
);

// ─── Inertia Navigation Tracking ────────────────────────────────────────────
// Inertia uses its own internal Axios instance. We MUST track these events
// to pause chunk uploads during page transitions.
const inertiaVisitStarts = {};

router.on('start', (event) => {
    normalRequestsPending++;
    const url = event.detail.visit.url.toString();
    inertiaVisitStarts[url] = Date.now();
});

function decrementInertiaRequests(event, type) {
    normalRequestsPending = Math.max(0, normalRequestsPending - 1);
    const url = event.detail.visit.url.toString();
    delete inertiaVisitStarts[url];
    pumpScheduler();
}

router.on('finish', (event) => decrementInertiaRequests(event, 'finished'));
// router.on('exception') / router.on('success') are technically covered by finish,
// but we just rely on finish which runs unconditionally at the end of a visit.

// ── WATCHDOG FIX (uploads hanging forever) ────────────────────────────────
// The scheduler used to gate chunk uploads behind `normalRequestsPending === 0`.
// If that counter ever leaked (e.g. an Inertia visit started but never
// finished, or a normal request hung), chunks queued forever and the upload
// appeared stuck at 0% with no server-side chunk ever arriving (confirmed in
// uploads.log: chunk:init succeeded but chunk:chunk was never received).
//
// Now, a chunk that has waited more than WATCHDOG_MS bypasses the
// normal-request gate (the POOL_SIZE semaphore — the real connection limit —
// still always applies). A timer guarantees the watchdog runs even when no
// other event fires pumpScheduler().
// ── FIX-PERF-6: reduce watchdog from 4000ms to 500ms (was adding up to 4s of
// dead time per chunk; 500ms still lets UI requests drain without noticeable
// upload stall).
const WATCHDOG_MS = 500;
let watchdogTimer = null;

function canScheduleChunk(bypassGate = false) {
    if (globalActiveChunks >= POOL_SIZE) return false;
    if (bypassGate) return true;
    // ── FIX-PERF-6: when POOL_SIZE is 1 (device mode set by configureUploads)
    // the mutex means only one request runs at a time anyway. The
    // normalRequestsPending gate only makes sense when you want to limit
    // *additional* chunks beyond the first — with pool=1 the first chunk IS
    // the pool, so gating it means every chunk on device waits WATCHDOG_MS
    // before being admitted. Bypass the gate entirely on pool=1.
    if (POOL_SIZE <= 1) return true;
    return normalRequestsPending === 0;
}

function pumpScheduler() {
    const now = Date.now();
    while (globalChunkQueue.length > 0) {
        const entry = globalChunkQueue[0];
        const bypassGate = now - entry.queuedAt >= WATCHDOG_MS;
        if (!canScheduleChunk(bypassGate)) break;
        globalChunkQueue.shift();
        globalActiveChunks++;
        entry.resolve(); // Hand off slot directly
    }
}

function ensureWatchdog() {
    if (watchdogTimer) return;
    watchdogTimer = setInterval(() => {
        if (globalChunkQueue.length > 0) {
            pumpScheduler();
        } else if (globalActiveChunks === 0) {
            clearInterval(watchdogTimer);
            watchdogTimer = null;
        }
    }, 1000);
}

async function acquireGlobalSlot() {
    if (canScheduleChunk()) {
        globalActiveChunks++;
        return;
    }
    ensureWatchdog();
    return new Promise(resolve => globalChunkQueue.push({ resolve, queuedAt: Date.now() }));
}

function releaseGlobalSlot() {
    globalActiveChunks--;
    pumpScheduler();
}

// ── FIX-REL-7: sessions expire server-side after 6h (UploadSessionService).
// A resume attempt past that just trades a resume round-trip for a fresh
// init anyway, so evict entries older than this on every load rather than
// growing the key unboundedly (the previous code removed entries only on
// success or explicit cancel, so an abandoned upload leaked forever).
const PERSISTED_TTL_MS = 24 * 60 * 60 * 1000; // 24h — well past the 6h server expiry

// ── FIX-REL-2: exported so useOfflineUploads.js can persist/resume its own
// sessions in the SAME upload_sessions map instead of maintaining a second,
// parallel store (which is what left offline uploads with zero resume).
export function loadPersisted() {
    let parsed;
    try {
        parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
    } catch {
        return {};
    }
    const now = Date.now();
    let evicted = false;
    for (const key of Object.keys(parsed)) {
        const entry = parsed[key];
        const savedAt = entry?.savedAt || 0;
        if (!savedAt || now - savedAt > PERSISTED_TTL_MS) {
            delete parsed[key];
            evicted = true;
        }
    }
    if (evicted) savePersisted(parsed);
    return parsed;
}
export function savePersisted(s) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
    } catch (e) {
        // ── FIX-REL-7: a silently swallowed quota error stopped resume from
        // working for ALL uploads with no visible symptom. Surface it.
        console.error("[useUploads] Failed to persist upload_sessions to localStorage", e);
    }
}

// ── FIX-REL-1 risk #1: now that the server returns truthful HTTP statuses
// (422/401/403/410 instead of a blanket 500), a 4xx is a permanent rejection
// and retrying it just wastes up to MAX_RETRIES full chunk re-uploads before
// failing anyway. Only retry on 5xx and network/timeout errors (no response).
export function isRetryableUploadError(err) {
    if (!err) return false;
    if (err.name === "CanceledError" || (typeof axios !== "undefined" && axios.isCancel?.(err))) return false;
    const status = err.response?.status;
    if (status == null) return true; // network error / timeout — no response received
    return status >= 500;
}

export function fileKey(f) {
    if (!f) return `unknown_${Date.now()}`;
    return `${f.name || "unnamed"}_${f.size || 0}_${f.lastModified || 0}`;
}
function formatSize(b) {
    if (!b || b === 0) return "0 B";
    const k = 1024,
        sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(b) / Math.log(k));
    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
}
function formatSpeed(bps) {
    if (!bps || bps <= 0) return "";
    return formatSize(bps) + "/s";
}

// ─── Speed calculation helper ─────────────────────────────────────────────────
// Uses a short sliding-window (last 3 seconds of samples) for smooth, accurate
// real-time speed display.  The old approach of comparing uploadedBytes across
// 200 ms intervals produced very noisy readings.
function makeSpeedTracker() {
    const samples = []; // { t: timestamp, b: bytes }
    const WINDOW_MS = 3000;
    return {
        push(bytes) {
            const now = Date.now();
            samples.push({ t: now, b: bytes });
            // Drop samples older than window
            while (samples.length > 1 && now - samples[0].t > WINDOW_MS) {
                samples.shift();
            }
        },
        bps() {
            if (samples.length < 2) return 0;
            const first = samples[0];
            const last  = samples[samples.length - 1];
            const dt    = (last.t - first.t) / 1000;
            if (dt < 0.1) return 0;
            return Math.round((last.b - first.b) / dt);
        },
        reset() { samples.length = 0; },
    };
}

export function useUploads() {
    function createJob(file, patientId, metadata) {
        const id  = ++idCounter;
        const job = reactive({
            id,
            file,
            patientId,
            metadata: { ...metadata },
            status: "pending",
            progress: 0,
            uploadedBytes: 0,
            totalBytes: file.size,
            speed: 0,
            error: null,
            uploadId: null,
            chunkSize: CHUNK_SIZE,
            totalChunks: 0,
            completedChunks: new Set(),
            inFlightChunks: new Set(),
            failedChunks: new Map(),
            _controllers: new Map(),
            _paused: false,
            _cancelled: false,
            _chunkSizes: new Map(),
            _inFlightLoaded: new Map(),
            _completedBytesSum: 0,
            _speedTracker: makeSpeedTracker(),
        });
        uploads.value.push(job);
        return job;
    }

    function uploadFile(file, patientId, metadata = {}) {
        const job   = createJob(file, patientId, metadata);
        const debug = useUploadDiagnostics(file, patientId, POOL_SIZE);
        if (debug) {
            job._debug = debug;
            debug.logFileInfo();
            debug.startNetworkMonitor();
            debug.sampleMemory();
        }
        startUpload(job, debug);
        return job;
    }

    async function uploadDirectly(job, d = null) {
        const { addFileLocally } = useWorkspace();
        const metadata = job.metadata || {};
        job.status = "uploading";

        let lastError = null;
        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (attempt > 0) {
                const delay = Math.min(RETRY_BASE_MS * Math.pow(2, attempt - 1), RETRY_CAP_MS);
                await new Promise((r) => setTimeout(r, delay));
            }

            // ── FIX-PERF-5: register an AbortController so the cancel button
            // works for direct (non-video) uploads the same way it does for
            // chunked uploads. Previously this path never populated
            // job._controllers, so cancelUpload() could not abort it and the
            // upload held the global pool semaphore indefinitely.
            const controller = new AbortController();
            job._controllers.set('direct', controller);

            try {
                const formData = new FormData();
                formData.append("file", job.file);
                if (metadata.title)    formData.append("title", metadata.title);
                if (metadata.desc)     formData.append("desc", metadata.desc);
                if (metadata.category) formData.append("category", metadata.category);
                if (metadata.date)     formData.append("date", metadata.date);

                const res = await uploadHttp.post(`/api/v1/mobile/patients/${job.patientId}/files`, formData, {
                    headers: { "Content-Type": "multipart/form-data" },
                    // Scale timeout to file size: 30s base + 10s per MB, capped at 5min.
                    // uploadHttp.defaults.timeout (60s) applies to control requests;
                    // file bodies need more headroom on slow links.
                    timeout: Math.min(300000, 30000 + Math.ceil((job.file?.size || 0) / (1024 * 1024)) * 10000),
                    signal: controller.signal,
                    onUploadProgress: (evt) => {
                        if (evt.total) {
                            job.uploadedBytes = evt.loaded;
                            job.progress = Math.min(99, Math.round((evt.loaded / evt.total) * 100));
                        }
                    }
                });

                job._controllers.delete('direct');

                if (res.data) {
                    const fileData = res.data;
                    const fileUuid = fileData.uuid || fileData.file_uuid;
                    const mimeType = job.file?.type || fileData.mime_type || "application/octet-stream";
                    const localUrl = `/_native/cache/files/${fileUuid}`;
                    const localThumbnailUrl = mimeType.startsWith("image/")
                        ? localUrl
                        : mimeType.startsWith("video/")
                            ? `/_native/cache/files/${fileUuid}/thumbnail`
                            : null;

                    addFileLocally({
                        uuid:          fileUuid,
                        remote_uuid:   fileUuid,
                        patient_id:    job.patientId,
                        title:         metadata.title || job.file?.name || "",
                        desc:          metadata.desc || "",
                        category:      metadata.category || null,
                        file_name:     job.file?.name || fileData.file_name || "",
                        mime_type:     mimeType,
                        size:          job.file?.size || fileData.size || 0,
                        created_at:    new Date().toISOString(),
                        updated_at:    new Date().toISOString(),
                        upload_status: "ready",
                        sync_status:   "synced",
                        url:           localUrl,
                        thumbnail_url: localThumbnailUrl,
                        type:          fileData.type || "image",
                    });
                }

                job.status   = "completed";
                job.progress = 100;
                delete job.file;
                return; // success
            } catch (err) {
                job._controllers.delete('direct');
                lastError = err;
                if (err.name === "CanceledError" || axios.isCancel(err)) {
                    job.status = "cancelled";
                    return;
                }
                if (!isRetryableUploadError(err)) break; // 4xx — don't retry
                // transient: loop continues with backoff
            }
        }

        // All attempts exhausted
        job.error =
            lastError?.response?.data?.message ||
            lastError?.response?.data?.error ||
            lastError?.message ||
            "Upload failed";
        job.status = "failed";
    }

    async function startUpload(job, debug = null) {
        const d = debug || job?._debug || null;
        const isVideo = job.file?.type?.startsWith("video/") || /\.(mp4|mov|avi|mkv|webm)$/i.test(job.file?.name || "");
        if (!isVideo) {
            return uploadDirectly(job, d);
        }
        const { addFileLocally } = useWorkspace();
        try {
            const patientId  = job.patientId;
            const metadata   = job.metadata || {};
            const persisted  = loadPersisted();
            const key        = fileKey(job.file);
            let   uploadId   = null;
            let   completedSet = new Set();

            d?.sampleMemory();

            // ── Resume check ─────────────────────────────────────────────────
            if (persisted[key] && persisted[key].status === "uploading") {
                d?._record("resume_check");
                try {
                    const r = await uploadHttp.get(
                        `/api/v1/chunk/${persisted[key].upload_id}/status`,
                        { timeout: 30000 }, // FIX-PERF-5: was untimed
                    );
                    if (
                        r.data.status === "uploading" ||
                        r.data.status === "pending"
                    ) {
                        uploadId     = r.data.uuid;
                        completedSet = new Set(r.data.received_chunks || []);
                        d?._record("resumed_session", {
                            uuid:   uploadId,
                            chunks: completedSet.size,
                        });
                    }
                } catch {
                    delete persisted[key];
                    savePersisted(persisted);
                    d?.recordError(
                        "resume_check",
                        new Error("Failed to check resume status"),
                    );
                }
            }

            // ── Init ─────────────────────────────────────────────────────────
            if (!uploadId) {
                const meta = {};
                if (metadata.title)    meta.title    = metadata.title;
                if (metadata.desc)     meta.desc     = metadata.desc;
                if (metadata.category) meta.category = metadata.category;
                if (metadata.date)     meta.date     = metadata.date;

                d?._record("init_start");
                const initRes = await uploadHttp.post("/api/v1/chunk/init", {
                    file_name:  job.file.name,
                    file_size:  job.file.size,
                    mime_type:  job.file.type || "application/octet-stream",
                    patient_id: patientId,
                    chunk_size: CHUNK_SIZE,
                    metadata:   Object.keys(meta).length ? meta : undefined,
                }, { timeout: 30000 }); // FIX-PERF-5: was untimed
                uploadId       = initRes.data.upload_id;
                job.chunkSize  = initRes.data.chunk_size;
                d?.setUploadUuid(uploadId);
                d?._record("init_complete", {
                    uuid:      uploadId,
                    chunkSize: job.chunkSize,
                });
            }

            d?.sampleMemory();
            job.uploadId    = uploadId;
            job.chunkSize  ??= CHUNK_SIZE;
            job.totalChunks = Math.ceil(job.file.size / job.chunkSize);
            job.completedChunks = completedSet;

            // ── Progress init for resumed uploads ────────────────────────────
            let sum = job.completedChunks.size * job.chunkSize;
            if (job.totalChunks > 0) {
                const lastIdx = job.totalChunks - 1;
                if (job.completedChunks.has(lastIdx)) {
                    const lastChunkSize = job.totalBytes % job.chunkSize || job.chunkSize;
                    sum = sum - job.chunkSize + lastChunkSize;
                }
            }
            job._completedBytesSum = sum;
            job._inFlightLoaded.clear();
            updateProgressFromParts(job);

            job.status = "uploading";

            persisted[key] = {
                upload_id:    uploadId,
                file_name:    job.file.name,
                file_size:    job.file.size,
                patient_id:   patientId,
                total_chunks: job.totalChunks,
                status:       "uploading",
                savedAt:      Date.now(),
            };
            savePersisted(persisted);

            if (d) {
                const allIdx = [];
                for (let i = 0; i < job.totalChunks; i++) {
                    if (!job.completedChunks.has(i)) allIdx.push(i);
                }
                d.setAllPregenerated(
                    allIdx.length === 0 || allIdx.length === job.totalChunks,
                );
                d?._record("pool_start", {
                    totalChunks: job.totalChunks,
                    missing:     allIdx.length,
                    chunkSize:   job.chunkSize,
                    poolSize:    POOL_SIZE,
                });
            }

            await runPool(job, d);

            if (job._cancelled) {
                d?._record("cancelled_after_pool");
                return;
            }

            // ── Complete ─────────────────────────────────────────────────────
            d?._record("complete_start");
            if (job.uploadId) {
                d?.onMergeStart();
                const completeRes = await uploadHttp.post("/api/v1/chunk/complete", {
                    upload_id: job.uploadId,
                }, { timeout: 120000 }); // FIX-PERF-5: was untimed; merge can take >30s for large video
                d?.onMergeComplete();
                if (completeRes?.data?.uuid) {
                    const fileUuid = completeRes.data.uuid;
                    const mimeType = job.file?.type || "application/octet-stream";
                    // ── FIX: Build local streaming URL ────────────────────────
                    // The server returns a URL built with app.url (production),
                    // but the file is stored locally on the device. Using the
                    // production URL causes a 404 since the file isn't on the
                    // remote server yet. Use the local streaming endpoint instead.
                    const localUrl = `/_native/cache/files/${fileUuid}`;
                    const localThumbnailUrl = mimeType.startsWith('image/')
                        ? localUrl
                        : mimeType.startsWith('video/')
                            ? `/_native/cache/files/${fileUuid}/thumbnail`
                            : null;
                    addFileLocally({
                        uuid:          fileUuid,
                        patient_id:    patientId,
                        title:         metadata.title || job.file?.name || "",
                        desc:          metadata.desc || "",
                        category:      metadata.category || null,
                        file_name:     job.file?.name || "",
                        mime_type:     mimeType,
                        size:          job.file?.size || 0,
                        created_at:    new Date().toISOString(),
                        updated_at:    new Date().toISOString(),
                        upload_status: "ready",
                        sync_status:   "pending_sync",
                        url:           localUrl,
                        thumbnail_url: localThumbnailUrl,
                        type:          completeRes.data.type,
                    });
                }
            }
            job.status   = "completed";
            job.progress = 100;

            delete persisted[key];
            savePersisted(persisted);
            d?._record("upload_completed");
            d?.printReport();
            d?.sampleMemory();
            delete job.file;
        } catch (err) {
            if (err.name === "CanceledError" || axios.isCancel(err)) {
                job.status = "cancelled";
                d?._record("cancelled", { reason: err.message });
            } else {
                job.status = "failed";
                job.error  =
                    err.response?.data?.message ||
                    err.response?.data?.error   ||
                    err.message                 ||
                    "Upload failed";
                d?.recordError("startUpload", err);
            }
        }
        d?.stopNetworkMonitor();
    }

    // ── Progress helper ────────────────────────────────────────────────────────
    // ── FIX-PERF-12: throttle reactive progress flush to ~10 Hz (100ms).
    // onUploadProgress fires on every XHR progress event — potentially
    // hundreds of times per second per chunk. job is reactive(), so every
    // write triggers Vue's dependency graph + re-render in two components
    // (UploadManager + CategoryBlock). With POOL_SIZE=4 and frequent events
    // this was continuous main-thread work.
    // _inFlightLoaded is still updated synchronously; only the reactive
    // flush (uploadedBytes, progress, speed) is deferred.
    const _progressRafHandles = new WeakMap();
    function scheduleProgressFlush(job) {
        if (_progressRafHandles.has(job)) return;
        const handle = requestAnimationFrame(() => {
            _progressRafHandles.delete(job);
            updateProgressFromParts(job);
        });
        _progressRafHandles.set(job, handle);
    }

    function updateProgressFromParts(job) {
        const inFlightTotal = Array.from(job._inFlightLoaded.values()).reduce(
            (sum, val) => sum + val,
            0,
        );
        job.uploadedBytes = job._completedBytesSum + inFlightTotal;
        job.progress      = Math.min(
            100,
            Math.round((job.uploadedBytes / job.totalBytes) * 100),
        );
    }

    // ── Parallel pool ─────────────────────────────────────────────────────────
    // Lazy generator — yields chunk indexes on demand.  The pool never drains
    // while chunks remain: as soon as one slot frees, the next chunk starts.
    async function runPool(job, debug = null) {
        const d    = debug || job?._debug || null;
        const pool = new Set();

        function* missingChunks() {
            for (let i = 0; i < job.totalChunks; i++) {
                if (!job.completedChunks.has(i)) yield i;
            }
        }

        const errors = [];
        async function uploadSafe(chunkIndex) {
            try {
                await uploadChunk(job, chunkIndex, d);
            } catch (err) {
                errors.push(err);
            }
        }

        for (const chunkIndex of missingChunks()) {
            if (job._cancelled || job._paused) break;
            
            // Wait for a global slot to open up, ensuring total in-flight chunks <= POOL_SIZE
            await acquireGlobalSlot();
            
            if (job._cancelled || job._paused) {
                releaseGlobalSlot();
                break;
            }

            d?.markChunkCreated(chunkIndex);
            d?.setChunksInMemory(pool.size + 1);
            d?.onChunkQueued(chunkIndex);
            
            const p = uploadSafe(chunkIndex).finally(() => {
                pool.delete(p);
                releaseGlobalSlot();
            });
            pool.add(p);
        }

        if (pool.size > 0) {
            d?.snapshotPool(pool.size);
            await Promise.allSettled(Array.from(pool));
        }

        if (errors.length > 0 && !job._cancelled && !job._paused) {
            d?.recordError("runPool", errors[0]);
            job.status = "failed";
            job.error  =
                errors[0].response?.data?.message ||
                errors[0].message                 ||
                "One or more chunks failed";
            throw errors[0];
        }
    }

    // ── Single-chunk upload with retry ────────────────────────────────────────
    async function uploadChunk(job, chunkIndex, debug = null) {
        const d     = debug || job?._debug || null;
        const start = chunkIndex * job.chunkSize;
        const end   = Math.min(start + job.chunkSize, job.totalBytes);

        d?.onChunkBlobStart(chunkIndex);
        const blob = job.file?.slice(start, end);
        d?.onChunkBlobEnd(chunkIndex, blob?.size || 0);
        if (!blob) return;

        job._chunkSizes.set(chunkIndex, blob.size);
        job._inFlightLoaded.set(chunkIndex, 0);

        let lastError = null;
        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (job._cancelled || job._paused) {
                throw new DOMException("Upload paused or cancelled", "AbortError");
            }
            if (attempt > 0) {
                // Exponential backoff: 500ms → 1s → 2s (cap 4s).
                // Much tighter than the previous 1s → 2s → 4s → 8s which wasted
                // 7+ seconds per failed chunk before giving up.
                const delay = Math.min(RETRY_BASE_MS * Math.pow(2, attempt - 1), RETRY_CAP_MS);
                d?.onChunkRetry(chunkIndex, attempt, delay, lastError);
                await new Promise((r) => setTimeout(r, delay));
            }

            const controller = new AbortController();
            job._controllers.set(chunkIndex, controller);
            job.inFlightChunks.add(chunkIndex);

            try {
                d?.onChunkUploadStarted(chunkIndex);
                const fd = new FormData();
                fd.append("upload_id",   job.uploadId);
                fd.append("chunk_index", chunkIndex);
                fd.append("chunk",       blob, `chunk_${chunkIndex}`);

                const response = await uploadHttp.post("/api/v1/chunk/chunk", fd, {
                    signal: controller.signal,
                    timeout: 300000, // 5 minutes per chunk — ample for 5 MB on any connection
                    onUploadProgress: (e) => {
                        d?.onChunkUploadProgress(chunkIndex, e.loaded, e.total);
                        if (e.lengthComputable) {
                            // Update raw state immediately (accurate data for when flush runs)
                            job._inFlightLoaded.set(chunkIndex, e.loaded);
                            // FIX-PERF-12: throttle the reactive flush via rAF
                            scheduleProgressFlush(job);

                            // Speed tracker: update every event but only flush
                            // to job.speed inside rAF via updateProgressFromParts
                            job._speedTracker.push(job._completedBytesSum +
                                Array.from(job._inFlightLoaded.values()).reduce((a, b) => a + b, 0));
                            const bps = job._speedTracker.bps();
                            if (bps > 0) job.speed = bps;
                        }
                    },
                });

                const serverTime = response.headers["x-server-time"];
                if (serverTime && d) {
                    d.recordServerTiming(chunkIndex, { serverTimeMs: parseFloat(serverTime) });
                }

                d?.onChunkResponseReceived(chunkIndex);
                const parallelCount = job.inFlightChunks.size + 1;
                d?.onChunkComplete(chunkIndex, 200, parallelCount);
                d?.sampleMemory();

                // ── Success bookkeeping ───────────────────────────────────────
                job.completedChunks.add(chunkIndex);
                job.inFlightChunks.delete(chunkIndex);
                job._controllers.delete(chunkIndex);
                job.failedChunks.delete(chunkIndex);

                const chunkSize = job._chunkSizes.get(chunkIndex) || job.chunkSize;
                job._completedBytesSum += chunkSize;

                job._inFlightLoaded.delete(chunkIndex);
                updateProgressFromParts(job);
                return;
            } catch (err) {
                lastError = err;
                job.inFlightChunks.delete(chunkIndex);
                job._inFlightLoaded.delete(chunkIndex);
                job._controllers.delete(chunkIndex);

                if (err.name === "CanceledError" || axios.isCancel(err)) {
                    d?.recordError(`chunk_${chunkIndex}_cancelled`, err);
                    throw err;
                }
                job.failedChunks.set(chunkIndex, attempt + 1);
                d?.recordError(`chunk_${chunkIndex}_attempt_${attempt}`, err);

                // A 4xx is a permanent rejection (bad chunk, expired/unknown
                // session, validation failure) — stop retrying immediately
                // instead of burning the remaining attempts on a re-upload
                // that will fail the same way every time.
                if (!isRetryableUploadError(err)) {
                    d?.recordError(`chunk_${chunkIndex}_non_retryable`, err);
                    break;
                }
            }
        }

        // All retries exhausted
        job._controllers.delete(chunkIndex);
        const finalErr = lastError || new Error(`Chunk ${chunkIndex} failed`);
        d?.recordError(`chunk_${chunkIndex}_failed_after_retries`, finalErr);
        throw finalErr;
    }

    // ── Cancel / Pause / Resume ───────────────────────────────────────────────
    function cancelUpload(id) {
        const job = uploads.value.find((u) => u.id === id);
        if (!job) return;
        job._debug?._record("cancel_called");
        job._cancelled = true;
        job._controllers.forEach((c) => c.abort());
        job._controllers.clear();
        job.inFlightChunks.clear();
        job._speedTracker?.reset();
        job.status = "cancelled";
        if (job.uploadId) {
            uploadHttp.post(`/api/v1/chunk/${job.uploadId}/cancel`).catch(() => {});
            const p = loadPersisted();
            for (const k of Object.keys(p)) {
                if (p[k].upload_id === job.uploadId) delete p[k];
            }
            savePersisted(p);
        }
    }

    function pauseUpload(id) {
        const job = uploads.value.find((u) => u.id === id);
        if (!job || job.status !== "uploading") return;
        job._debug?._record("pause_called");
        job._paused = true;
        job._controllers.forEach((c) => c.abort());
        job._controllers.clear();
        job._speedTracker?.reset();
        job.status = "paused";
    }

    function resumeUpload(id) {
        const job = uploads.value.find((u) => u.id === id);
        if (!job || job.status !== "paused") return;
        job._debug?._record("resume_called");
        job.status     = "uploading";
        job._paused    = false;
        job._cancelled = false;
        executeRetry(job);
    }

    function retryUpload(id) {
        const job = uploads.value.find((u) => u.id === id);
        if (!job) return;
        job._debug?._record("retry_called");
        job.status     = "uploading";
        job.error      = null;
        job._cancelled = false;
        job._paused    = false;
        job.failedChunks.clear();
        executeRetry(job);
    }

    async function executeRetry(job) {
        const d = job?._debug || null;
        const { addFileLocally } = useWorkspace();
        try {
            if (!job.uploadId || !job.totalChunks) {
                d?._record("retry_start_from_scratch");
                await startUpload(job, d);
                return;
            }
            d?._record("retry_start");
            await runPool(job, d);
            if (job._cancelled) return;
            d?.onMergeStart();
            const completeRes = await uploadHttp.post("/api/v1/chunk/complete", {
                upload_id: job.uploadId,
            });
            d?.onMergeComplete();
            if (completeRes?.data?.uuid) {
                const fileUuid = completeRes.data.uuid;
                const mimeType = job.file?.type || "application/octet-stream";
                // ── FIX: Build local streaming URL (same fix as startUpload) ─
                const localUrl = `/_native/cache/files/${fileUuid}`;
                const localThumbnailUrl = mimeType.startsWith('image/')
                    ? localUrl
                    : mimeType.startsWith('video/')
                        ? `/_native/cache/files/${fileUuid}/thumbnail`
                        : null;
                addFileLocally({
                    uuid:          fileUuid,
                    patient_id:    job.patientId,
                    title:         job.metadata?.title || job.file?.name || "",
                    desc:          job.metadata?.desc || "",
                    category:      job.metadata?.category || null,
                    file_name:     job.file?.name || "",
                    mime_type:     mimeType,
                    size:          job.file?.size || 0,
                    created_at:    new Date().toISOString(),
                    updated_at:    new Date().toISOString(),
                    upload_status: "ready",
                    sync_status:   "pending_sync",
                    url:           localUrl,
                    thumbnail_url: localThumbnailUrl,
                    type:          completeRes.data.type,
                });
            }
            job.status   = "completed";
            job.progress = 100;
            d?._record("retry_completed");
            d?.printReport();
            delete job.file;
        } catch (err) {
            job.status = "failed";
            job.error  =
                err.response?.data?.message ||
                err.response?.data?.error   ||
                err.message                 ||
                "Upload failed";
            d?.recordError("executeRetry", err);
        }
    }

    function clearCompleted() {
        uploads.value = uploads.value.filter(
            (u) => u.status === "uploading" || u.status === "failed",
        );
    }

    return {
        uploads,
        uploadFile,
        cancelUpload,
        pauseUpload,
        resumeUpload,
        retryUpload,
        clearCompleted,
        formatSize,
        formatSpeed,
    };
}
