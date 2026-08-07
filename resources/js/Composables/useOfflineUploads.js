// ═══════════════════════════════════════════════════════════════════
// useOfflineUploads — Phase 7 Offline File Uploads
// ═══════════════════════════════════════════════════════════════════
//
// Architecture:
//   When offline:
//     User picks file → POST to /_native/api/offline/uploads →
//     stored in storage/app/uploads/pending/{uuid}.{ext} →
//     metadata in SQLite offline_files table (sync_status = pending_upload) →
//     file appears immediately in UI
//
//   When online:
//     SyncPendingUploads command uploads to remote API →
//     replaces local with server record →
//     sync_status = synced
//
//   Preview:
//     Uses Phase 6 FileCacheService to serve local pending files
//     (offline_files.local_path points to the pending file on disk)
//
// Dependencies:
//   useNativeBridge  — Android permission handling & file picker
//   useWorkspace      — addFileLocally() for reactive UI updates
//   axios             — HTTP client
// ═══════════════════════════════════════════════════════════════════

import axios from 'axios'
import { ref, computed, reactive } from 'vue'
import { useNativeBridge } from './useNativeBridge'
import { useWorkspace } from './useWorkspace'
import { useSyncEngine } from './useSyncEngine'  // BUG-015: reliable network state
import { useUploads, loadPersisted, savePersisted, fileKey, isRetryableUploadError, getUploadConfig } from './useUploads'  // shared bottom-right UploadManager.vue popup

// ── FIX-REL-2: retry/backoff parameters, matching useUploads.js:uploadChunk
// so the offline path reaches parity with the online path instead of
// aborting the whole file on the first transient error.
const MAX_RETRIES = 3
const RETRY_BASE_MS = 500
const RETRY_CAP_MS = 4000

// ─── Module-level state (shared across all useOfflineUploads() calls) ──────
const offlineUploads = ref([])

let idCounter = 0

const STATUS_ICONS = {
  pending_upload: '⏳',
  uploading:      '↑',
  synced:         '✓',
  failed:         '⚠',
}

const STATUS_LABELS = {
  pending_upload: 'Pending upload',
  uploading:      'Uploading...',
  synced:         'Synced',
  failed:         'Upload failed',
}

/**
 * Detect if the app is currently online.
 * ── BUG-015 FIX: Use SyncEngine's isOnline instead of navigator.onLine ──
 * navigator.onLine is unreliable in Android WebViews — it can stay true
 * for minutes after losing connection. SyncEngine.isOnline integrates the
 * Android ConnectivityManager via the native bridge for accurate state.
 */
function isOnline() {
  const { isOnline: syncOnline } = useSyncEngine()
  return syncOnline.value
}

