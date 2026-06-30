import { reactive, computed, ref } from 'vue';
import axios from 'axios';

/**
 * Upload manager — medical clinic document storage.
 *
 * State machine (per job):
 *
 *   preparing ──► uploading ──► merging ──► processing ──► completed
 *                    │              │            │
 *                    ▼              ▼            ▼
 *                 retrying       failed        failed
 *                    │
 *                    ▼
 *                 uploading  (resumes)
 *
 *   (any state) ──► cancelled
 *   failed      ──► preparing  (retry)
 *
 * Transitions are ALWAYS driven by server state, never by client assumption.
 *
 * Refresh recovery rules (applied immediately on boot, NO assumed state):
 *   • Server has a ready/queued/processing file  → follow processing pipeline
 *   • Server has chunks on disk (upload interrupted) → failed + retry available
 *   • Server has nothing (session gone)           → failed + retry available
 *   • Server error / unreachable                  → failed + retry available
 *
 * The client NEVER assumes "merging" after a refresh. Only a live /complete
 * response from the current session transitions the job into "merging".
 */

// ── tunables ──────────────────────────────────────────────────────────────────
const MIN_CHUNK           = 1  * 1024 * 1024;   // 1 MB
const MAX_CHUNK           = 16 * 1024 * 1024;   // 16 MB
const MIN_CONCURRENCY     = 1;
const MAX_CONCURRENCY     = 6;
const MAX_RETRIES         = 4;
const CHUNK_TIMEOUT_MS    = 90_000;
const STALL_MS            = 30_000;
const CATALOG_KEY         = 'upload_catalog_v2'; // bumped — old v1 format incompatible
const POLL_MAX_ATTEMPTS   = 120;
const POLL_BASE_MS        = 1_500;
const POLL_MAX_MS         = 30_000;

// ── module singleton ──────────────────────────────────────────────────────────
const jobs         = ref([]);
const activeUploads = new Set();
let   idCounter    = 0;

// ── formatters ────────────────────────────────────────────────────────────────
const fmtSize = (b) => {
  if (!b) return '0 B';
  const k = 1024, s = ['B','KB','MB','GB','TB'], i = Math.floor(Math.log(b)/Math.log(k));
  return parseFloat((b/Math.pow(k,i)).toFixed(1)) + ' ' + s[i];
};
const fmtSpeed = (b) => {
  if (!b || b <= 0) return '0 B/s';
  const k = 1024, s = ['B/s','KB/s','MB/s','GB/s'], i = Math.floor(Math.log(b)/Math.log(k));
  return parseFloat((b/Math.pow(k,i)).toFixed(1)) + ' ' + s[i];
};
const fmtDuration = (secs) => {
  if (!isFinite(secs) || secs < 0) return '—';
  if (secs < 1) return '<1s';
  secs = Math.round(secs);
  if (secs < 60) return secs + 's';
  const m = Math.floor(secs / 60), s = secs % 60;
  if (m < 60) return `${m}m ${s}s`;
  return `${Math.floor(m/60)}h ${m%60}m`;
};
const waitFor = (ms) => new Promise(r => setTimeout(r, ms));

// ── chunk/concurrency sizing ──────────────────────────────────────────────────
function pickChunkSize(fileSize, bps) {
  if (!bps || bps <= 0) {
    if (fileSize > 1024 ** 3)       return 8  * 1024 ** 2;
    if (fileSize > 200 * 1024 ** 2) return 5  * 1024 ** 2;
    return 2 * 1024 ** 2;
  }
  return Math.max(MIN_CHUNK, Math.min(MAX_CHUNK, bps * 3));
}
function pickConcurrency(bps) {
  if (!bps || bps <= 0)          return 3;
  if (bps < 512  * 1024)         return MIN_CONCURRENCY;
  if (bps < 3    * 1024 ** 2)    return 2;
  if (bps < 10   * 1024 ** 2)    return 3;
  return MAX_CONCURRENCY;
}

// ── progress recompute ────────────────────────────────────────────────────────
const recompute = (job) => {
  let up = 0;
  for (let i = 0; i < job._totalChunks; i++) up += job._chunkLoaded[i] || 0;
  up = Math.min(up, job.totalSize);
  job.uploadedBytes  = up;
  job.remainingBytes = job.totalSize - up;
  job.progress       = job.totalSize > 0 ? Math.min(100, (up / job.totalSize) * 100) : 0;
  const elapsed = (Date.now() - job._startTime) / 1000;
  if (elapsed > 0.3) {
    const avg = up / elapsed;
    job.speed   = job.speed > 0 ? job.speed * 0.7 + avg * 0.3 : avg;
    job.etaText = job.speed > 0 ? fmtDuration(job.remainingBytes / job.speed) : '—';
  }
  persistCatalog();
};

