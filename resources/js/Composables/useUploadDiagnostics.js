// ═══════════════════════════════════════════════════════════════════════════════
// Upload Diagnostics & Performance Instrumentation
// ───────────────────────────────────────────────────────────────────────────────
// Toggle: set UPLOAD_DEBUG=true in browser console:  localStorage.debug='1'
//         or pass ?debug=1 in the URL.
// When disabled, ALL diagnostic code is a no-op (single guard check per call).
// ═══════════════════════════════════════════════════════════════════════════════

const UPLOAD_DEBUG = (() => {
  const ls = typeof localStorage !== 'undefined' && localStorage.getItem('upload_debug')
  if (ls) return ls === '1' || ls === 'true'
  if (typeof location !== 'undefined') return /debug=1/.test(location.search)
  return false
})()

let uidCounter = 0
function uid() { return ++uidCounter }

function pad2(n) { return String(n).padStart(2, '0') }
function pad3(n) { return String(n).padStart(3, '0') }
function ts() {
  const d = new Date()
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}.${pad3(d.getMilliseconds())}`
}
function fmtBytes(b) {
  if (!b || b === 0) return '0 B'
  const k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(b) / Math.log(k))
  return parseFloat((b / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}
function fmtDuration(ms) {
  if (ms < 1000) return ms.toFixed(1) + ' ms'
  if (ms < 60000) return (ms / 1000).toFixed(2) + ' sec'
  const m = Math.floor(ms / 60000)
  const s = (ms % 60000) / 1000
  return `${m}m ${s.toFixed(1)}s`
}

// ─── Device diagnostics (collected once) ──────────────────────────────────────
let _device = null
function collectDevice() {
  if (_device) return _device
  const ua = navigator.userAgent
  const mobile = /Mobi|Android|iPhone|iPad|iPod/i.test(ua)
  const os = (() => {
    if (/Windows/i.test(ua)) return 'Windows'
    if (/Mac OS X/.test(ua)) return 'macOS'
    if (/Linux/.test(ua) && !/Android/.test(ua)) return 'Linux'
    if (/Android/.test(ua)) return 'Android'
    if (/iOS|iPhone|iPad|iPod/.test(ua)) return 'iOS'
    return 'Unknown'
  })()
  const browser = (() => {
    if (/Edg\//.test(ua)) return 'Edge'
    if (/Chrome\//.test(ua)) return 'Chrome'
    if (/Firefox\//.test(ua)) return 'Firefox'
    if (/Safari\//.test(ua)) return 'Safari'
    if (/SamsungBrowser/.test(ua)) return 'Samsung Internet'
    return 'Unknown'
  })()
  const bver = (() => {
    const m = ua.match(/(Chrome|Firefox|Edg|Version)\/([\d.]+)/)
    return m ? m[2] : '?'
  })()
  _device = {
    browser, bver, os, mobile,
    platform: navigator.platform || '',
    userAgent: ua,
    screen: `${screen.width}x${screen.height}`,
    viewport: `${window.innerWidth}x${window.innerHeight}`,
    dpr: window.devicePixelRatio || 1,
    cores: navigator.hardwareConcurrency || '?',
    memory: navigator.deviceMemory || '?',
    touch: 'ontouchstart' in window,
    lang: navigator.language || '',
    tz: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
  }
  return _device
}

// ─── Network diagnostics ──────────────────────────────────────────────────────
function collectNetwork() {
  const c = navigator.connection
  if (!c) return null
  return {
    type: c.effectiveType || '?',
    downlink: c.downlink || 0,
    rtt: c.rtt || 0,
    saveData: c.saveData || false,
    maxDownlink: 'downlinkMax' in c ? c.downlinkMax : '?',
  }
}

// ─── Memory snapshot ──────────────────────────────────────────────────────────
function memorySnap() {
  const m = performance.memory
  if (!m) return null
  return {
    used: m.usedJSHeapSize,
    total: m.totalJSHeapSize,
    limit: m.jsHeapSizeLimit,
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Diagnostics instance (one per upload)
// ═══════════════════════════════════════════════════════════════════════════════
export class UploadDiagnostics {
  constructor(file, patientId) {
    if (!UPLOAD_DEBUG) return

    this._id = uid()
    this._clientId = crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}_${this._id}`
    this._file = file
    this._patientId = patientId
    this._t0 = performance.now()
    this._fileSelectedAt = performance.now()
    this._uploadUuid = null
    this._device = collectDevice()
    this._networkStart = collectNetwork()

    // Timelines
    this._events = []
    this._chunks = new Map()     // chunkIndex → ChunkMetrics
    this._chunksCreated = []      // for pre-generation detection
    this._errors = []
    this._memorySamples = []
    this._networkSamples = [{ ...this._networkStart, at: performance.now() - this._t0 }]
    this._poolSnapshots = []
    this._firstChunkStartedAt = null
    this._mergeStart = null
    this._mergeEnd = null
    this._serverData = {}         // accumulated server-side timing

    this._chunksInMemory = 0
    this._maxChunksInMemory = 0
    this._poolSizeHistory = []
    this._sequentialDetected = false
    this._allPregenerated = false

    this._networkListener = null

    this._record('file_selected', {
      name: file.name,
      size: file.size,
      mime: file.type || '?',
      totalChunks: Math.ceil(file.size / (5 * 1024 * 1024)),
      chunkSize: 5 * 1024 * 1024,
    })
  }

  // ── internal ────────────────────────────────────────────────────────────────
  _record(name, data = {}) {
    if (!UPLOAD_DEBUG) return
    const now = performance.now()
    this._events.push({ name, at: now, rel: now - this._t0, ...data })
  }

  _now() { return performance.now() }
  _rel() { return performance.now() - this._t0 }

  setUploadUuid(uuid) {
    if (!UPLOAD_DEBUG) return
    this._uploadUuid = uuid
    this._record('session_created', { uuid })
  }

  // ── File diagnostics ────────────────────────────────────────────────────────
  logFileInfo() {
    if (!UPLOAD_DEBUG) return
    const dev = this._device
    const net = this._networkStart

    // Detect startup delay
    const startupDelay = performance.now() - this._fileSelectedAt
    const hasWarning = startupDelay > 500

    console.groupCollapsed(
      `%c[UPLOAD${this._id}] ${this._file.name}`,
      'color:#6366f1;font-weight:bold'
    )

    console.log('Upload ID (client):', this._clientId)
    console.log('Timestamp:', ts())
    console.log('')

    // Device
    console.group('Device')
    console.log('Browser:', `${dev.browser} ${dev.bver}`)
    console.log('OS:', dev.os)
    console.log('Device:', dev.mobile ? 'Mobile' : 'Desktop')
    console.log('Platform:', dev.platform)
    console.log('User Agent:', dev.userAgent)
    console.log('Screen:', dev.screen)
    console.log('Viewport:', dev.viewport)
    console.log('Pixel Ratio:', dev.dpr)
    console.log('CPU Cores:', dev.cores)
    console.log('Device Memory:', dev.memory + ' GB')
    console.log('Touch:', dev.touch)
    console.log('Language:', dev.lang)
    console.log('Timezone:', dev.tz)
    console.groupEnd()

    // Network
    if (net) {
      console.groupCollapsed('Network')
      console.log('Connection Type:', net.type)
      console.log('Downlink:', net.downlink + ' Mbps')
      console.log('RTT:', net.rtt + ' ms')
      console.log('Save Data:', net.saveData)
      if (net.maxDownlink) console.log('Max Downlink:', net.maxDownlink + ' Mbps')
      console.groupEnd()
    }

    // File
    console.group('File')
    const ext = this._file.name.includes('.')
      ? this._file.name.split('.').pop()
      : '(none)'
    console.log('File Name:', this._file.name)
    console.log('Extension:', ext)
    console.log('MIME Type:', this._file.type || 'application/octet-stream')
    console.log('Size:', fmtBytes(this._file.size), `(${this._file.size} bytes)`)
    console.log('Last Modified:', new Date(this._file.lastModified).toISOString())
    console.log('Total Chunks:', Math.ceil(this._file.size / (5 * 1024 * 1024)))
    console.log('Chunk Size:', fmtBytes(5 * 1024 * 1024))
    console.groupEnd()

    // Startup delay
    console.groupCollapsed('Startup Delay')
    console.log('File selected → first upload:', fmtDuration(startupDelay))
    if (hasWarning) {
      console.log('%c WARNING  Large startup delay detected.', 'color:red;font-weight:bold')
    }
    console.groupEnd()

    if (hasWarning) {
      console.log(
        '%c WARNING  Large startup delay detected (' + fmtDuration(startupDelay) + ')',
        'color:red;font-weight:bold;font-size:14px'
      )
    }

    // Memory
    const mem = memorySnap()
    if (mem) {
      console.groupCollapsed('Memory (initial)')
      console.log('Used Heap:', fmtBytes(mem.used))
      console.log('Total Heap:', fmtBytes(mem.total))
      console.log('Heap Limit:', fmtBytes(mem.limit))
      console.groupEnd()
    }

    console.groupEnd()
  }

  // ── Network listener ────────────────────────────────────────────────────────
  startNetworkMonitor() {
    if (!UPLOAD_DEBUG) return
    const c = navigator.connection
    if (!c) return
    this._networkListener = () => {
      const snap = collectNetwork()
      if (snap) {
        this._networkSamples.push({ ...snap, at: this._rel() })
        console.log(
          `%c[UPLOAD${this._id}] ⚡ Network changed: type=${snap.type}, downlink=${snap.downlink}Mbps, rtt=${snap.rtt}ms`,
          'color:orange;font-weight:bold'
        )
      }
    }
    c.addEventListener('change', this._networkListener)
  }

  stopNetworkMonitor() {
    if (!UPLOAD_DEBUG) return
    if (this._networkListener) {
      navigator.connection?.removeEventListener('change', this._networkListener)
      this._networkListener = null
    }
  }

  // ── Memory monitoring ───────────────────────────────────────────────────────
  sampleMemory() {
    if (!UPLOAD_DEBUG) return
    const m = memorySnap()
    if (m) {
      const prev = this._memorySamples.length > 0
        ? this._memorySamples[this._memorySamples.length - 1]
        : null
      const growth = prev ? m.used - prev.used : 0
      this._memorySamples.push({ ...m, growth, at: this._rel() })
    }
  }

  // ── Pool monitoring ─────────────────────────────────────────────────────────
  snapshotPool(activeCount) {
    if (!UPLOAD_DEBUG) return
    this._poolSizeHistory.push({ active: activeCount, at: this._rel() })
  }

  setChunksInMemory(n) {
    if (!UPLOAD_DEBUG) return
    this._chunksInMemory = n
    if (n > this._maxChunksInMemory) this._maxChunksInMemory = n
  }

  // ── Chunk generation mode detection ─────────────────────────────────────────
  markChunkCreated(chunkIndex) {
    if (!UPLOAD_DEBUG) return
    this._chunksCreated.push(chunkIndex)
  }

  setAllPregenerated(val) {
    if (!UPLOAD_DEBUG) return
    this._allPregenerated = val
  }

  // ── Chunk diagnostics ──────────────────────────────────────────────────────
  onChunkBlobStart(chunkIndex) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex) || this._newChunkMetrics(chunkIndex)
    m.blobSliceStart = performance.now()
  }

  onChunkBlobEnd(chunkIndex, blobSize) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex)
    if (!m) return
    m.blobSliceEnd = performance.now()
    m.blobSliceDuration = m.blobSliceEnd - m.blobSliceStart
    m.chunkSize = blobSize
  }

  onChunkQueued(chunkIndex) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex) || this._newChunkMetrics(chunkIndex)
    m.queuedAt = performance.now()
    if (m.blobSliceStart) {
      m.queueWaitStart = m.blobSliceEnd || m.blobSliceStart
    }
    this._record('chunk_queued', { chunkIndex })
  }

  onChunkUploadStarted(chunkIndex) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex) || this._newChunkMetrics(chunkIndex)
    m.uploadStartedAt = performance.now()
    if (m.queuedAt) {
      m.queueWait = m.uploadStartedAt - (m.queueWaitStart || m.queuedAt)
    }
    if (!this._firstChunkStartedAt) {
      this._firstChunkStartedAt = performance.now()
      this._record('first_chunk_started', { chunkIndex })
    }
    this._record('chunk_upload_started', { chunkIndex })
  }

  onChunkUploadProgress(chunkIndex, loaded, total) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex)
    if (!m) return
    if (!m.firstByteSentAt && loaded > 0) {
      m.firstByteSentAt = performance.now()
    }
    m.lastLoaded = loaded
  }

  onChunkResponseReceived(chunkIndex) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex)
    if (!m) return
    m.responseReceivedAt = performance.now()
  }

  onChunkRetry(chunkIndex, attempt, delay, error) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex)
    if (!m) return
    if (!m.retries) m.retries = []
    m.retries.push({ attempt, delay, error: error?.message || String(error), at: this._rel() })
    this._record('chunk_retry', { chunkIndex, attempt, delay })
  }

  onChunkComplete(chunkIndex, httpStatus, parallelCount) {
    if (!UPLOAD_DEBUG) return
    const m = this._chunks.get(chunkIndex)
    if (!m) return
    m.completedAt = performance.now()
    m.httpStatus = httpStatus
    m.parallelCount = parallelCount
    if (m.uploadStartedAt) {
      m.totalUploadTime = m.completedAt - m.uploadStartedAt
      m.avgSpeed = m.chunkSize > 0 && m.totalUploadTime > 0
        ? Math.round(m.chunkSize / (m.totalUploadTime / 1000))
        : 0
    }

    const queueWait = m.queueWait || 0
    const blobs = m.blobSliceDuration || 0
    const total = m.totalUploadTime || 0

    const net = collectNetwork()

    console.group(`%cChunk #${chunkIndex}`, 'color:#8b5cf6;font-weight:bold')
    console.log('Chunk Size:', fmtBytes(m.chunkSize))
    console.log('Blob.slice() Duration:', fmtDuration(blobs))
    console.log('Queue Wait:', fmtDuration(queueWait))
    console.log('Upload Started:', new Date(m.uploadStartedAt).toLocaleTimeString())
    console.log('Upload Finished:', new Date(m.completedAt).toLocaleTimeString())
    console.log('Duration:', fmtDuration(total))
    console.log('Average Speed:', fmtBytes(m.avgSpeed) + '/s')
    console.log('Retry:', (m.retries ? m.retries.length : 0))
    console.log('HTTP Status:', httpStatus)
    if (net) {
      console.log('Network:', `type=${net.type}, downlink=${net.downlink}Mbps, rtt=${net.rtt}ms`)
    }
    console.log('Parallel Uploads:', parallelCount)
    console.groupEnd()

    this._record('chunk_completed', {
      chunkIndex, size: m.chunkSize, duration: total, speed: m.avgSpeed,
    })
  }

  _newChunkMetrics(chunkIndex) {
    if (!UPLOAD_DEBUG) return
    const m = {
      chunkIndex,
      blobSliceStart: 0,
      blobSliceEnd: 0,
      blobSliceDuration: 0,
      chunkSize: 0,
      queuedAt: 0,
      queueWaitStart: 0,
      queueWait: 0,
      uploadStartedAt: 0,
      firstByteSentAt: 0,
      responseReceivedAt: 0,
      completedAt: 0,
      totalUploadTime: 0,
      avgSpeed: 0,
      httpStatus: 0,
      parallelCount: 0,
      lastLoaded: 0,
      retries: null,
    }
    this._chunks.set(chunkIndex, m)
    return m
  }

  // ── Merge diagnostics ──────────────────────────────────────────────────────
  onMergeStart() {
    if (!UPLOAD_DEBUG) return
    this._mergeStart = performance.now()
    this._record('merge_started')
    console.log(`%c[UPLOAD${this._id}] Merge started`, 'color:#f59e0b')
  }

  onMergeComplete(data) {
    if (!UPLOAD_DEBUG) return
    this._mergeEnd = performance.now()
    this._mergeData = data
    this._record('merge_completed', data)

    const duration = this._mergeEnd - this._mergeStart
    console.log(`%c[UPLOAD${this._id}] Merge finished in ${fmtDuration(duration)}`, 'color:#10b981;font-weight:bold')
    if (data) {
      console.groupCollapsed('Merge Result')
      for (const [k, v] of Object.entries(data)) console.log(k + ':', v)
      console.groupEnd()
    }
  }

  // ── Server timing ───────────────────────────────────────────────────────────
  recordServerTiming(chunkIndex, serverData) {
    if (!UPLOAD_DEBUG) return
    this._serverData[chunkIndex] = serverData
  }

  // ── Error diagnostics ──────────────────────────────────────────────────────
  recordError(context, error) {
    if (!UPLOAD_DEBUG) return
    const entry = {
      context,
      message: error?.message || String(error),
      stack: error?.stack || '',
      at: this._rel(),
      name: error?.name || '',
      code: error?.code || '',
      response: error?.response ? {
        status: error.response.status,
        data: error.response.data,
      } : null,
    }
    this._errors.push(entry)
    this._record('error', { context, message: entry.message })

    console.group(`%c[UPLOAD${this._id}] ❌ Error: ${context}`, 'color:red;font-weight:bold')
    console.log('Message:', entry.message)
    if (entry.stack) console.log('Stack:', entry.stack)
    if (entry.response) console.log('Response:', entry.response.status, entry.response.data)
    console.log('Relative Time:', fmtDuration(entry.at))
    console.groupEnd()
  }

  // ── Timeline ────────────────────────────────────────────────────────────────
  getTimeline() {
    if (!UPLOAD_DEBUG) return []
    return this._events.map(e => ({
      event: e.name,
      at: new Date(this._t0 + e.at).toISOString(),
      rel: fmtDuration(e.rel),
      ...e,
    }))
  }

  showTimeline() {
    if (!UPLOAD_DEBUG) return
    console.groupCollapsed('%cUpload Timeline', 'color:#6366f1;font-weight:bold')
    for (const e of this._events) {
      console.log(
        `[${ts()}] +${fmtDuration(e.rel)}  ${e.name}`,
        Object.keys(e).length > 2 ? e : ''
      )
    }
    console.groupEnd()
  }

  // ── Sequential detection ────────────────────────────────────────────────────
  detectSequential(currentActive) {
    if (!UPLOAD_DEBUG) return false
    if (currentActive < POOL_SIZE && this._poolSizeHistory.length > 5) {
      const recent = this._poolSizeHistory.slice(-5)
      const allBelow = recent.every(r => r.active < 2)
      if (allBelow && !this._sequentialDetected) {
        this._sequentialDetected = true
        console.log(
          `%c[UPLOAD${this._id}] ⚠ WARNING  Parallel upload lost. Expected: ${POOL_SIZE} active, Current: ${currentActive}`,
          'color:red;font-weight:bold;font-size:13px'
        )
        return true
      }
    }
    return false
  }

  // ── Final report ────────────────────────────────────────────────────────────
  generateReport() {
    if (!UPLOAD_DEBUG) return null

    const allChunks = Array.from(this._chunks.values())
    const completed = allChunks.filter(c => c.completedAt > 0)
    const uploadDuration = completed.length > 0
      ? Math.max(...completed.map(c => c.completedAt)) -
        (this._firstChunkStartedAt || this._fileSelectedAt)
      : 0
    const totalUploadTime = this._events.length > 0
      ? this._events[this._events.length - 1].at
      : 0

    const chunkDurations = completed.map(c => c.totalUploadTime).filter(Boolean)
    const chunkSpeeds = completed.map(c => c.avgSpeed).filter(Boolean)
    const queueWaits = completed.map(c => c.queueWait).filter(Boolean)

    const fastest = chunkDurations.length > 0 ? Math.min(...chunkDurations) : 0
    const slowest = chunkDurations.length > 0 ? Math.max(...chunkDurations) : 0
    const avgDuration = chunkDurations.length > 0
      ? chunkDurations.reduce((a, b) => a + b, 0) / chunkDurations.length
      : 0
    const avgSpeed = chunkSpeeds.length > 0
      ? chunkSpeeds.reduce((a, b) => a + b, 0) / chunkSpeeds.length
      : 0
    const avgQueue = queueWaits.length > 0
      ? queueWaits.reduce((a, b) => a + b, 0) / queueWaits.length
      : 0
    const maxQueue = queueWaits.length > 0 ? Math.max(...queueWaits) : 0
    const peakSpeed = chunkSpeeds.length > 0 ? Math.max(...chunkSpeeds) : 0

    const retryCount = allChunks.reduce((s, c) => s + (c.retries ? c.retries.length : 0), 0)
    const failedCount = allChunks.filter(c => !c.completedAt).length
    const startupDelay = this._firstChunkStartedAt
      ? this._firstChunkStartedAt - this._fileSelectedAt
      : 0

    const memStart = this._memorySamples.length > 0 ? this._memorySamples[0] : null
    const memPeak = this._memorySamples.length > 0
      ? this._memorySamples.reduce((p, s) => s.used > p.used ? s : p, this._memorySamples[0])
      : null

    const netEnd = collectNetwork()
    const netStart = this._networkStart

    const bottlenecks = []
    if (this._sequentialDetected) bottlenecks.push('Parallel uploads degenerated to sequential')
    if (avgQueue > 2000) bottlenecks.push(`High queue wait time (avg ${fmtDuration(avgQueue)})`)
    if (netStart && netStart.downlink < 2) bottlenecks.push(`Slow connection (${netStart.downlink} Mbps)`)
    if (startupDelay > 500) bottlenecks.push(`High startup delay (${fmtDuration(startupDelay)})`)
    if (retryCount > 3) bottlenecks.push(`High retry rate (${retryCount} retries)`)
    if (memPeak && memStart && (memPeak.used - memStart.used) > 100 * 1024 * 1024) {
      bottlenecks.push(`Memory spike (${fmtBytes(memPeak.used - memStart.used)} growth)`)
    }

    const report = {
      uploadId: this._clientId,
      serverUploadId: this._uploadUuid,
      result: this._errors.length > 0 ? 'FAILED' : 'SUCCESS',
      fileName: this._file?.name || '?',
      fileSize: this._file?.size || 0,
      device: { ...this._device },
      network: { start: this._networkStart, end: netEnd, samples: this._networkSamples },
      chunkSize: 5 * 1024 * 1024,
      totalChunks: allChunks.length,
      chunkGenerationMode: this._allPregenerated ? 'PREGENERATED' : 'LAZY STREAMING',
      parallelUploads: POOL_SIZE,
      averageQueueTime: fmtDuration(avgQueue),
      maxQueueTime: fmtDuration(maxQueue),
      fastestChunk: fmtDuration(fastest),
      slowestChunk: fmtDuration(slowest),
      averageChunkDuration: fmtDuration(avgDuration),
      averageUploadSpeed: fmtBytes(avgSpeed) + '/s',
      peakUploadSpeed: fmtBytes(peakSpeed) + '/s',
      retries: retryCount,
      failedChunks: failedCount,
      mergeDuration: this._mergeStart && this._mergeEnd
        ? fmtDuration(this._mergeEnd - this._mergeStart)
        : 'N/A',
      totalUploadTime: fmtDuration(totalUploadTime),
      clientTime: fmtDuration(performance.now() - this._t0),
      detectedBottlenecks: bottlenecks.length > 0 ? bottlenecks : ['None detected'],
      recommendations: this._buildRecommendations(bottlenecks, netStart),
      events: this._events,
      errors: this._errors,
      chunks: Object.fromEntries(this._chunks),
    }

    return report
  }

  _buildRecommendations(bottlenecks, net) {
    const recs = []
    if (bottlenecks.length === 0) recs.push('No bottlenecks detected — system performing optimally.')
    if (net && net.downlink < 1) recs.push('Very slow connection: consider reducing chunk size or enabling resumable uploads.')
    if (bottlenecks.some(b => b.includes('sequential'))) {
      recs.push('Uploads becoming sequential: check server supports concurrent chunk uploads.')
    }
    if (bottlenecks.some(b => b.includes('startup'))) {
      recs.push('Large startup delay: check file reading or init endpoint latency.')
    }
    if (bottlenecks.some(b => b.includes('retry'))) {
      recs.push('High retry rate: check network stability or server error rate.')
    }
    if (bottlenecks.some(b => b.includes('queue'))) {
      recs.push('High queue wait time: consider reducing POOL_SIZE or increasing server throughput.')
    }
    return recs
  }

  // ── Console report ──────────────────────────────────────────────────────────
  printReport() {
    if (!UPLOAD_DEBUG) return
    const r = this.generateReport()
    if (!r) return

    const line = '='.repeat(55)
    console.log(`%c${line}`, 'color:#6366f1')
    console.log('%cUPLOAD PERFORMANCE REPORT', 'color:#6366f1;font-weight:bold;font-size:16px')
    console.log(`%c${line}`, 'color:#6366f1')
    console.log('')
    console.log('Upload ID:', r.uploadId)
    console.log('Server ID:', r.serverUploadId || 'N/A')
    console.log('Result:', r.result === 'SUCCESS'
      ? '%cSUCCESS' : '%cFAILED',
      r.result === 'SUCCESS' ? 'color:#10b981;font-weight:bold' : 'color:red;font-weight:bold'
    )
    console.log('')
    console.group('File')
    console.log('Name:', r.fileName)
    console.log('Size:', fmtBytes(r.fileSize))
    console.groupEnd()
    console.log('')
    console.group('Device')
    console.log('Browser:', `${r.device.browser} ${r.device.bver}`)
    console.log('OS:', r.device.os)
    console.log('Connection:', `${r.network?.start?.type || '?'} (${r.network?.start?.downlink || '?'} Mbps)`)
    console.log('RTT:', r.network?.start?.rtt + ' ms' || '?')
    console.groupEnd()
    console.log('')
    console.group('Chunks')
    console.log('Chunk Size:', fmtBytes(r.chunkSize))
    console.log('Total Chunks:', r.totalChunks)
    console.log('Generation Mode:', r.chunkGenerationMode)
    console.log('Parallel Uploads:', r.parallelUploads)
    console.log('Average Queue Time:', r.averageQueueTime)
    console.log('Fastest Chunk:', r.fastestChunk)
    console.log('Slowest Chunk:', r.slowestChunk)
    console.log('Average Chunk Duration:', r.averageChunkDuration)
    console.log('Average Speed:', r.averageUploadSpeed)
    console.log('Peak Speed:', r.peakUploadSpeed)
    console.log('Retries:', r.retries)
    console.log('Failed Chunks:', r.failedChunks)
    console.groupEnd()
    console.log('')
    console.group('Timing')
    console.log('Merge Duration:', r.mergeDuration)
    console.log('Total Upload Time:', r.totalUploadTime)
    console.log('Client Time:', r.clientTime)
    console.groupEnd()
    console.log('')
    console.groupCollapsed('Bottlenecks')
    for (const b of r.detectedBottlenecks) {
      console.log(b.startsWith('None') ? `✅ ${b}` : `⚠ ${b}`)
    }
    console.groupEnd()
    console.log('')
    console.groupCollapsed('Recommendations')
    for (const rec of r.recommendations) console.log(`→ ${rec}`)
    console.groupEnd()
    console.log('')
    console.log(`%c${line}`, 'color:#6366f1')
  }

  // ── Export ──────────────────────────────────────────────────────────────────
  exportJSON() {
    const r = this.generateReport()
    if (!r) return ''
    return JSON.stringify(r, null, 2)
  }

  exportCSV() {
    if (!UPLOAD_DEBUG) return ''
    const lines = ['event,time_rel,time_abs']
    for (const e of this._events) {
      lines.push(`${e.name},${e.rel.toFixed(1)},${new Date(this._t0 + e.at).toISOString()}`)
    }
    lines.push('')
    lines.push('chunk,size,queue_wait,upload_duration,speed,retries')
    for (const [idx, c] of this._chunks) {
      lines.push(`${idx},${c.chunkSize || 0},${(c.queueWait || 0).toFixed(1)},${(c.totalUploadTime || 0).toFixed(1)},${c.avgSpeed || 0},${c.retries ? c.retries.length : 0}`)
    }
    return lines.join('\n')
  }

  downloadReport() {
    if (!UPLOAD_DEBUG) return
    const json = this.exportJSON()
    const csv = this.exportCSV()
    const blob = new Blob([json], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `upload_report_${this._clientId}.json`
    a.click()
    URL.revokeObjectURL(url)
    // Also log CSV to console for copy-paste
    console.log('%cUpload Report CSV (copy below):', 'color:#6366f1')
    console.log(csv)
  }

  // ── Cleanup ─────────────────────────────────────────────────────────────────
  destroy() {
    if (!UPLOAD_DEBUG) return
    this.stopNetworkMonitor()
    this._chunks.clear()
    this._events.length = 0
    this._memorySamples.length = 0
  }

  // ── Accessors used by UploadManager ─────────────────────────────────────────
  get isDebug() { return UPLOAD_DEBUG }
  get clientId() { return this._clientId }
  get hasReport() { return UPLOAD_DEBUG && this._events.length > 0 }
  get poolSizeHistory() { return this._poolSizeHistory }
}

// Module-level POOL_SIZE reference (will be set from useUploads.js)
const POOL_SIZE = 3

// ── Factory ───────────────────────────────────────────────────────────────────
export function useUploadDiagnostics(file, patientId) {
  if (!UPLOAD_DEBUG) return null
  return new UploadDiagnostics(file, patientId)
}
