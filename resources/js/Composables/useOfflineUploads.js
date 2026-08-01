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
import { ref, computed } from 'vue'
import { useNativeBridge } from './useNativeBridge'
import { useWorkspace } from './useWorkspace'
import { useSyncEngine } from './useSyncEngine'  // BUG-015: reliable network state

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
    const fd = new FormData()
    fd.append('file', file)
    fd.append('patient_uuid', patientUuid)
    if (metadata.title) fd.append('title', metadata.title)
    if (metadata.desc) fd.append('desc', metadata.desc)
    if (metadata.category) fd.append('category', metadata.category)

    const res = await axios.post('/_native/api/offline/uploads', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000, // 2 minutes for large files
    })

    return res.data
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
      sync_status: response.sync_status || 'pending_upload',
      type: response.type || 'document',
      created_at: response.created_at || new Date().toISOString(),
      error_message: null,
    })
    offlineUploads.value.push(job)
    return job
  }

  // ── Main public API ────────────────────────────────────────────────

  /**
   * Upload a file while offline — saves locally via the Phase 7 offline endpoint.
   *
   * This function is STRICTLY offline-only. For online uploads, use the
   * existing chunked upload system from useUploads directly.
   *
   * Returns a reactive job object with sync_status = 'pending_upload'.
   * The file appears immediately in the UI via addFileLocally().
   */
  async function uploadFile(file, patientUuid, metadata = {}) {
    if (isOnline()) {
      throw new Error(
        '[OfflineUpload] Cannot use offline upload while online. ' +
        'Use useUploads().uploadFile() for online uploads.'
      )
    }

    const fd = new FormData()
    fd.append('file', file)
    fd.append('patient_uuid', patientUuid)
    if (metadata.title) fd.append('title', metadata.title)
    if (metadata.desc) fd.append('desc', metadata.desc)
    if (metadata.category) fd.append('category', metadata.category)

    try {
      const res = await axios.post('/_native/api/offline/uploads', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 120000,
      })

      const job = createJob(file, patientUuid, metadata, res.data)

      // Add to workspace immediately so the user sees it
      const { addFileLocally } = useWorkspace()
      addFileLocally({
        uuid:          res.data.uuid,
        patient_id:    patientUuid,
        title:         metadata.title || file.name || '',
        desc:          metadata.desc || '',
        category:      metadata.category || '',
        file_name:     res.data.original_name,
        mime_type:     res.data.mime_type,
        size:          res.data.size,
        sync_status:   'pending_upload',
        type:          res.data.type || 'document',
        extension:     res.data.extension,
        created_at:    res.data.created_at,
        updated_at:    res.data.created_at,
        local_path:    res.data.local_path,
        upload_status: 'pending_upload',
      })

      return job
    } catch (err) {
      console.error('[OfflineUpload] Failed to save file locally:', err)
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