// ── catalog persistence ───────────────────────────────────────────────────────
const PERSIST_KEYS = ['id','name','patientId','category','totalSize','status','fileUuid','errorMsg'];

function persistCatalog() {
  try {
    const snap = jobs.value.map(j => {
      const o = {};
      for (const k of PERSIST_KEYS) o[k] = j[k];
      o._totalChunks  = j._totalChunks;
      o._chunkSize    = j._chunkSize;
      o._sessionId    = j._sessionId;
      o.uploadedBytes = j.uploadedBytes;
      o.progress      = j.progress;
      o._lastModified = j.file?.lastModified || 0;
      return o;
    });
    localStorage.setItem(CATALOG_KEY, JSON.stringify(snap));
  } catch {}
}

// ── catalog restore (runs once on module load) ────────────────────────────────
function loadCatalog() {
  try {
    const raw = localStorage.getItem(CATALOG_KEY);
    if (!raw) return;
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr)) return;

    for (const saved of arr) {
      // Skip jobs that were already in a terminal state — no point restoring them.
      if (['completed','cancelled'].includes(saved.status)) continue;

      const job = reactive({
        ...saved,
        // Runtime fields — file object is gone after refresh.
        file:        null,
        speed:       0,
        etaText:     '—',
        cancellable: false,   // can't cancel a ghost (no File to abort)
        retryChunk:  null,
        _chunkLoaded: new Array(saved._totalChunks || 0).fill(0),
        _startTime:  0,
        _abort:      new AbortController(),
        _restored:   true,
      });

      if (saved.id > idCounter) idCounter = saved.id;
      jobs.value.push(job);

      // Immediately interrogate the server — never trust the saved status.
      recoverJob(job);
    }
  } catch {}
}

/**
 * recoverJob — the ONLY place that decides what happens to a restored job.
 *
 * Calls /uploads/status ONCE immediately (no delay), then:
 *
 *   Case A: file exists + status=ready             → completed
 *   Case B: file exists + status=queued/processing → follow processing pipeline
 *   Case C: file exists + status=failed            → failed
 *   Case D: no file, chunks > 0 on disk            → upload was interrupted
 *                                                     → failed + show retry
 *   Case E: no file, 0 chunks (session gone)       → session expired/deleted
 *                                                     → failed + show retry
 *   Case F: network error / server error            → failed + show retry
 *
 * Rule: we NEVER assume merging after a refresh. Only a live /complete 202
 * response (from the same browser tab, same session) may transition to merging.
 */
async function recoverJob(job) {
  const sid = job._sessionId;

  // No session ID saved — cannot recover anything.
  if (!sid) {
    markFailed(job, 'Session lost after refresh. Please re-upload.');
    return;
  }

  try {
    const res = await axios.get('/api/v1/uploads/status', { params: { session_id: sid } });
    const d   = res.data || {};

    // ── Case A / B / C: a PatientFile record exists ──────────────────────────
    if (d.file) {
      job.fileUuid = d.file.uuid;
      const st = d.file.upload_status;

      if (st === 'ready') {
        job.status   = 'completed';
        job.progress = 100;
        finalizeCompleted(job);
        return;
      }
      if (st === 'failed') {
        markFailed(job, 'Server processing failed.');
        return;
      }
      // queued / processing — merge is done, OptimizeVideoJob running or queued.
      // Follow the pipeline until it resolves.
      job.status = 'processing';
      await followProcessingPipeline(job);
      return;
    }

    // ── Case D: chunks exist — upload was interrupted before /complete ────────
    if ((d.received_chunks?.length ?? 0) > 0) {
      markFailed(job, 'Upload interrupted. Please retry.');
      return;
    }

    // ── Case E: nothing on server (session gone) ──────────────────────────────
    markFailed(job, 'Upload session expired. Please retry.');

  } catch {
    // ── Case F: network/server error ──────────────────────────────────────────
    markFailed(job, 'Could not reach server. Please retry.');
  }
}

function markFailed(job, msg) {
  job.status    = 'failed';
  job.errorMsg  = msg;
  persistCatalog();
}

/**
 * followProcessingPipeline — polls until the file is ready or failed.
 *
 * Called when we KNOW the merge has completed (file row exists in DB).
 * Only shows "processing" status — never "merging" — because the file
 * is already assembled.
 *
 * Exits on: ready, failed, abort signal, or poll cap.
 */
