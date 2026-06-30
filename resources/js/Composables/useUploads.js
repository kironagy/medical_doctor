import { ref, reactive } from 'vue'
import axios from 'axios'

const uploads = ref([])
let idCounter = 0

export function useUploads() {
  function uploadFile(file, patientId, metadata = {}) {
    const id = ++idCounter
    const job = reactive({
      id,
      file,
      patientId,
      status: 'uploading',
      progress: 0,
      uploadedBytes: 0,
      totalBytes: file.size,
      speed: 0,
      error: null,
      _controller: null,
      _lastLoaded: 0,
      _lastTime: Date.now(),
    })
    uploads.value.push(job)

    const formData = new FormData()
    formData.append('file', file)
    if (metadata.title) formData.append('title', metadata.title)
    if (metadata.desc) formData.append('desc', metadata.desc)
    if (metadata.category) formData.append('category', metadata.category)
    if (metadata.date) formData.append('date', metadata.date)

    const controller = new AbortController()
    job._controller = controller

    axios.post(`/api/v1/patients/${patientId}/files`, formData, {
      signal: controller.signal,
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (e.total) {
          job.progress = Math.round((e.loaded / e.total) * 100)
          job.uploadedBytes = e.loaded
          const now = Date.now()
          const elapsed = (now - job._lastTime) / 1000
          if (elapsed > 0.5) {
            const deltaBytes = e.loaded - job._lastLoaded
            job.speed = Math.round(deltaBytes / elapsed)
            job._lastLoaded = e.loaded
            job._lastTime = now
          }
        }
      },
    }).then(() => {
      job.status = 'completed'
      job.progress = 100
      job.speed = 0
    }).catch((err) => {
      if (axios.isCancel(err) || err.name === 'CanceledError') {
        job.status = 'cancelled'
      } else {
        job.status = 'failed'
        job.error = err.response?.data?.message || err.message || 'Upload failed'
      }
    })

    return job
  }

  function cancelUpload(id) {
    const job = uploads.value.find(u => u.id === id)
    if (job && job._controller) {
      job._controller.abort()
    }
  }

  function retryUpload(id) {
    const job = uploads.value.find(u => u.id === id)
    if (!job) return
    job.status = 'uploading'
    job.progress = 0
    job.uploadedBytes = 0
    job.speed = 0
    job.error = null
    job._lastLoaded = 0
    job._lastTime = Date.now()

    const formData = new FormData()
    formData.append('file', job.file)

    const controller = new AbortController()
    job._controller = controller

    axios.post(`/api/v1/patients/${job.patientId}/files`, formData, {
      signal: controller.signal,
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (e.total) {
          job.progress = Math.round((e.loaded / e.total) * 100)
          job.uploadedBytes = e.loaded
          const now = Date.now()
          const elapsed = (now - job._lastTime) / 1000
          if (elapsed > 0.5) {
            const deltaBytes = e.loaded - job._lastLoaded
            job.speed = Math.round(deltaBytes / elapsed)
            job._lastLoaded = e.loaded
            job._lastTime = now
          }
        }
      },
    }).then(() => {
      job.status = 'completed'
      job.progress = 100
      job.speed = 0
    }).catch((err) => {
      if (axios.isCancel(err) || err.name === 'CanceledError') {
        job.status = 'cancelled'
      } else {
        job.status = 'failed'
        job.error = err.response?.data?.message || err.message || 'Upload failed'
      }
    })
  }

  function clearCompleted() {
    uploads.value = uploads.value.filter(u => u.status === 'uploading' || u.status === 'failed')
  }

  function formatSize(bytes) {
    if (!bytes || bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
  }

  function formatSpeed(bytesPerSec) {
    if (!bytesPerSec || bytesPerSec <= 0) return ''
    return formatSize(bytesPerSec) + '/s'
  }

  return {
    uploads,
    uploadFile,
    cancelUpload,
    retryUpload,
    clearCompleted,
    formatSize,
    formatSpeed,
  }
}
