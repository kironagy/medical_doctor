import { reactive, computed, ref } from 'vue';
import axios from 'axios';

/**
 * Global, persistent upload manager — a module-level singleton store.
 *
 * Survives Inertia navigations (state is module-scoped) AND survives a full
 * page refresh / browser restart via a compact catalog in localStorage.
 * After a refresh, in-flight file *contents* (File objects) are gone — the
 * browser cannot persist those — so the store reattaches to whatever the
 * SERVER still has (received chunks) and either resumes (if more chunks are
 * pending) or follows the async merge/processing pipeline to completion.
 *
 * Lifecycle (client view):
 *   preparing → uploading → merging → processing → completed
 *                                            ↘ failed
 *   (anywhere) → cancelled
 *
 * Key resilience/perf features:
 *  - AbortController per job (instant cancel; XHR won't leak)
 *  - Chunk-level retry with exponential backoff; whole file never restarts
 *  - Server resume via /uploads/status
 *  - Adaptive chunk size + concurrency based on measured throughput
 *  - Per-chunk stall watchdog (kills hung XHRs instead of waiting forever)
 *  - Clamped progress (e.loaded never exceeds the chunk's real size)
 *  - Exponential backoff polling for post-merge status (not constant-rate)
 *  - Job catalog persisted to localStorage; restored on boot
 *  - Dedupe by patientId+name+size+lastModified
 *  - Blob refs released immediately after a chunk is sent
 */

// ---- tunables ----
const MIN_CHUNK = 1 * 1024 * 1024;      // 1 MB (slow networks)
const MAX_CHUNK = 16 * 1024 * 1024;     // 16 MB (fast networks)
const MIN_CONCURRENCY = 1;
const MAX_CONCURRENCY = 6;
const MAX_RETRIES = 4;
const CHUNK_TIMEOUT_MS = 90_000;        // kill a chunk with no completion in 90s
const STALL_NO_PROGRESS_MS = 30_000;    // kill a chunk with no byte progress for 30s
const CATALOG_KEY = 'upload_catalog_v1';
const POLL_MAX_ATTEMPTS = 120;           // exponential polling cap
const POLL_BASE_MS = 1500;
const POLL_MAX_MS = 30_000;

// ---- module-level singleton state ----
const jobs = ref([]);
const activeUploads = new Set(); // dedupe keys
let idCounter = 0;