async function followProcessingPipeline(job) {
  const sid = job._sessionId;
  let attempt = 0;

  while (attempt < POLL_MAX_ATTEMPTS && !job._abort.signal.aborted) {
    const delay = Math.min(POLL_BASE_MS * Math.pow(1.6, attempt), POLL_MAX_MS);
    await waitFor(delay);
    attempt++;

    try {
      const res = await axios.get('/api/v1/uploads/status', { params: { session_id: sid } });
      const d   = res.data || {};

      if (!d.file) {
        // File row should exist by now. If it disappeared, something is wrong.
        markFailed(job, 'File record lost. Please retry.');
        return;
      }

      job.fileUuid = d.file.uuid;
      const st = d.file.upload_status;

      if (st === 'ready') {
        job.status   = 'completed';
        job.progress = 100;
        finalizeCompleted(job);
        return;
      }
      if (st === 'failed') {
        markFailed(job, 'Server processing failed.');
        return;
      }

      job.status = 'processing'; // queued / processing — still running
    } catch {
      // transient network blip — keep polling
    }
  }

  if (job.status !== 'completed') {
    markFailed(job, 'Timed out waiting for server. Please retry.');
  }
}

// ── finalize helpers ──────────────────────────────────────────────────────────
function finalizeCompleted(job) {
  notifyUploaded();
  persistCatalog();
  // Auto-dismiss after 4s.
  setTimeout(() => {
    const i = jobs.value.findIndex(j => j.id === job.id);
    if (i !== -1 && ['completed','cancelled'].includes(jobs.value[i].status)) {
      jobs.value.splice(i, 1);
      persistCatalog();
    }
  }, 4000);
}

// ── enqueue new files ─────────────────────────────────────────────────────────
function enqueue(files, ctx) {
  for (const file of files) {
    const dedupeKey = `${ctx.patientId}:${file.name}:${file.size}:${file.lastModified}`;
    if (activeUploads.has(dedupeKey)) continue;

    // Skip if an active (non-terminal) job for the same file already exists.
    const existing = jobs.value.find(j =>
      j.patientId === ctx.patientId &&
      j.name      === file.name     &&
      j.totalSize === file.size     &&
      !['failed','cancelled','completed'].includes(j.status)
    );
    if (existing) continue;

    activeUploads.add(dedupeKey);
    const chunkSize   = pickChunkSize(file.size, 0);
    const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));

    const job = reactive({
      id:            ++idCounter,
      name:          file.name,
      patientId:     ctx.patientId,
      category:      ctx.category,
      file,
      totalSize:     file.size,
      uploadedBytes: 0,
      remainingBytes:file.size,
      progress:      0,
      speed:         0,
      etaText:       '—',
      status:        'preparing',
      errorMsg:      '',
      fileUuid:      null,
      retryChunk:    null,
      cancellable:   true,
      _totalChunks:  totalChunks,
      _chunkLoaded:  new Array(totalChunks).fill(0),
      _chunkSize:    chunkSize,
      _startTime:    0,
      _sessionId:    null,
      _abort:        new AbortController(),
      _dedupeKey:    dedupeKey,
      _restored:     false,
    });

    jobs.value.push(job);
    runJob(job).finally(() => activeUploads.delete(dedupeKey));
  }
}

