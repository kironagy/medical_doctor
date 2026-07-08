import axios from "axios";
import { reactive, ref } from "vue";
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

// ─── Global Concurrency Semaphore ─────────────────────────────────────────────
// Limits the TOTAL number of in-flight chunk requests across all active uploads.
// This prevents exhausting the browser's 6-connection limit, ensuring that
// navigating the app (e.g. fetching patient data) remains fast even with 4 files uploading.
let globalActiveChunks = 0;
const globalChunkQueue = [];

async function acquireGlobalSlot() {
    if (globalActiveChunks < POOL_SIZE) {
        globalActiveChunks++;
        return;
    }
    return new Promise(resolve => globalChunkQueue.push(resolve));
}

function releaseGlobalSlot() {
    if (globalChunkQueue.length > 0) {
        const resolve = globalChunkQueue.shift();
        resolve(); // Hand off slot directly
    } else {
        globalActiveChunks--;
    }
}

function loadPersisted() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
    } catch {
        return {};
    }
}
function savePersisted(s) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
    } catch {}
}
function fileKey(f) {
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

    async function startUpload(job, debug = null) {
        const d = debug || job?._debug || null;
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
                    const r = await axios.get(
                        `/api/v1/chunk/${persisted[key].upload_id}/status`,
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
                const initRes = await axios.post("/api/v1/chunk/init", {
                    file_name:  job.file.name,
                    file_size:  job.file.size,
                    mime_type:  job.file.type || "application/octet-stream",
                    patient_id: patientId,
                    chunk_size: CHUNK_SIZE,
                    metadata:   Object.keys(meta).length ? meta : undefined,
                });
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
                const completeRes = await axios.post("/api/v1/chunk/complete", {
                    upload_id: job.uploadId,
                });
                d?.onMergeComplete();
                if (completeRes?.data?.uuid) {
                    addFileLocally({
                        uuid:          completeRes.data.uuid,
                        patient_id:    patientId,
                        title:         metadata.title || job.file?.name || "",
                        desc:          metadata.desc || "",
                        category:      metadata.category || "",
                        file_name:     job.file?.name || "",
                        mime_type:     job.file?.type || "application/octet-stream",
                        size:          job.file?.size || 0,
                        created_at:    new Date().toISOString(),
                        updated_at:    new Date().toISOString(),
                        upload_status: "ready",
                        url:           completeRes.data.url,
                        thumbnail_url: completeRes.data.thumbnail_url,
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

    // ── Progress helper ───────────────────────────────────────────────────────
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

                const response = await axios.post("/api/v1/chunk/chunk", fd, {
                    signal: controller.signal,
                    timeout: 300000, // 5 minutes per chunk — ample for 5 MB on any connection
                    onUploadProgress: (e) => {
                        d?.onChunkUploadProgress(chunkIndex, e.loaded, e.total);
                        if (e.lengthComputable) {
                            job._inFlightLoaded.set(chunkIndex, e.loaded);
                            updateProgressFromParts(job);

                            // Update speed via sliding-window tracker
                            job._speedTracker.push(job.uploadedBytes);
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
            axios.post(`/api/v1/chunk/${job.uploadId}/cancel`).catch(() => {});
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
            const completeRes = await axios.post("/api/v1/chunk/complete", {
                upload_id: job.uploadId,
            });
            d?.onMergeComplete();
            if (completeRes?.data?.uuid) {
                addFileLocally({
                    uuid:          completeRes.data.uuid,
                    patient_id:    job.patientId,
                    title:         job.metadata?.title || job.file?.name || "",
                    desc:          job.metadata?.desc || "",
                    category:      job.metadata?.category || "",
                    file_name:     job.file?.name || "",
                    mime_type:     job.file?.type || "application/octet-stream",
                    size:          job.file?.size || 0,
                    created_at:    new Date().toISOString(),
                    updated_at:    new Date().toISOString(),
                    upload_status: "ready",
                    url:           completeRes.data.url,
                    thumbnail_url: completeRes.data.thumbnail_url,
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
