// Upload Engine - adapted from v3 useUploads.js
(function() {
    const POOL_SIZE = 2;
    const MAX_RETRIES = 3;
    const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB
    const STORAGE_KEY = "upload_sessions";

    const uploads = [];
    let idCounter = 0;

    function loadPersisted() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
        } catch (e) {
            return {};
        }
    }

    function savePersisted(s) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
        } catch (e) {}
    }

    function fileKey(f) {
        if (!f) return `unknown_${Date.now()}`;
        return `${f.name || "unnamed"}_${f.size || 0}_${f.lastModified || 0}`;
    }

    function formatSize(b) {
        if (!b || b === 0) return "0 B";
        const k = 1024, sizes = ["B", "KB", "MB", "GB", "TB"];
        const i = Math.floor(Math.log(b) / Math.log(k));
        return parseFloat((b / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
    }

    function formatSpeed(bps) {
        if (!bps || bps <= 0) return "";
        return formatSize(bps) + "/s";
    }

    function formatETA(loaded, total, speed) {
        if (!speed || speed <= 0) return '';
        const remaining = (total - loaded) / speed;
        if (remaining < 5) return '~' + Math.ceil(remaining) + 's';
        if (remaining < 60) return '~' + Math.round(remaining) + 's';
        const mins = Math.floor(remaining / 60);
        const secs = Math.round(remaining % 60);
        return `~${mins}m ${secs}s`;
    }

    // Event emitter for upload events
    const EventBus = {
        listeners: {},
        on(event, callback) {
            if (!this.listeners[event]) this.listeners[event] = [];
            this.listeners[event].push(callback);
        },
        emit(event, data) {
            if (this.listeners[event]) this.listeners[event].forEach(cb => cb(data));
        }
    };

    function createJob(file, patientId, metadata = {}) {
        const id = ++idCounter;
        const job = {
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
            _lastLoaded: 0,
            _lastTime: Date.now(),
            _chunkSizes: new Map(),
            _inFlightLoaded: new Map(),
            _completedBytesSum: 0,
        };
        uploads.push(job);
        return job;
    }

    async function uploadFile(file, patientId, metadata = {}) {
        const job = createJob(file, patientId, metadata);
        await startUpload(job);
        return job;
    }

    async function startUpload(job) {
        const persisted = loadPersisted();
        const key = fileKey(job.file);
        let uploadId = null;
        let completedSet = new Set();

        try {
            // Resume check
            if (persisted[key] && persisted[key].status === "uploading") {
                try {
                    const r = await fetch(`/api/v1/chunk/${persisted[key].upload_id}/status`, {
                        headers: { 'Accept': 'application/json' }
                    }).then(res => res.json());
                    if (r.status === "uploading" || r.status === "pending") {
                        uploadId = r.uuid;
                        completedSet = new Set(r.received_chunks || []);
                        EventBus.emit('log', { type: 'resume', uuid: uploadId, chunks: completedSet.size });
                    }
                } catch (e) {
                    delete persisted[key];
                    savePersisted(persisted);
                }
            }

            if (!uploadId) {
                const meta = {};
                if (metadata.title) meta.title = metadata.title;
                if (metadata.desc) meta.desc = metadata.desc;
                if (metadata.category) meta.category = metadata.category;
                if (metadata.date) meta.date = metadata.date;

                const initRes = await fetch('/api/v1/chunk/init', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        file_name: job.file.name,
                        file_size: job.file.size,
                        mime_type: job.file.type || "application/octet-stream",
                        patient_id: patientId,
                        chunk_size: CHUNK_SIZE,
                        metadata: Object.keys(meta).length ? meta : undefined
                    })
                }).then(res => res.json());

                uploadId = initRes.upload_id;
                job.chunkSize = initRes.chunk_size;
                EventBus.emit('log', { type: 'init', uuid: uploadId, chunkSize: job.chunkSize });
            }

            job.uploadId = uploadId;
            job.chunkSize ??= CHUNK_SIZE;
            job.totalChunks = Math.ceil(job.file.size / job.chunkSize);
            job.completedChunks = completedSet;

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
                upload_id: uploadId,
                file_name: job.file.name,
                file_size: job.file.size,
                patient_id: patientId,
                total_chunks: job.totalChunks,
                status: "uploading"
            };
            savePersisted(persisted);

            await runPool(job);

            if (job._cancelled) {
                EventBus.emit('log', { type: 'cancelled_after_pool' });
                return;
            }

            // Complete
            const completeRes = await fetch('/api/v1/chunk/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ upload_id: job.uploadId })
            }).then(res => res.json());

            job.status = "completed";
            job.progress = 100;
            job.fileData = completeRes; // contains uuid, url, thumbnail_url, type

            delete persisted[key];
            savePersisted(persisted);
            EventBus.emit('complete', job);
        } catch (err) {
            if (err.name === "CanceledError" || err.name === "AbortError") {
                job.status = "cancelled";
                EventBus.emit('cancelled', job);
            } else {
                job.status = "failed";
                job.error = err.message || "Upload failed";
                EventBus.emit('failed', job);
            }
        }
    }

    function updateProgressFromParts(job) {
        const inFlightTotal = Array.from(job._inFlightLoaded.values()).reduce((sum, val) => sum + val, 0);
        job.uploadedBytes = job._completedBytesSum + inFlightTotal;
        job.progress = Math.min(100, Math.round((job.uploadedBytes / job.totalBytes) * 100));
    }

    async function runPool(job) {
        const pool = new Set();

        function* missingChunks() {
            for (let i = 0; i < job.totalChunks; i++) {
                if (!job.completedChunks.has(i)) yield i;
            }
        }

        const errors = [];
        async function uploadSafe(chunkIndex) {
            try {
                await uploadChunk(job, chunkIndex);
            } catch (err) {
                errors.push(err);
            }
        }

        for (const chunkIndex of missingChunks()) {
            if (job._cancelled || job._paused) break;
            if (pool.size >= POOL_SIZE) {
                await Promise.race(pool);
                if (job._cancelled || job._paused) break;
            }
            const p = uploadSafe(chunkIndex).finally(() => pool.delete(p));
            pool.add(p);
        }

        if (pool.size > 0) {
            await Promise.allSettled(Array.from(pool));
        }

        if (errors.length > 0 && !job._cancelled && !job._paused) {
            job.status = "failed";
            job.error = errors[0].message || "One or more chunks failed";
            throw errors[0];
        }
    }

    async function uploadChunk(job, chunkIndex) {
        const start = chunkIndex * job.chunkSize;
        const end = Math.min(start + job.chunkSize, job.totalBytes);
        const blob = job.file.slice(start, end);

        job._chunkSizes.set(chunkIndex, blob.size);
        job._inFlightLoaded.set(chunkIndex, 0);

        let lastError = null;
        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (job._cancelled || job._paused) {
                throw new DOMException("Upload paused or cancelled", "AbortError");
            }
            if (attempt > 0) {
                const delay = Math.min(1000 * Math.pow(2, attempt - 1), 8000);
                await new Promise(r => setTimeout(r, delay));
            }

            const controller = new AbortController();
            job._controllers.set(chunkIndex, controller);
            job.inFlightChunks.add(chunkIndex);

            try {
                const fd = new FormData();
                fd.append("upload_id", job.uploadId);
                fd.append("chunk_index", chunkIndex);
                fd.append("chunk", blob, `chunk_${chunkIndex}`);

                const response = await fetch('/api/v1/chunk/chunk', {
                    method: 'POST',
                    body: fd,
                    signal: controller.signal
                });

                if (!response.ok) throw new Error(`Chunk failed: ${response.status}`);

                const result = await response.json();

                job.completedChunks.add(chunkIndex);
                job.inFlightChunks.delete(chunkIndex);
                job._controllers.delete(chunkIndex);
                job.failedChunks.delete(chunkIndex);

                const chunkSize = job._chunkSizes.get(chunkIndex) || job.chunkSize;
                job._completedBytesSum += chunkSize;

                job._inFlightLoaded.delete(chunkIndex);
                updateProgressFromParts(job);

                // speed calculation
                const now = Date.now();
                if (now - job._lastTime > 200) {
                    const db = job.uploadedBytes - job._lastLoaded;
                    job.speed = db > 0 ? Math.round(db / ((now - job._lastTime) / 1000)) : 0;
                    job._lastLoaded = job.uploadedBytes;
                    job._lastTime = now;
                }
                return;
            } catch (err) {
                lastError = err;
                job.inFlightChunks.delete(chunkIndex);
                job._inFlightLoaded.delete(chunkIndex);
                job._controllers.delete(chunkIndex);
                if (err.name === "CanceledError" || err.name === "AbortError") {
                    throw err;
                }
                job.failedChunks.set(chunkIndex, attempt + 1);
            }
        }

        throw lastError || new Error(`Chunk ${chunkIndex} failed`);
    }

    function cancelUpload(job) {
        job._cancelled = true;
        job._controllers.forEach(c => c.abort());
        job._controllers.clear();
        job.inFlightChunks.clear();
        job.status = "cancelled";
        if (job.uploadId) {
            fetch(`/api/v1/chunk/${job.uploadId}/cancel`, { method: 'POST' }).catch(() => {});
            const p = loadPersisted();
            for (const k of Object.keys(p)) {
                if (p[k].upload_id === job.uploadId) delete p[k];
            }
            savePersisted(p);
        }
    }

    function pauseUpload(job) {
        if (job.status !== "uploading") return;
        job._paused = true;
        job._controllers.forEach(c => c.abort());
        job._controllers.clear();
        job.status = "paused";
    }

    function resumeUpload(job) {
        if (job.status !== "paused") return;
        job.status = "uploading";
        job._paused = false;
        job._cancelled = false;
        startUpload(job); // retry entire upload; could be smarter but matches v3 behavior
    }

    function retryUpload(job) {
        if (!job) return;
        job.status = "uploading";
        job.error = null;
        job._cancelled = false;
        job._paused = false;
        job.failedChunks.clear();
        startUpload(job);
    }

    function clearCompleted() {
        for (let i = uploads.length - 1; i >= 0; i--) {
            if (uploads[i].status === "completed" || uploads[i].status === "failed") {
                uploads.splice(i, 1);
            }
        }
    }

    // API
    window.UploadEngine = {
        upload: uploadFile,
        cancel: cancelUpload,
        pause: pauseUpload,
        resume: resumeUpload,
        retry: retryUpload,
        clearCompleted,
        getUploads: () => uploads,
        formatSize,
        formatSpeed,
        formatETA,
        EventBus
    };
})();
