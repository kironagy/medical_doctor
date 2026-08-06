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
import { useUploads } from './useUploads'  // shared bottom-right UploadManager.vue popup

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
    const CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB chunks
    const token = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
    const headers = token ? { 'Authorization': 'Bearer ' + token } : {};

    // Step 1: Initialize chunk upload session
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

    const { upload_id, chunk_size = CHUNK_SIZE, total_chunks } = initRes.data
    if (job) job.totalChunks = total_chunks

    let lastSpeedSample = { t: performance.now(), bytes: 0 }

    // Step 2: Upload chunks sequentially
    for (let i = 0; i < total_chunks; i++) {
      const start = i * chunk_size
      const end = Math.min(file.size, (i + 1) * chunk_size)
      const chunkBlob = file.slice(start, end)

      const fd = new FormData()
      fd.append('upload_id', upload_id)
      fd.append('chunk_index', i)
      fd.append('chunk', chunkBlob, file.name || 'chunk')

      await axios.post('/api/v1/chunk/chunk', fd, {
        headers: {
          'Content-Type': 'multipart/form-data',
          ...headers
        },
        timeout: 120000,
      })

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
        totalChunks: 0,
        completedChunks: new Set(),
        offline: true,
      })
      uploads.value.push(popupJob)
    }

    try {
      let fileData
      if (isVideo) {
        fileData = await saveFileChunkedOffline(file, patientUuid, metadata, popupJob)
        popupJob.status = 'completed'
        popupJob.progress = 100
      } else {
        fileData = await saveFileOffline(file, patientUuid, metadata)
      }

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
      console.error('[OfflineUpload] Failed to save file locally:', err)
      if (popupJob) {
        popupJob.status = 'failed'
        popupJob.error = err?.message || 'Upload failed'
      }
      throw err
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
  async function retryUpload(uuid) {
    try {
      const res = await axios.post(`/_native/api/offline/uploads/${uuid}/retry`)
      const job = offlineUploads.value.find((j) => j.uuid === uuid)
      if (job) {
        job.sync_status = 'pending_upload'
        job.error_message = null
      }
      // Also update in workspace
      const { updateFileLocally } = useWorkspace()
      updateFileLocally({ uuid, sync_status: 'pending_upload' })
      return res.data
    } catch (err) {
      console.error('[OfflineUpload] Retry failed:', err)
      throw err
    }
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
    deleteUpload,
    statusIcon,
    statusLabel,
    previewUrl,
    isOnline,
  }
}