export function useOfflineUploads() {
  const {
    isCameraAvailable,
    isFilePickerAvailable,
    requestPermission,
    pickFiles,
    takePhoto,
    recordVideo,
    PERMISSION_GRANTED,
  } = useNativeBridge()

  // ── Internal helpers ────────────────────────────────────────────────

  /**
   * Upload a file to the local offline endpoint.
   * This saves it to disk + SQLite with sync_status = pending_upload.
   */
  async function saveFileOffline(file, patientUuid, metadata = {}) {
    const token = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
    const headers = {
      'Content-Type': 'multipart/form-data',
      ...(token ? { 'Authorization': 'Bearer ' + token } : {})
    };
    const fd = new FormData()
    fd.append('file', file)
    fd.append('patient_uuid', patientUuid)
    if (metadata.title) fd.append('title', metadata.title)
    if (metadata.desc) fd.append('desc', metadata.desc)
    if (metadata.category) fd.append('category', metadata.category)

    const res = await axios.post('/_native/api/offline/uploads', fd, {
      headers,
      timeout: 120000,
    })

    return res.data
  }

  /**
   * Save a video or large file locally using the chunked upload endpoints (/api/v1/chunk/init, /api/v1/chunk/chunk, /api/v1/chunk/complete).
   * This avoids single giant POST requests and works cleanly with embedded Laravel.
   *
   * $job, when given, is the reactive entry pushed into useUploads()'s shared
   * `uploads` list — updated after every chunk so UploadManager.vue's
   * chunk-count/progress-bar/speed UI reflects real progress instead of
   * nothing happening until the whole file (all chunks + merge) is done.
   */
  async function saveFileChunkedOffline(file, patientUuid, metadata = {}, job = null) {
    // ── FIX-PERF-4: read the shared configuration instead of a hardcoded
    // constant. configureUploads() in app.js sets chunkSize to 2 MB on device,
    // and that change would have had no effect on this path before this fix.
    const CHUNK_SIZE = getUploadConfig().chunkSize || (5 * 1024 * 1024)
    const token = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
    const headers = token ? { 'Authorization': 'Bearer ' + token } : {};

    // ── FIX-REL-2 step 2/3: persist + resume via the SAME upload_sessions
    // localStorage map useUploads.js already maintains, keyed the same way
    // (fileKey). Previously the offline path never wrote or read this map,
    // so any interrupted offline upload restarted at chunk 0 from scratch.
    const persisted = loadPersisted()
    const key = fileKey(file)

    let upload_id = null
    let chunk_size = CHUNK_SIZE
    let total_chunks = 0
    let completedSet = new Set()

    // ── Resume check ──────────────────────────────────────────────────
    if (persisted[key] && persisted[key].status === 'uploading') {
      try {
        const r = await axios.get(`/api/v1/chunk/${persisted[key].upload_id}/status`, { headers })
        if (r.data.status === 'uploading' || r.data.status === 'pending') {
          upload_id = r.data.uuid
          total_chunks = r.data.total_chunks
          completedSet = new Set(r.data.received_chunks || [])
        }
      } catch {
        // Session gone (410/404) or unreachable — fall through to a fresh init.
        delete persisted[key]
        savePersisted(persisted)
      }
    }

    // Step 1: Initialize chunk upload session (skipped when resuming)
    if (!upload_id) {
      const initRes = await axios.post('/api/v1/chunk/init', {
        file_name: file.name || 'video.mp4',
        file_size: file.size,
        mime_type: file.type || 'video/mp4',
        patient_id: patientUuid,
        chunk_size: CHUNK_SIZE,
        metadata: {
          title: metadata.title || file.name || '',
          desc: metadata.desc || '',
          category: metadata.category || '',
          date: metadata.date || new Date().toISOString(),
        },
      }, { headers })

      upload_id = initRes.data.upload_id
      chunk_size = initRes.data.chunk_size || CHUNK_SIZE
      total_chunks = initRes.data.total_chunks
    }

    if (job) {
      job.totalChunks = total_chunks
      job.uploadId = upload_id
      job.completedChunks = completedSet
    }

    persisted[key] = {
      upload_id,
      file_name: file.name,
      file_size: file.size,
      patient_id: patientUuid,
      total_chunks,
      status: 'uploading',
      savedAt: Date.now(),
    }
    savePersisted(persisted)

    let lastSpeedSample = { t: performance.now(), bytes: 0 }
    let uploadedBytes = 0
    for (const idx of completedSet) {
      uploadedBytes += Math.min(chunk_size, file.size - idx * chunk_size)
    }

    // Step 2: Upload missing chunks sequentially, with retry+backoff
    for (let i = 0; i < total_chunks; i++) {
      if (completedSet.has(i)) continue // ── resume: skip chunks the server already has

      // ── Cooperative pause/cancel: checked between chunks since this loop
      // is a sequential await chain with no per-chunk AbortController pool
      // like the online path's runPool(). Paused/cancelled state is left in
      // `persisted[key]` so a later resume/retry continues from here.
      if (job?._cancelled) {
        const err = new DOMException('Upload cancelled', 'AbortError')
        throw err
      }
      if (job?._paused) {
        const err = new DOMException('Upload paused', 'AbortError')
        err.isPause = true
        throw err
      }

      const start = i * chunk_size
      const end = Math.min(file.size, (i + 1) * chunk_size)
      const chunkBlob = file.slice(start, end)

      let lastError = null
      let attempt = 0
      for (; attempt <= MAX_RETRIES; attempt++) {
        if (attempt > 0) {
          const delay = Math.min(RETRY_BASE_MS * Math.pow(2, attempt - 1), RETRY_CAP_MS)
          await new Promise((r) => setTimeout(r, delay))
        }

        const fd = new FormData()
        fd.append('upload_id', upload_id)
        fd.append('chunk_index', i)
        fd.append('chunk', chunkBlob, file.name || 'chunk')

        const controller = new AbortController()
        if (job) job._controller = controller

        try {
          await axios.post('/api/v1/chunk/chunk', fd, {
            headers: {
              'Content-Type': 'multipart/form-data',
              ...headers
            },
            timeout: 120000,
            signal: controller.signal,
          })
          lastError = null
          break
        } catch (err) {
          lastError = err
          // ── Retry only on 5xx / network errors — a 4xx (bad chunk,
          // expired session) will fail identically on every retry.
          if (!isRetryableUploadError(err)) break
        }
      }

      if (lastError) throw lastError

      completedSet.add(i)
      uploadedBytes = end

      if (job) {
        job.completedChunks.add(i)
        job.uploadedBytes = end
        job.progress = Math.round((end / file.size) * 100)

        const now = performance.now()
        const dt = (now - lastSpeedSample.t) / 1000
        if (dt >= 0.5) {
          job.speed = Math.round((end - lastSpeedSample.bytes) / dt)
          lastSpeedSample = { t: now, bytes: end }
        }
      }
    }

    // Step 3: Complete upload and merge chunks into patient_files
    const completeRes = await axios.post('/api/v1/chunk/complete', { upload_id }, { headers })

    delete persisted[key]
    savePersisted(persisted)

    const resData = completeRes.data
    return {
      uuid: resData.uuid,
      local_path: resData.file_path || `patients/${patientUuid}/${resData.uuid}`,
      original_name: file.name,
      mime_type: file.type || 'video/mp4',
      extension: file.name?.split('.').pop() || '',
      size: file.size,
      sync_status: 'pending_sync',
      type: resData.type || (file.type?.startsWith('video/') ? 'video' : 'document'),
      created_at: new Date().toISOString(),
      url: resData.url,
      thumbnail_url: resData.thumbnail_url,
    }
  }

  /**
   * Create a reactive job entry in the offline uploads list.
   */
  function createJob(file, patientUuid, metadata = {}, response) {
    const id = ++idCounter
    const job = reactive({
      id,
      file,
      patientUuid,
      metadata: { ...metadata },
      uuid: response.uuid,
      local_path: response.local_path,
      original_name: response.original_name,
      mime_type: response.mime_type,
      extension: response.extension,
      size: response.size,
      sync_status: response.sync_status || 'pending_sync',
      type: response.type || 'document',
      created_at: response.created_at || new Date().toISOString(),
      error_message: null,
    })
    offlineUploads.value.push(job)
    return job
  }

  // ── Main public API ────────────────────────────────────────────────
  function offlineUploadFile(file, patientUuid, metadata = {}) {
    const { uploadFile } = useUploads()
    return uploadFile(file, patientUuid, metadata)
  }

  /**
   * Upload a file while offline — saves locally via the Phase 7 offline endpoint
   * or via local chunked upload for videos / large files.
   *
   * Returns a reactive job object with sync_status = 'pending_sync'.
   * The file appears immediately in the UI via addFileLocally().
   */
  async function uploadFile(file, patientUuid, metadata = {}) {
    // Android's file/camera pickers frequently hand back a File with an empty
    // `type`, which made this fall through to the single-request path and push
    // a whole video through one POST. Fall back to the extension, and treat any
    // large file as chunk-worthy regardless of what it claims to be.
    const LARGE_FILE_BYTES = 8 * 1024 * 1024
    const name = (file.name || '').toLowerCase()
    const looksLikeVideo = /\.(mp4|mov|avi|mkv|webm|3gp|wmv|flv|m4v)$/.test(name)
    const isVideo = !!(file.type && file.type.startsWith('video/'))
      || looksLikeVideo
      || (file.size || 0) > LARGE_FILE_BYTES

    const { addFileLocally } = useWorkspace()
    const { uploads } = useUploads()

    // Reuse the SAME bottom-right UploadManager.vue popup the online path
    // already shows (chunk count, progress bar, speed) instead of a
    // separate badge on the file card — offline.progress = true hides the
    // pause/cancel/retry buttons there, since those act on useUploads.js's
    // own in-flight-request bookkeeping, which this job has none of.
    let popupJob = null
    if (isVideo) {
      popupJob = reactive({
        id: `offline-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        file,
        patientId: patientUuid,
        metadata: { ...metadata },
        status: 'uploading',
        progress: 0,
        uploadedBytes: 0,
        totalBytes: file.size,
        speed: 0,
        error: null,
        uploadId: null,
        totalChunks: 0,
        completedChunks: new Set(),
        offline: true,
        _paused: false,
        _cancelled: false,
        _controller: null,
      })
      uploads.value.push(popupJob)
    }

    try {
      const fileData = await saveFileChunkedOffline(file, patientUuid, metadata, popupJob)
      popupJob.status = 'completed'
      popupJob.progress = 100

      const job = createJob(file, patientUuid, metadata, fileData)

      const fallbackUrl = `/_native/cache/files/${fileData.uuid}`
      const fallbackThumb = fileData.mime_type?.startsWith('image/')
        ? fallbackUrl
        : (fileData.mime_type?.startsWith('video/')
            ? `/_native/cache/files/${fileData.uuid}/thumbnail`
            : null)
      addFileLocally({
        uuid:          fileData.uuid,
        patient_id:    patientUuid,
        title:         metadata.title || file.name || '',
        desc:          metadata.desc || '',
        category:      metadata.category || '',
        file_name:     fileData.original_name || file.name,
        mime_type:     fileData.mime_type,
        size:          fileData.size,
        sync_status:   fileData.sync_status || 'pending_sync',
        type:          fileData.type || 'document',
        extension:     fileData.extension,
        created_at:    fileData.created_at,
        updated_at:    fileData.created_at,
        local_path:    fileData.local_path,
        upload_status: 'ready',
        url:           fileData.url || fallbackUrl,
        thumbnail_url: fileData.thumbnail_url || fallbackThumb,
      })

      return job
    } catch (err) {
      // A pause/cancel is a deliberate user action, not a failure — set the
      // matching status so the UI shows "Paused"/"Cancelled" rather than a
      // red error, and so the retry button (which resumes) applies correctly.
      if (popupJob && err?.name === 'AbortError') {
        popupJob.status = err.isPause ? 'paused' : 'cancelled'
        return
      }

      console.error('[OfflineUpload] Failed to save file locally:', err)
      if (popupJob) {
        popupJob.status = 'failed'
        // ── FIX-REL-1: use the full extraction chain instead of the bare
        // axios message, which was always "Request failed with status code 500"
        // regardless of the actual server-provided reason.
        popupJob.error =
          err?.response?.data?.message ||
          err?.response?.data?.error ||
          err?.message ||
          'Upload failed'
      }
      throw err
    }
  }

  /**
   * ── FIX-REL-2 step 4: resume a failed offline chunked (video) upload.
   * Wired to UploadManager.vue's retry button for `offline: true` jobs —
   * unhiding that button without this would call useUploads().retryUpload(),
   * which knows nothing about offline jobs (no `job.uploadId` bookkeeping
   * matching its own state machine) and would restart from scratch or throw.
   *
   * Relies on saveFileChunkedOffline's own resume logic (persisted session +
   * GET /status) to skip chunks already received by the server, so this is
   * safe to call after a partial failure — it does not restart at chunk 0.
   */
  async function resumeOfflineUpload(jobId) {
    const { uploads } = useUploads()
    const job = uploads.value.find((u) => u.id === jobId && u.offline)
    if (!job || !job.file) return

    job.status = 'uploading'
    job.error = null
    job._paused = false
    job._cancelled = false

    try {
      const fileData = await saveFileChunkedOffline(job.file, job.patientId, job.metadata, job)
      job.status = 'completed'
      job.progress = 100

      const { addFileLocally } = useWorkspace()
      const fallbackUrl = `/_native/cache/files/${fileData.uuid}`
      addFileLocally({
        uuid:          fileData.uuid,
        patient_id:    job.patientId,
        title:         job.metadata?.title || job.file?.name || '',
        desc:          job.metadata?.desc || '',
        category:      job.metadata?.category || '',
        file_name:     fileData.original_name || job.file?.name,
        mime_type:     fileData.mime_type,
        size:          fileData.size,
        sync_status:   fileData.sync_status || 'pending_sync',
        type:          fileData.type || 'document',
        created_at:    fileData.created_at,
        updated_at:    fileData.created_at,
        local_path:    fileData.local_path,
        upload_status: 'ready',
        url:           fileData.url || fallbackUrl,
        thumbnail_url: fileData.thumbnail_url,
      })
    } catch (err) {
      if (err?.name === 'AbortError') {
        job.status = err.isPause ? 'paused' : 'cancelled'
        return
      }
      job.status = 'failed'
      job.error =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        err?.message ||
        'Upload failed'
    }
  }

  /**
   * Pause an in-flight offline chunked upload. Cooperative — takes effect
   * before the next chunk starts (see the check in saveFileChunkedOffline).
   */
  function pauseOfflineUpload(jobId) {
    const { uploads } = useUploads()
    const job = uploads.value.find((u) => u.id === jobId && u.offline)
    if (!job || job.status !== 'uploading') return
    job._paused = true
    job._controller?.abort()
  }

  /**
   * Cancel an offline chunked upload and discard its persisted resume state
   * (so a later pick of the same file starts fresh instead of resuming a
   * cancelled session).
   */
  function cancelOfflineUpload(jobId) {
    const { uploads } = useUploads()
    const job = uploads.value.find((u) => u.id === jobId && u.offline)
    if (!job) return
    job._cancelled = true
    job._controller?.abort()
    if (job.file) {
      const persisted = loadPersisted()
      const key = fileKey(job.file)
      delete persisted[key]
      savePersisted(persisted)
    }
  }

  /**
   * Pick files from device with proper Android permission handling.
   * Handles Camera, Gallery, Files, PDF, Video, Audio.
   *
   * @param {Object} options
   * @param {'camera'|'gallery'|'files'|'pdf'|'video'|'audio'} [options.source='files']
   * @param {boolean} [options.multiple=true]
   * @returns {Promise<File[]>}
   */
  async function pickFile(options = {}) {
    const { source = 'files', multiple = true } = options

    switch (source) {
      case 'camera': {
        // Request camera permission first
        const perm = await requestPermission('camera')
        if (perm !== PERMISSION_GRANTED) return []

        const photo = await takePhoto()
        if (!photo) return []
        return [photo]
      }

      case 'video': {
        // Both camera + audio permissions needed
        const camPerm = await requestPermission('camera')
        if (camPerm !== PERMISSION_GRANTED) return []

        const audioPerm = await requestPermission('audio')
        if (audioPerm !== PERMISSION_GRANTED) return []

        const video = await recordVideo()
        if (!video) return []
        return [video]
      }

      case 'pdf': {
        // File access permission + PDF filter
        const perm = await requestPermission('files')
        if (perm !== PERMISSION_GRANTED) return []

        if (isFilePickerAvailable()) {
          const files = await pickFiles({
            multiple,
            accept: 'application/pdf',
          })
          return files || []
        }
        return []
      }

      case 'audio': {
        const perm = await requestPermission('audio')
        if (perm !== PERMISSION_GRANTED) return []

        if (isFilePickerAvailable()) {
          const files = await pickFiles({
            multiple,
            accept: 'audio/*',
          })
          return files || []
        }
        return []
      }

      case 'gallery':
      case 'files':
      default: {
        const perm = await requestPermission('files')
        if (perm !== PERMISSION_GRANTED) return []

        if (isFilePickerAvailable()) {
          const accept = source === 'gallery' ? 'image/*,video/*' : '*/*'
          const files = await pickFiles({ multiple, accept })
          return files || []
        }
        return []
      }
    }
  }

  /**
   * Retry a failed offline upload by resetting its sync_status.
   */
  // ── FIX-REL-2 step 5: removed the dead POST to
  // /_native/api/offline/uploads/{uuid}/retry — no such route exists
  // (routes/web.php only registers store/destroy/pendingIndex for this
  // prefix), so every call 404'd and was silently swallowed by the global
  // JSON exception render. This file's sync_status is already the sole
  // signal SyncEngine polls to decide what to push, so resetting it back to
  // 'pending_upload' locally (and in the workspace store) is sufficient to
  // make the next sync cycle retry it — no network round-trip needed.
  function retryUpload(uuid) {
    const job = offlineUploads.value.find((j) => j.uuid === uuid)
    if (job) {
      job.sync_status = 'pending_upload'
      job.error_message = null
    }
    const { updateFileLocally } = useWorkspace()
    updateFileLocally({ uuid, sync_status: 'pending_upload' })
  }

  /**
   * Delete a pending offline file (local + DB).
   */
  async function deleteUpload(uuid) {
    try {
      await axios.delete(`/_native/api/offline/uploads/${uuid}`)
      // Remove from local list
      offlineUploads.value = offlineUploads.value.filter((j) => j.uuid !== uuid)
      // Remove from workspace
      const { removeFileLocally } = useWorkspace()
      removeFileLocally(uuid)
    } catch (err) {
      console.error('[OfflineUpload] Delete failed:', err)
      throw err
    }
  }

  /**
   * Get the status icon for a sync_status value.
   */
  function statusIcon(status) {
    return STATUS_ICONS[status] || '⏳'
  }

  /**
   * Get the status label for a sync_status value.
   */
  function statusLabel(status) {
    return STATUS_LABELS[status] || status
  }

  /**
   * Get the preview URL for an offline file.
   * Uses the Phase 6 cache endpoint if the file is cached,
   * otherwise falls back to the local path.
   */
  function previewUrl(item) {
    // If this is an offline file with a local path, serve it via the cache endpoint
    if (item.local_path) {
      // The cache can serve any local file path
      return `/_native/cache/files/${item.uuid}`
    }
    // Otherwise fall back to the normal API URL
    return item.url || null
  }

  return {
    offlineUploads,
    uploadFile,
    pickFile,
    retryUpload,
    resumeOfflineUpload,
    pauseOfflineUpload,
    cancelOfflineUpload,
    deleteUpload,
    statusIcon,
    statusLabel,
    previewUrl,
    isOnline,
  }
}