// ── run a fresh upload job ────────────────────────────────────────────────────
async function runJob(job) {
  job._startTime = Date.now();
  job.status     = 'uploading';
  const signal   = job._abort.signal;
  const resumeKey = `upload_session:${job.patientId}:${job.name}:${job.totalSize}`;

  try {
    // ── Try to resume an existing session ────────────────────────────────────
    let received     = [];
    const storedSid  = localStorage.getItem(resumeKey);

    if (storedSid && !signal.aborted) {
      try {
        const sres = await axios.get('/api/v1/uploads/status',
          { params: { session_id: storedSid }, signal });
        const sd = sres.data || {};

        // If a file already exists for this session, it was already completed.
        if (sd.file) {
          job.fileUuid = sd.file.uuid;
          if (sd.file.upload_status === 'ready') {
            job.status = 'completed'; job.progress = 100;
            localStorage.removeItem(resumeKey);
            finalizeCompleted(job);
            return;
          }
          // queued / processing — follow the pipeline, no uploading needed.
          job.status = 'processing';
          localStorage.removeItem(resumeKey);
          await followProcessingPipeline(job);
          notifyUploaded();
          return;
        }

        received = sd.received_chunks || [];
        if (received.length > 0) {
          job._sessionId = storedSid;   // resume this session
        }
        // 0 received → session gone or empty, start fresh below
      } catch {
        // server unreachable — start a fresh session
      }
    }

    if (signal.aborted) throw new DOMException('Aborted', 'AbortError');

    // ── Init (fresh or new session) ───────────────────────────────────────────
    if (!job._sessionId) {
      const initRes = await axios.post('/api/v1/uploads/init', {
        filename:     job.name,
        total_chunks: job._totalChunks,
        total_size:   job.totalSize,
      }, { signal });
      job._sessionId = initRes.data.session_id;
      received       = initRes.data.received_chunks || [];
    }

    localStorage.setItem(resumeKey, job._sessionId);
    markReceived(job, received);
    recompute(job);

    // ── Upload pending chunks ─────────────────────────────────────────────────
    const receivedSet = new Set(received);
    const pending     = [];
    for (let i = 0; i < job._totalChunks; i++) {
      if (!receivedSet.has(i)) pending.push(i);
    }

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

    // ── All chunks on server → finalize ──────────────────────────────────────
    // Transition to merging ONLY here — this is the only legitimate source.
    job.status   = 'merging';
    job.progress = 100;
    persistCatalog();

    await axios.post('/api/v1/uploads/complete', {
      session_id:   job._sessionId,
      total_chunks: job._totalChunks,
      patient_uuid: job.patientId,
      metadata: {
        category:      job.category,
        original_name: job.name,
        extension:     job.name.split('.').pop(),
        type:          (job.file?.type || '').split('/')[0] || 'document',
        mime_type:     job.file?.type || 'application/octet-stream',
      },
    }, { signal });

    job.cancellable = false;
    localStorage.removeItem(resumeKey);
    job.file = null; // release memory

    // ── Follow processing until ready ─────────────────────────────────────────
    await followProcessingPipeline(job);
    notifyUploaded();

  } catch (err) {
    if (axios.isCancel?.(err) || err?.name === 'AbortError' || signal.aborted) {
      job.status = 'cancelled';
      cleanupSession(job, resumeKey);
    } else {
      job.status   = 'failed';
      job.errorMsg = err.response?.data?.message || err.message || 'Upload failed';
    }
  } finally {
    persistCatalog();
  }
}

function markReceived(job, received) {
  for (const i of received) {
    job._chunkLoaded[i] = (i === job._totalChunks - 1)
      ? job.totalSize - i * job._chunkSize
      : job._chunkSize;
  }
}

// ── per-chunk upload with retry ───────────────────────────────────────────────
async function uploadOneChunk(job, chunkIndex, signal) {
  const startByte = chunkIndex * job._chunkSize;
  const endByte   = Math.min(startByte + job._chunkSize, job.totalSize);
  const blob      = job.file.slice(startByte, endByte);

  let attempt = 0;
  while (attempt <= MAX_RETRIES) {
    if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
    try {
      const fd = new FormData();
      fd.append('chunk',        blob);
      fd.append('session_id',   job._sessionId);
      fd.append('chunk_index',  chunkIndex);
      fd.append('total_chunks', job._totalChunks);

      await postChunkWithWatchdog(job, chunkIndex, fd, signal);

      job._chunkLoaded[chunkIndex] = endByte - startByte;
      recompute(job);
      return;
    } catch (err) {
      if (axios.isCancel?.(err) || signal.aborted) throw err;
      attempt++;
      if (attempt > MAX_RETRIES) throw err;
      job.status     = 'retrying';
      job.retryChunk = chunkIndex;
      await waitFor(Math.min(800 * 2 ** (attempt - 1), 8000));
      if (!signal.aborted) { job.status = 'uploading'; job.retryChunk = null; }
    }
  }
}

function postChunkWithWatchdog(job, chunkIndex, formData, signal) {
  return new Promise((resolve, reject) => {
    const ctl     = new AbortController();
    const onAbort = () => ctl.abort();
    signal?.addEventListener('abort', onAbort, { once: true });

    let lastProgress = Date.now();

    const watchdog = setInterval(() => {
      if (Date.now() - lastProgress > STALL_MS) {
        clearInterval(watchdog);
        ctl.abort('stalled');
      }
    }, 5000);

    const finish = (fn) => {
      clearInterval(watchdog);
      signal?.removeEventListener('abort', onAbort);
      fn();
    };

    axios.post('/api/v1/uploads/chunk', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      signal:  ctl.signal,
      onUploadProgress: (e) => {
        lastProgress = Date.now();
        const loaded = Math.min(e.loaded || 0, formData.get('chunk').size || e.total || 0);
        job._chunkLoaded[chunkIndex] = loaded;
        recompute(job);
      },
    }).then(r => finish(() => resolve(r)), e => finish(() => reject(e)));

    setTimeout(() => ctl.abort('timeout'), CHUNK_TIMEOUT_MS);
  });
}

