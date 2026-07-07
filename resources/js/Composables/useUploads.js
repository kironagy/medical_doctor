import axios from "axios";
import { reactive, ref } from "vue";
import { useUploadDiagnostics } from "./useUploadDiagnostics";
import { useWorkspace } from "./useWorkspace";

// Compute SHA-256 hash of a Blob
async function sha256(blob) {
    const buffer = await blob.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}

// IndexedDB persistence
class UploadPersistence {
    constructor() {
        this.dbName = 'upload_sessions_db';
        this.storeName = 'uploads';
        this.db = null;
    }

    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, 1);
            request.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(this.storeName)) {
                    db.createObjectStore(this.storeName, { keyPath: 'key' });
                }
            };
            request.onsuccess = (e) => {
                this.db = e.target.result;
                resolve();
            };
            request.onerror = (e) => reject(e);
        });
    }

    async get(key) {
        if (!this.db) await this.init();
        return new Promise((resolve) => {
            const tx = this.db.transaction(this.storeName, 'readonly');
            const store = tx.objectStore(this.storeName);
            const req = store.get(key);
            req.onsuccess = () => resolve(req.result?.value || null);
            req.onerror = () => resolve(null);
        });
    }

    async set(key, value) {
        if (!this.db) await this.init();
        return new Promise((resolve) => {
            const tx = this.db.transaction(this.storeName, 'readwrite');
            const store = tx.objectStore(this.storeName);
            store.put({ key, value });
            tx.oncomplete = () => resolve();
        });
    }

    async remove(key) {
        if (!this.db) await this.init();
        return new Promise((resolve) => {
            const tx = this.db.transaction(this.storeName, 'readwrite');
            const store = tx.objectStore(this.storeName);
            store.delete(key);
            tx.oncomplete = () => resolve();
        });
    }

    async clear() {
        if (!this.db) await this.init();
        return new Promise((resolve) => {
            const tx = this.db.transaction(this.storeName, 'readwrite');
            const store = tx.objectStore(this.storeName);
            store.clear();
            tx.oncomplete = () => resolve();
        });
    }
}

const persistence = new UploadPersistence();