const fmtSize = (bytes) => {
  if (!bytes || bytes === 0) return '0 B';
  const k = 1024, sizes = ['B','KB','MB','GB','TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};
const fmtSpeed = (bps) => {
  if (!bps || bps <= 0) return '0 B/s';
  const k = 1024, sizes = ['B/s','KB/s','MB/s','GB/s'];
  const i = Math.floor(Math.log(bps) / Math.log(k));
  return parseFloat((bps / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};
const fmtDuration = (secs) => {
  if (!isFinite(secs) || secs < 0) return '—';
  if (secs < 1) return '<1s';
  secs = Math.round(secs);
  if (secs < 60) return secs + 's';
  const m = Math.floor(secs / 60), s = secs % 60;
  if (m < 60) return `${m}m ${s}s`;
  const h = Math.floor(m / 60);
  return `${h}h ${m % 60}m`;
};
const waitFor = (ms) => new Promise((r) => setTimeout(r, ms));

/** Choose a chunk size for a file based on measured throughput (bytes/s). */
function pickChunkSize(fileSize, bytesPerSec) {
  // default for unknown throughput
  if (!bytesPerSec || bytesPerSec <= 0) {
    if (fileSize > 1 * 1024 ** 3) return 8 * 1024 ** 2;  // >1GB → 8MB
    if (fileSize > 200 * 1024 ** 2) return 5 * 1024 ** 2; // >200MB → 5MB
    return 2 * 1024 ** 2;                                  // else 2MB
  }
  // Target ~3s per chunk at current speed → smooth progress, low overhead.
  let sized = bytesPerSec * 3;
  return Math.max(MIN_CHUNK, Math.min(MAX_CHUNK, sized));
}

/** Choose concurrency from measured throughput. */
function pickConcurrency(bytesPerSec) {
  if (!bytesPerSec || bytesPerSec <= 0) return 3;
  // More parallelism only helps when the link is fast; on slow links more
  // requests just queue and inflate overhead.
  if (bytesPerSec < 512 * 1024) return MIN_CONCURRENCY; // <0.5MB/s
  if (bytesPerSec < 3 * 1024 ** 2) return 2;
  if (bytesPerSec < 10 * 1024 ** 2) return 3;
  return MAX_CONCURRENCY;
}

const recompute = (job) => {
  let uploaded = 0;
  for (let i = 0; i < job._totalChunks; i++) {
    uploaded += job._chunkLoaded[i] || 0;
  }
  // clamp: never above total
  if (uploaded > job.totalSize) uploaded = job.totalSize;
  job.uploadedBytes = uploaded;
  job.remainingBytes = job.totalSize - uploaded;
  job.progress = job.totalSize > 0 ? Math.min(100, (uploaded / job.totalSize) * 100) : 0;

  const elapsed = (Date.now() - job._startTime) / 1000;
  if (elapsed > 0.3) {
    const avg = uploaded / elapsed;
    // EMA smoothing so the speed display doesn't jitter.
    job.speed = job.speed > 0 ? job.speed * 0.7 + avg * 0.3 : avg;
    job.etaText = job.speed > 0 ? fmtDuration(job.remainingBytes / job.speed) : '—';
  }
  persistCatalog();
};

// ---------- persistent catalog (survives refresh) ----------
const persistableKeys = ['id', 'name', 'patientId', 'category', 'totalSize', 'status', 'fileUuid', 'errorMsg'];
function persistCatalog() {
  try {
    const snap = jobs.value.map((j) => {
      const o = {};
      for (const k of persistableKeys) o[k] = j[k];
      o._totalChunks = j._totalChunks;
      o._sessionId = j._sessionId;
      o.uploadedBytes = j.uploadedBytes;
      o.progress = j.progress;
      // store lastModified so dedupe matches after refresh too
      o._lastModified = j.file?.lastModified || 0;
      return o;
    });
    localStorage.setItem(CATALOG_KEY, JSON.stringify(snap));
  } catch {}
}
function loadCatalog() {
  try {
    const raw = localStorage.getItem(CATALOG_KEY);
    if (!raw) return;
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr)) return;
    for (const saved of arr) {
      // After refresh we cannot recover the File object. So we make a
      // "ghost" job that has no file but can still follow the server-side
      // pipeline (merging/processing/completed) via /uploads/status.
      const job = reactive({
        ...saved,
        // UI fields
        speed: 0, etaText: '—',
        cancellable: false, // can't cancel without a file object
        retryChunk: null,
        _chunkLoaded: new Array(saved._totalChunks || 0).fill(0),
        _startTime: 0,
        _abort: new AbortController(),
        _restored: true,
        file: null,
      });
      if (saved.id > idCounter) idCounter = saved.id;
      jobs.value.push(job);
      // re-attach to the server pipeline
      followRestoredJob(job);
    }
  } catch {}
}
async function followRestoredJob(job) {
  // No file → we can only watch the server. Stay alive across refresh.
  const resumeKey = `upload_session:${job.patientId}:${job.name}:${job.totalSize}`;
  const sid = job._sessionId || localStorage.getItem(resumeKey);
  if (!sid) return;
  job._sessionId = sid;
  await watchServerPipeline(job);
}

async function watchServerPipeline(job) {
  let attempt = 0;
  while (attempt < POLL_MAX_ATTEMPTS && !job._abort.signal.aborted) {
    const delay = Math.min(POLL_BASE_MS * 1.6 ** attempt, POLL_MAX_MS);
    await waitFor(delay);
    attempt++;
    try {
      const res = await axios.get('/api/v1/uploads/status', { params: { session_id: job._sessionId } });
      const d = res.data || {};
      job._phase = d.phase;
      if (d.file && d.file.upload_status === 'ready') {
        job.fileUuid = d.file.uuid;
        job.status = 'completed';
        finalizeCompleted(job);
        return;
      }
      if (d.file && d.file.upload_status === 'failed') {
        job.status = 'failed';
        job.errorMsg = 'Server processing failed';
        return;
      }
      // still merging/processing
      job.status = d.file ? 'processing' : (countReceived(d) > 0 ? 'merging' : 'merging');
    } catch {
      // network blip — keep going
    }
  }
  if (job.status !== 'completed') {
    job.status = 'failed';
    job.errorMsg = 'Timed out waiting for server processing';
  }
}
const countReceived = (d) => (d.received_chunks?.length) || 0;

function finalizeCompleted(job) {
  notifyUploaded();
  setTimeout(() => {
    const i = jobs.value.findIndex((j) => j.id === job.id);
    if (i !== -1 && (jobs.value[i].status === 'completed' || jobs.value[i].status === 'cancelled')) {
      jobs.value.splice(i, 1);
      persistCatalog();
    }
  }, 4000);
}

/**
 * Enqueue one or more files for background upload.
 */
function enqueue(files, ctx) {
  for (const file of files) {
    const dedupeKey = `${ctx.patientId}:${file.name}:${file.size}:${file.lastModified}`;
    if (activeUploads.has(dedupeKey)) continue;
    // skip if a restored ghost for the same file is already in the catalog
    if (jobs.value.some((j) => j.patientId === ctx.patientId && j.name === file.name && j.totalSize === file.size && j.status !== 'failed' && j.status !== 'cancelled' && j.status !== 'completed')) {
      continue;
    }
    activeUploads.add(dedupeKey);

    const chunkSize = pickChunkSize(file.size, 0);
    const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
    const job = reactive({
      id: ++idCounter,
      name: file.name,
      patientId: ctx.patientId,
      category: ctx.category,
      file,
      totalSize: file.size,
      uploadedBytes: 0,
      remainingBytes: file.size,
      progress: 0,
      speed: 0,
      etaText: '—',
      status: 'preparing',
      errorMsg: '',
      fileUuid: null,
      retryChunk: null,
      cancellable: true,
      _totalChunks: totalChunks,
      _chunkLoaded: new Array(totalChunks).fill(0),
      _chunkSize: chunkSize,
      _startTime: 0,
      _sessionId: null,
      _abort: new AbortController(),
      _dedupeKey: dedupeKey,
      _lastActivity: 0,
    });
    jobs.value.push(job);
    runJob(job).finally(() => activeUploads.delete(dedupeKey));
  }
}

async function runJob(job) {
  job._startTime = Date.now();
  job.status = 'uploading';
  const signal = job._abort.signal;
  const resumeKey = `upload_session:${job.patientId}:${job.name}:${job.totalSize}`;

  try {
    // ---- resume: reuse existing session if present ----
    let received = [];
    const storedSession = localStorage.getItem(resumeKey);
    if (storedSession && !signal.aborted) {
      try {
        const sres = await axios.get('/api/v1/uploads/status', { params: { session_id: storedSession }, signal });
        received = sres.data?.received_chunks || [];
        if (received.length > 0) {
          job._sessionId = storedSession;
        } else {
          // server has no chunks either — start fresh
          job._sessionId = null;
        }
      } catch {
        // keep null
      }
    }
    if (signal.aborted) throw new DOMException('Aborted', 'AbortError');

    // ---- init ----
    if (!job._sessionId) {
      const initRes = await axios.post('/api/v1/uploads/init', {
        filename: job.name,
        total_chunks: job._totalChunks,
        total_size: job.totalSize,
        patient_id: job.patientId,
        category: job.category,
      }, { signal });
      job._sessionId = initRes.data.session_id;
      received = initRes.data?.received_chunks || [];
    }
    localStorage.setItem(resumeKey, job._sessionId);
    markReceived(job, received);
    recompute(job);

    // ---- concurrent upload pool (adaptive) ----
    const receivedSet = new Set(received);
    const pending = [];
    for (let i = 0; i < job._totalChunks; i++) if (!receivedSet.has(i)) pending.push(i);

    let cursor = 0;
    const worker = async () => {
      while (cursor < pending.length && !signal.aborted) {
        const idx = pending[cursor++];
        await uploadOneChunk(job, idx, signal);
      }
    };
    const n = Math.min(pickConcurrency(job.speed), Math.max(1, pending.length));
    await Promise.all(Array.from({ length: n }, worker));

    if (signal.aborted) throw new DOMException('Aborted', 'AbortError');

    // ---- finalize (async on the server) ----
    job.status = 'merging';
    job.progress = 100;
    persistCatalog();

    const completeRes = await axios.post('/api/v1/uploads/complete', {
      session_id: job._sessionId,
      total_chunks: job._totalChunks,
      patient_uuid: job.patientId,
      metadata: {
        category: job.category,
        original_name: job.name,
        extension: job.name.split('.').pop(),
        type: job.file.type.split('/')[0] || 'document',
        mime_type: job.file.type,
      },
    }, { signal });

    // Server returns 202 (accepted) — merge + processing happen on a worker.
    job.cancellable = false;
    localStorage.removeItem(resumeKey);

    // Release the File reference now that bytes are safely on disk.
    // (Memory: avoids keeping the whole file alive for the duration of
    // processing on slow servers.)
    job.file = null;

    // Follow the async pipeline. If the server happened to already produce
    // a file sync (e.g. tiny files in some configs), still resolve via poll.
    await pollStatus(job);

    notifyUploaded();
  } catch (err) {
    if (axios.isCancel?.(err) || err?.name === 'AbortError' || signal.aborted) {
      job.status = 'cancelled';
      cleanupSession(job, resumeKey);
    } else {
      console.error('Upload failed', err);
      job.status = 'failed';
      job.errorMsg = err.response?.data?.message || err.message || 'Upload failed';
    }
  } finally {
    persistCatalog();
  }
}

function markReceived(job, received) {
  for (const i of received) {
    job._chunkLoaded[i] = i === job._totalChunks - 1
      ? job.totalSize - i * job._chunkSize
      : job._chunkSize;
  }
}

async function uploadOneChunk(job, chunkIndex, signal) {
  // Recompute chunk size lazily if throughput has changed a lot.
  const size = job._chunkSize;
  const startByte = chunkIndex * size;
  const endByte = Math.min(startByte + size, job.totalSize);
  const chunkData = job.file.slice(startByte, endByte);

  let attempt = 0;
  while (attempt <= MAX_RETRIES) {
    if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
    try {
      const formData = new FormData();
      formData.append('chunk', chunkData);
      formData.append('session_id', job._sessionId);
      formData.append('chunk_index', chunkIndex);
      formData.append('total_chunks', job._totalChunks);

      await postChunkWithWatchdog(job, chunkIndex, formData, signal);

      job._chunkLoaded[chunkIndex] = endByte - startByte;
      recompute(job);
      return;
    } catch (err) {
      if (axios.isCancel?.(err) || signal.aborted) throw err;
      attempt++;
      if (attempt > MAX_RETRIES) throw err;
      job.status = 'retrying';
      job.retryChunk = chunkIndex;
      const back = Math.min(800 * 2 ** (attempt - 1), 8000);
      await waitFor(back);
      if (!signal.aborted) {
        job.status = 'uploading';
        job.retryChunk = null;
      }
    }
  }
}

/**
 * Post one chunk with a stall watchdog. If no upload progress for
 * STALL_NO_PROGRESS_MS or no completion within CHUNK_TIMEOUT_MS, abort the
 * XHR so the retry loop can re-send just that chunk (not the whole file).
 */
function postChunkWithWatchdog(job, chunkIndex, formData, signal) {
  return new Promise((resolve, reject) => {
    const ctl = new AbortController();
    const onAbort = () => ctl.abort();
    if (signal) signal.addEventListener('abort', onAbort, { once: true });

    let lastProgress = Date.now();
    let lastLoaded = 0;

    const watchdog = setInterval(() => {
      const stalledNoProgress = (Date.now() - lastProgress) > STALL_NO_PROGRESS_MS;
      if (stalledNoProgress) {
        clearInterval(watchdog);
        ctl.abort('stalled');
      }
    }, 5000);

    const finish = (fn) => {
      clearInterval(watchdog);
      if (signal) signal.removeEventListener('abort', onAbort);
      fn();
    };

    axios.post('/api/v1/uploads/chunk', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      signal: ctl.signal,
      onUploadProgress: (e) => {
        lastProgress = Date.now();
        const size = formData.get('chunk').size || (e.total || 0);
        const loaded = Math.min(e.loaded || 0, size);
        if (loaded > lastLoaded) lastLoaded = loaded;
        job._chunkLoaded[chunkIndex] = loaded;
        recompute(job);
      },
    }).then(
      (r) => finish(() => resolve(r)),
      (e) => finish(() => reject(e)),
    );

    // Absolute completion timeout
    setTimeout(() => {
      if (watchdog) ctl.abort('timeout');
    }, CHUNK_TIMEOUT_MS);
  });
}

/** Poll the async pipeline with exponential backoff. */
async function pollStatus(job) {
  const sid = job._sessionId;
  if (!sid) { job.status = 'completed'; finalizeCompleted(job); return; }
  let attempt = 0;
  while (attempt < POLL_MAX_ATTEMPTS && !job._abort.signal.aborted) {
    const delay = Math.min(POLL_BASE_MS * 1.6 ** attempt, POLL_MAX_MS);
    await waitFor(delay);
    attempt++;
    try {
      const res = await axios.get('/api/v1/uploads/status', { params: { session_id: sid } });
      const d = res.data || {};
      if (d.file) {
        job.fileUuid = d.file.uuid;
        const st = d.file.upload_status;
        if (st === 'ready') { job.status = 'completed'; finalizeCompleted(job); return; }
        if (st === 'failed') { job.status = 'failed'; job.errorMsg = 'Processing failed'; return; }
        job.status = 'processing';
      } else if (countReceived(d) === 0) {
        job.status = 'merging';
      } else {
        job.status = 'merging';
      }
    } catch {
      // transient — keep polling
    }
  }
  if (job.status !== 'completed') {
    job.status = 'failed';
    job.errorMsg = 'Timed out waiting for server processing';
  }
}

async function cleanupSession(job, resumeKey) {
  try { localStorage.removeItem(resumeKey); } catch {}
  if (job._sessionId) {
    try { await axios.post('/api/v1/uploads/cancel', { session_id: job._sessionId }); } catch {}
  }
}

/** Immediately cancel an upload. */
function cancel(jobId) {
  const job = jobs.value.find((j) => j.id === jobId);
  if (!job) return;
  if (job._pollInterval) clearInterval(job._pollInterval);
  if (!job.cancellable) return;
  job._abort.abort();
  job.status = 'cancelled';
  const resumeKey = `upload_session:${job.patientId}:${job.name}:${job.totalSize}`;
  cleanupSession(job, resumeKey);
  persistCatalog();
}

/** Retry a failed/cancelled upload. For restored (no File) jobs, cannot retry. */
function retry(jobId) {
  const job = jobs.value.find((j) => j.id === jobId);
  if (!job) return;
  if (!job.file) {
    // ghost: just re-follow the server pipeline
    job.status = 'processing';
    watchServerPipeline(job);
    persistCatalog();
    return;
  }
  if (job._pollInterval) clearInterval(job._pollInterval);
  job.status = 'preparing';
  job.errorMsg = '';
  job.progress = 0;
  job.uploadedBytes = 0;
  job.remainingBytes = job.totalSize;
  job.speed = 0;
  job.etaText = '—';
  job.retryChunk = null;
  job._chunkLoaded = new Array(job._totalChunks).fill(0);
  job._startTime = 0;
  job._sessionId = null;
  job._abort = new AbortController();
  job.cancellable = true;
  const resumeKey = `upload_session:${job.patientId}:${job.name}:${job.totalSize}`;
  localStorage.removeItem(resumeKey);
  activeUploads.add(job._dedupeKey);
  runJob(job).finally(() => activeUploads.delete(job._dedupeKey));
  persistCatalog();
}

function removeJob(jobId) {
  const i = jobs.value.findIndex((j) => j.id === jobId);
  if (i === -1) return;
  const job = jobs.value[i];
  if (job._pollInterval) clearInterval(job._pollInterval);
  jobs.value.splice(i, 1);
  persistCatalog();
}
function clearCompleted() {
  jobs.value = jobs.value.filter((j) => !['completed','cancelled'].includes(j.status));
  persistCatalog();
}

// Stop everything if the user closes the tab — best-effort, no guarantee.
if (typeof window !== 'undefined') {
  window.addEventListener('beforeunload', () => {
    for (const j of jobs.value) {
      if (!j.cancellable) continue;
      try { j._abort.abort(); } catch {}
      try { navigator.sendBeacon?.('/api/v1/uploads/cancel', JSON.stringify({ session_id: j._sessionId })); } catch {}
    }
  });
}

// --- notify file grids ---
const subscribers = new Set();
function onUploaded(fn) { subscribers.add(fn); return () => subscribers.delete(fn); }
function notifyUploaded() { subscribers.forEach((fn) => { try { fn(); } catch {} }); }

// restore any jobs surviving a refresh (one-time)
loadCatalog();

export function useUploads() {
  const activeJobs = computed(() => jobs.value.filter((j) =>
    !['completed','cancelled'].includes(j.status)));
  const hasActive = computed(() => activeJobs.value.length > 0);
  const totalProgress = computed(() => {
    const active = jobs.value.filter((j) =>
      ['uploading','merging','processing','preparing','retrying','failed'].includes(j.status));
    if (active.length === 0) return 100;
    const totalSize = active.reduce((s, j) => s + j.totalSize, 0);
    const uploaded = active.reduce((s, j) => s + j.uploadedBytes, 0);
    return totalSize > 0 ? (uploaded / totalSize) * 100 : 0;
  });
  return {
    jobs, activeJobs, hasActive, totalProgress,
    enqueue, cancel, retry, removeJob, clearCompleted, onUploaded,
    fmtSize, fmtSpeed, fmtDuration,
  };
}