// ── session cleanup ───────────────────────────────────────────────────────────
async function cleanupSession(job, resumeKey) {
  try { localStorage.removeItem(resumeKey); } catch {}
  if (job._sessionId) {
    try { await axios.post('/api/v1/uploads/cancel', { session_id: job._sessionId }); } catch {}
  }
}

// ── cancel ────────────────────────────────────────────────────────────────────
function cancel(jobId) {
  const job = jobs.value.find(j => j.id === jobId);
  if (!job || !job.cancellable) return;
  job._abort.abort();
  job.status = 'cancelled';
  cleanupSession(job, `upload_session:${job.patientId}:${job.name}:${job.totalSize}`);
  persistCatalog();
}

// ── retry ─────────────────────────────────────────────────────────────────────
function retry(jobId) {
  const job = jobs.value.find(j => j.id === jobId);
  if (!job) return;

  // Ghost job (no File object after refresh) — cannot re-upload bytes.
  // The user must pick the file again via the normal upload flow.
  if (!job.file) {
    // Give the user a clear message rather than silently doing nothing.
    job.errorMsg = 'Page was refreshed — please re-select the file to upload again.';
    persistCatalog();
    return;
  }

  // Reset all runtime state.
  job._abort       = new AbortController();
  job.status       = 'preparing';
  job.errorMsg     = '';
  job.progress     = 0;
  job.uploadedBytes  = 0;
  job.remainingBytes = job.totalSize;
  job.speed        = 0;
  job.etaText      = '—';
  job.retryChunk   = null;
  job._chunkLoaded = new Array(job._totalChunks).fill(0);
  job._startTime   = 0;
  job._sessionId   = null;
  job.cancellable  = true;

  const resumeKey = `upload_session:${job.patientId}:${job.name}:${job.totalSize}`;
  localStorage.removeItem(resumeKey);

  activeUploads.add(job._dedupeKey);
  runJob(job).finally(() => activeUploads.delete(job._dedupeKey));
  persistCatalog();
}

// ── remove / clear ────────────────────────────────────────────────────────────
function removeJob(jobId) {
  const i = jobs.value.findIndex(j => j.id === jobId);
  if (i === -1) return;
  jobs.value.splice(i, 1);
  persistCatalog();
}
function clearCompleted() {
  jobs.value = jobs.value.filter(j => !['completed','cancelled'].includes(j.status));
  persistCatalog();
}

// ── tab close: best-effort cancel of in-flight uploads ───────────────────────
if (typeof window !== 'undefined') {
  window.addEventListener('beforeunload', () => {
    for (const j of jobs.value) {
      if (!j.cancellable) continue;
      try { j._abort.abort(); } catch {}
      // sendBeacon for chunks that were mid-flight — server will keep the
      // already-stored chunks so the upload can be resumed next session.
    }
  });
}

// ── file grid notification ────────────────────────────────────────────────────
const subscribers = new Set();
function onUploaded(fn) { subscribers.add(fn); return () => subscribers.delete(fn); }
function notifyUploaded() { subscribers.forEach(fn => { try { fn(); } catch {} }); }

// ── boot: restore catalog ─────────────────────────────────────────────────────
loadCatalog();

// ── public API ────────────────────────────────────────────────────────────────
export function useUploads() {
  const activeJobs = computed(() =>
    jobs.value.filter(j => !['completed','cancelled'].includes(j.status)));
  const hasActive  = computed(() => activeJobs.value.length > 0);
  const totalProgress = computed(() => {
    const active = jobs.value.filter(j =>
      ['uploading','merging','processing','preparing','retrying','failed'].includes(j.status));
    if (!active.length) return 100;
    const total    = active.reduce((s,j) => s + j.totalSize,     0);
    const uploaded = active.reduce((s,j) => s + j.uploadedBytes, 0);
    return total > 0 ? (uploaded / total) * 100 : 0;
  });

  return {
    jobs, activeJobs, hasActive, totalProgress,
    enqueue, cancel, retry, removeJob, clearCompleted, onUploaded,
    fmtSize, fmtSpeed, fmtDuration,
  };
}