export function useUploads() {
    const uploads = ref([]);
    let idCounter = 0;
    const POOL_SIZE = 4; // Increased from 2, adaptive later
    const MIN_POOL_SIZE = 2;
    const MAX_POOL_SIZE = 8;
    const MAX_RETRIES = 3;
    const CHUNK_SIZE = 5 * 1024 * 1024;
    const MIN_CHUNK_SIZE = 1 * 1024 * 1024;
    const MAX_CHUNK_SIZE = 16 * 1024 * 1024;

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

    function formatTime(seconds) {
        if (!seconds || seconds <= 0) return "";
        if (seconds < 60) return `${Math.round(seconds)}s`;
        const mins = Math.floor(seconds / 60);
        const secs = Math.round(seconds % 60);
        return `${mins}m ${secs}s`;
    }

    // Generate file fingerprint (name + size + lastModified)
    function fileKey(file) {
        if (!file) return `unknown_${Date.now()}`;
        return `${file.name || "unnamed"}_${file.size || 0}_${file.lastModified || 0}`;
    }

    // Calculate accurate bytes for a chunk index
    function getChunkSizeAt(session, chunkIndex) {
        if (chunkIndex === session.totalChunks - 1) {
            const remainder = session.totalBytes % session.chunkSize;
            return remainder || session.chunkSize;
        }
        return session.chunkSize;
    }

    function createJob(file, patientId, metadata) {
        const id = ++idCounter;
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
            eta: 0,
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
            _lastLoaded: 0,
            _lastTime: Date.now(),
            _chunkSizes: new Map(),
            _inFlightLoaded: new Map(),
            _completedBytesSum: 0,
            _concurrency: POOL_SIZE,
            _networkSample: [],
        });
        uploads.value.push(job);
        return job;
    }

    async function uploadFile(file, patientId, metadata = {}) {
        const job = createJob(file, patientId, metadata);
        const debug = useUploadDiagnostics(file, patientId);
        if (debug) {
            job._debug = debug;
            debug.logFileInfo();
            debug.startNetworkMonitor();
            debug.sampleMemory();
        }
        await startUpload(job, debug);
        return job;
    }

    async function startUpload(job, debug = null) {
        const d = debug || job?._debug || null;
        const { addFileLocally } = useWorkspace();
        try {
            const patientId = job.patientId;
            const metadata = job.metadata || {};

            d?.sampleMemory();

            // Check for persisted session (page refresh recovery)
            const persisted = await persistence.get('upload:' + fileKey(job.file));
            let uploadId = null;
            let completedSet = new Set();

            if (persisted && persisted.uploadId) {
                d?._record("recovery_check");
                try {
                    const r = await axios.get(`/api/v1/chunk/${persisted.uploadId}/status`);
                    if (r.data.status === "uploading" || r.data.status === "pending") {
                        uploadId = r.data.uuid;
                        completedSet = new Set(r.data.received_chunks || []);
                        d?._record("recovered_session", {
                            uuid: uploadId,
                            chunks: completedSet.size,
                            from: 'indexeddb',
                        });
                    }
                } catch (e) {
                    await persistence.remove('upload:' + fileKey(job.file));
                    d?.recordError("recovery_check", new Error("Failed to verify persisted session"));
                }
            }

            // If no valid persisted session, create new one
            if (!uploadId) {
                const meta = {};
                if (metadata.title) meta.title = metadata.title;
                if (metadata.desc) meta.desc = metadata.desc;
                if (metadata.category) meta.category = metadata.category;
                if (metadata.date) meta.date = metadata.date;

                d?._record("init_start");
                const initRes = await axios.post("/api/v1/chunk/init", {
                    file_name: job.file.name,
                    file_size: job.file.size,
                    mime_type: job.file.type || "application/octet-stream",
                    patient_id: patientId,
                    chunk_size: job.chunkSize,
                    metadata: Object.keys(meta).length ? meta : undefined,
                });
                uploadId = initRes.data.upload_id;
                job.chunkSize = initRes.data.chunk_size;
                d?.setUploadUuid(uploadId);
                d?._record("init_complete", {
                    uuid: uploadId,
                    chunkSize: job.chunkSize,
                });
            }

            d?.sampleMemory();
            job.uploadId = uploadId;
            job.totalChunks = Math.ceil(job.file.size / job.chunkSize);
            job.completedChunks = completedSet;

            // Initialize progress tracking for resumed uploads
            let sum = 0;
            job.completedChunks.forEach(idx => {
                const chunkSize = getChunkSizeAt(job, idx);
                sum += chunkSize;
            });
            job._completedBytesSum = sum;
            job._inFlightLoaded.clear();
            updateProgressFromParts(job);

            job.status = "uploading";

            // Persist session state
            await saveJobState(job);

            d?._record("pool_start", {
                totalChunks: job.totalChunks,
                missing: job.totalChunks - job.completedChunks.size,
                concurrency: job._concurrency,
            });

            // Upload with smart parallel pool
            await runSmartPool(job, d);

            if (job._cancelled) {
                d?._record("cancelled_after_pool");
                return;
            }

            // Complete
            d?._record("complete_start");
            if (job.uploadId) {
                d?.onMergeStart();
                const completeRes = await axios.post("/api/v1/chunk/complete", {
                    upload_id: job.uploadId,
                });
                d?.onMergeComplete();
                if (completeRes?.data?.uuid) {
                    addFileLocally({
                        uuid: completeRes.data.uuid,
                        patient_id: patientId,
                        title: metadata.title || job.file?.name || "",
                        desc: metadata.desc || "",
                        category: metadata.category || "",
                        file_name: job.file?.name || "",
                        mime_type: job.file?.type || "application/octet-stream",
                        size: job.file?.size || 0,
                        created_at: new Date().toISOString(),
                        updated_at: new Date().toISOString(),
                        upload_status: "ready",
                        url: completeRes.data.url,
                        thumbnail_url: completeRes.data.thumbnail_url,
                        type: completeRes.data.type,
                    });
                }
            }
            job.status = "completed";
            job.progress = 100;
            job.uploadedBytes = job.totalBytes;
            job.eta = 0;

            await persistence.remove('upload:' + fileKey(job.file));
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
                job.error =
                    err.response?.data?.message ||
                    err.response?.data?.error ||
                    err.message ||
                    "Upload failed";
                d?.recordError("startUpload", err);
            }
        }
        d?.stopNetworkMonitor();
    }

    // Smart pool with adaptive concurrency – proper queue scheduler
    async function runSmartPool(job, debug = null) {
        const d = debug || job?._debug || null;
        const activeWorkers = new Map(); // chunkIndex -> Promise
        const failedChunks = new Set();
        const queue = [];

        // Initialize queue with all missing chunks (including any previously failed)
        for (let i = 0; i < job.totalChunks; i++) {
            if (!job.completedChunks.has(i)) {
                queue.push(i);
            }
        }

        // Calculate adaptive concurrency based on network samples
        function adjustConcurrency() {
            if (job._networkSample.length < 5) return job._concurrency;

            const avgSpeed = job._networkSample.reduce((a, b) => a + b, 0) / job._networkSample.length;

            if (avgSpeed > 5 * 1024 * 1024) { // > 5 MB/s
                return Math.min(MAX_POOL_SIZE, job._concurrency + 1);
            } else if (avgSpeed < 512 * 1024) { // < 512 KB/s
                return Math.max(MIN_POOL_SIZE, job._concurrency - 1);
            }
            return job._concurrency;
        }

        async function worker(chunkIndex) {
            if (job._cancelled || job._paused) {
                activeWorkers.delete(chunkIndex);
                return;
            }

            try {
                await uploadChunk(job, chunkIndex, d);
                // After successful chunk, try to increase concurrency if network allows
                job._concurrency = adjustConcurrency();
            } catch (err) {
                if (!job._cancelled && !job._paused) {
                    failedChunks.add(chunkIndex);
                    // Reduce concurrency on failure
                    job._concurrency = Math.max(MIN_POOL_SIZE, job._concurrency - 1);
                }
            } finally {
                activeWorkers.delete(chunkIndex);
            }
        }

        // Main scheduler loop – zero idle time
        while ((queue.length > 0 || activeWorkers.size > 0) && !job._cancelled && !job._paused) {
            // Start new workers up to concurrency limit
            while (queue.length > 0 && activeWorkers.size < job._concurrency) {
                const chunkIndex = queue.shift();
                const p = worker(chunkIndex);
                activeWorkers.set(chunkIndex, p);
                // No separate finally – worker cleans itself
            }

            // Wait for at least one worker to finish if at capacity
            if (activeWorkers.size >= job._concurrency) {
                await Promise.race(activeWorkers.values());
            }
        }

        // Retry failed chunks
        if (failedChunks.size > 0 && !job._cancelled && !job._paused) {
            d?._record("retry_failed_chunks", { count: failedChunks.size });
            for (const chunkIndex of failedChunks) {
                if (!job.completedChunks.has(chunkIndex)) {
                    // Reset retry state for this chunk
                    job.failedChunks.delete(chunkIndex);
                    queue.push(chunkIndex);
                }
            }
            failedChunks.clear();

            // Retry loop – same scheduler
            while ((queue.length > 0 || activeWorkers.size > 0) && !job._cancelled && !job._paused) {
                while (queue.length > 0 && activeWorkers.size < job._concurrency) {
                    const chunkIndex = queue.shift();
                    const p = worker(chunkIndex);
                    activeWorkers.set(chunkIndex, p);
                }
                if (activeWorkers.size >= job._concurrency) {
                    await Promise.race(activeWorkers.values());
                }
            }
        }

        // If any chunks still failed after retries, mark job failed
        if (failedChunks.size > 0 && !job._cancelled && !job._paused) {
            d?.recordError("runPool", new Error(`${failedChunks.size} chunks failed after retry`));
            job.status = "failed";
            job.error = `${failedChunks.size} chunks failed after retry`;
            throw new Error("Upload failed");
        }
    }

    async function uploadChunk(job, chunkIndex, debug = null) {
        const d = debug || job?._debug || null;

        d?.onChunkBlobStart(chunkIndex);
        const start = chunkIndex * job.chunkSize;
        const end = Math.min(start + job.chunkSize, job.totalBytes);
        const blob = job.file.slice(start, end);
        d?.onChunkBlobEnd(chunkIndex, blob?.size || 0);
        if (!blob) return;

        job._chunkSizes.set(chunkIndex, blob.size);
        job._inFlightLoaded.set(chunkIndex, 0);

        // Compute checksum
        const checksum = await sha256(blob);
        d?._record("chunk_checksum", { chunk: chunkIndex, checksum });

        let lastError = null;
        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (job._cancelled || job._paused) {
                throw new DOMException("Upload paused or cancelled", "AbortError");
            }

            // Exponential backoff with jitter
            if (attempt > 0) {
                const baseDelay = Math.min(1000 * Math.pow(2, attempt - 1), 8000);
                const jitter = Math.random() * 1000;
                const delay = baseDelay + jitter;
                d?.onChunkRetry(chunkIndex, attempt, delay, lastError);
                await new Promise(r => setTimeout(r, delay));
            }

            const controller = new AbortController();
            job._controllers.set(chunkIndex, controller);
            job.inFlightChunks.add(chunkIndex);

            try {
                d?.onChunkUploadStarted(chunkIndex);
                const fd = new FormData();
                fd.append("upload_id", job.uploadId);
                fd.append("chunk_index", chunkIndex);
                fd.append("chunk", blob, `chunk_${chunkIndex}`);
                fd.append("checksum", checksum);

                const response = await axios.post("/api/v1/chunk/chunk", fd, {
                    signal: controller.signal,
                    timeout: 300000,
                    onUploadProgress: (e) => {
                        d?.onChunkUploadProgress(chunkIndex, e.loaded, e.total);
                        if (e.lengthComputable) {
                            job._inFlightLoaded.set(chunkIndex, e.loaded);
                            updateProgressFromParts(job);
                        }
                    },
                });

                const serverTime = response.headers['x-server-time'];
                if (serverTime && d) {
                    d.recordServerTiming(chunkIndex, { serverTimeMs: parseFloat(serverTime) });
                }

                d?.onChunkResponseReceived(chunkIndex);
                d?.onChunkComplete(chunkIndex, 200, job.inFlightChunks.size);
                d?.sampleMemory();

                // Success - update state
                job.completedChunks.add(chunkIndex);
                job.inFlightChunks.delete(chunkIndex);
                job._controllers.delete(chunkIndex);
                job.failedChunks.delete(chunkIndex);

                const chunkSize = blob.size;
                job._completedBytesSum += chunkSize;

                job._inFlightLoaded.delete(chunkIndex);
                updateProgressFromParts(job);

                // Sample network speed
                const now = Date.now();
                if (now - job._lastTime > 200) {
                    const deltaBytes = job.uploadedBytes - job._lastLoaded;
                    if (deltaBytes > 0) {
                        const deltaSec = (now - job._lastTime) / 1000;
                        const speed = Math.round(deltaBytes / deltaSec);
                        job.speed = speed;
                        job._networkSample.push(speed);
                        if (job._networkSample.length > 10) {
                            job._networkSample.shift();
                        }
                        // Update ETA
                        const remainingBytes = job.totalBytes - job.uploadedBytes;
                        job.eta = remainingBytes > 0 ? remainingBytes / speed : 0;
                    }
                    job._lastLoaded = job.uploadedBytes;
                    job._lastTime = now;
                }

                // Persist state
                await saveJobState(job);
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

                job.failedChunks.set(chunkIndex, (job.failedChunks.get(chunkIndex) || 0) + 1);
                d?.recordError(`chunk_${chunkIndex}_attempt_${attempt}`, err);
            }
        }

        job._controllers.delete(chunkIndex);
        const finalErr = lastError || new Error(`Chunk ${chunkIndex} failed`);
        d?.recordError(`chunk_${chunkIndex}_failed_after_retries`, finalErr);
        throw finalErr;
    }

    function updateProgressFromParts(job) {
        const inFlightTotal = Array.from(job._inFlightLoaded.values()).reduce((sum, val) => sum + val, 0);
        job.uploadedBytes = job._completedBytesSum + inFlightTotal;
        job.progress = Math.min(100, Math.round((job.uploadedBytes / job.totalBytes) * 100));
    }

    async function saveJobState(job) {
        try {
            const state = {
                uploadId: job.uploadId,
                patientId: job.patientId,
                totalBytes: job.totalBytes,
                totalChunks: job.totalChunks,
                chunkSize: job.chunkSize,
                completedChunks: Array.from(job.completedChunks),
                failedChunks: Array.from(job.failedChunks.entries()),
                status: job.status,
                progress: job.progress,
                uploadedBytes: job.uploadedBytes,
                speed: job.speed,
                metadata: job.metadata,
                fileKey: fileKey(job.file),
            };
            await persistence.set('upload:' + state.fileKey, state);
        } catch (e) {
            console.warn('Failed to save upload state:', e);
        }
    }

    async function restoreJobState(job, key) {
        try {
            const state = await persistence.get('upload:' + key);
            if (state && state.uploadId) {
                job.uploadId = state.uploadId;
                job.totalBytes = state.totalBytes;
                job.totalChunks = state.totalChunks;
                job.chunkSize = state.chunkSize;
                job.completedChunks = new Set(state.completedChunks);
                job.failedChunks = new Map(state.failedChunks);
                job.uploadedBytes = state.uploadedBytes;
                job.progress = state.progress;
                job.speed = state.speed;
                return true;
            }
        } catch (e) {
            console.warn('Failed to restore upload state:', e);
        }
        return false;
    }

    // Resume an existing upload without restarting
    async function resumeUpload(id) {
        const job = uploads.value.find(u => u.id === id);
        if (!job || job.status !== "paused") return;

        const d = job._debug;
        d?._record("resume_called");

        job.status = "uploading";
        job._paused = false;
        job._cancelled = false;

        // Sync with server to get authoritative state
        d?._record("sync_server_state");
        try {
            const res = await axios.get(`/api/v1/chunk/${job.uploadId}/status`);
            const serverChunks = new Set(res.data.received_chunks || []);
            job.completedChunks = serverChunks;
            job._completedBytesSum = 0;
            job.completedChunks.forEach(idx => {
                job._completedBytesSum += getChunkSizeAt(job, idx);
            });
            updateProgressFromParts(job);
            await saveJobState(job);
            d?._record("server_state_synced", { chunks: serverChunks.size });
        } catch (e) {
            d?.recordError("resume_sync_failed", e);
            // Continue with local state if server unreachable
        }

        // Continue uploading - DO NOT call startUpload
        await runSmartPool(job, d);

        if (job._cancelled) return;

        // Completion
        d?.onMergeStart();
        const completeRes = await axios.post("/api/v1/chunk/complete", {
            upload_id: job.uploadId,
        });
        d?.onMergeComplete();

        const { addFileLocally } = useWorkspace();
        if (completeRes?.data?.uuid) {
            addFileLocally({
                uuid: completeRes.data.uuid,
                patient_id: job.patientId,
                title: job.metadata?.title || job.file?.name || "",
                desc: job.metadata?.desc || "",
                category: job.metadata?.category || "",
                file_name: job.file?.name || "",
                mime_type: job.file?.type || "application/octet-stream",
                size: job.file?.size || 0,
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                upload_status: "ready",
                url: completeRes.data.url,
                thumbnail_url: completeRes.data.thumbnail_url,
                type: completeRes.data.type,
            });
        }

        job.status = "completed";
        job.progress = 100;
        job.uploadedBytes = job.totalBytes;
        job.eta = 0;

        await persistence.remove('upload:' + fileKey(job.file));
        d?._record("upload_completed");
        d?.printReport();
        delete job.file;
    }

    function pauseUpload(id) {
        const job = uploads.value.find(u => u.id === id);
        if (!job || job.status !== "uploading") return;

        job._debug?._record("pause_called");
        job._paused = true;
        job._controllers.forEach(c => c.abort());
        job._controllers.clear();
        job.status = "paused";

        saveJobState(job);
    }

    function cancelUpload(id) {
        const job = uploads.value.find(u => u.id === id);
        if (!job) return;

        const d = job._debug;
        d?._record("cancel_called");
        job._cancelled = true;
        job._controllers.forEach(c => c.abort());
        job._controllers.clear();
        job.inFlightChunks.clear();
        job.status = "cancelled";

        if (job.uploadId) {
            axios.post(`/api/v1/chunk/${job.uploadId}/cancel`).catch(() => {});
            persistence.remove('upload:' + fileKey(job.file));
        }
    }

    function retryUpload(id) {
        const job = uploads.value.find(u => u.id === id);
        if (!job) return;

        const d = job._debug;
        d?._record("retry_called");
        job.status = "uploading";
        job.error = null;
        job._cancelled = false;
        job._paused = false;

        // Retry only failed chunks; keep completed ones
        saveJobState(job).then(() => {
            runSmartPool(job, d).then(async () => {
                if (job._cancelled) return;

                d?.onMergeStart();
                try {
                    const completeRes = await axios.post("/api/v1/chunk/complete", {
                        upload_id: job.uploadId,
                    });
                    d?.onMergeComplete();

                    const { addFileLocally } = useWorkspace();
                    if (completeRes?.data?.uuid) {
                        addFileLocally({
                            uuid: completeRes.data.uuid,
                            patient_id: job.patientId,
                            title: job.metadata?.title || job.file?.name || "",
                            desc: job.metadata?.desc || "",
                            category: job.metadata?.category || "",
                            file_name: job.file?.name || "",
                            mime_type: job.file?.type || "application/octet-stream",
                            size: job.file?.size || 0,
                            created_at: new Date().toISOString(),
                            updated_at: new Date().toISOString(),
                            upload_status: "ready",
                            url: completeRes.data.url,
                            thumbnail_url: completeRes.data.thumbnail_url,
                            type: completeRes.data.type,
                        });
                    }
                    job.status = "completed";
                    job.progress = 100;
                    job.uploadedBytes = job.totalBytes;
                    job.eta = 0;
                    await persistence.remove('upload:' + fileKey(job.file));
                    d?._record("retry_completed");
                    d?.printReport();
                    delete job.file;
                } catch (err) {
                    job.status = "failed";
                    job.error = err.response?.data?.message || err.message || "Upload failed";
                    d?.recordError("executeRetry", err);
                }
            }).catch(err => {
                job.status = "failed";
                job.error = err.message || "Upload failed";
            });
        });
    }

    function clearCompleted() {
        uploads.value = uploads.value.filter(
            u => u.status === "uploading" || u.status === "failed"
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
        formatTime,
    };
}
