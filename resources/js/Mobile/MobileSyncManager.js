import { initPullToRefresh, setRefreshCallback, triggerRefresh } from './PullToRefresh'

const SYNC_ENDPOINT = '/api/mobile/v1/sync'
const HEARTBEAT_INTERVAL = 60000
const RECONNECT_INTERVAL = 30000

let isOnline = navigator.onLine
let heartbeatTimer = null
let isSyncing = false
let lastSyncAt = null
let syncListeners = []

function notifyListeners(event, data) {
  syncListeners.forEach(fn => fn(event, data))
}

export function onSyncEvent(callback) {
  syncListeners.push(callback)
  return () => {
    syncListeners = syncListeners.filter(fn => fn !== callback)
  }
}

async function checkConnectivity() {
  try {
    const res = await fetch(`${SYNC_ENDPOINT}/status`, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      signal: AbortSignal.timeout(5000),
    })
    const wasOffline = !isOnline
    isOnline = res.ok
    if (wasOffline && isOnline) {
      notifyListeners('online', {})
      syncNow(true)
    }
    return isOnline
  } catch {
    if (isOnline) {
      isOnline = false
      notifyListeners('offline', {})
    }
    return false
  }
}

async function syncNow(silent = false) {
  if (isSyncing) return { synced: false, reason: 'already_syncing' }
  if (!isOnline) return { synced: false, reason: 'offline', offline: true }

  isSyncing = true
  notifyListeners('sync-start', {})

  try {
    const body = { entities: ['patients', 'files', 'visits', 'notes', 'categories', 'shares', 'doctors'] }
    if (lastSyncAt) body.last_sync_at = lastSyncAt

    const res = await fetch(`${SYNC_ENDPOINT}/pull`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(30000),
    })

    if (!res.ok) throw new Error(`Sync failed: ${res.status}`)

    const result = await res.json()
    if (result.server_time) lastSyncAt = result.server_time
    localStorage.setItem('mobile_last_sync_at', lastSyncAt)

    const counts = {}
    if (result.data) {
      for (const [entity, items] of Object.entries(result.data)) {
        counts[entity] = items?.length || 0
      }
    }

    notifyListeners('sync-complete', { counts, server_time: result.server_time })

    if (!silent && counts.patients > 0) {
      window.location.reload()
    }

    return { synced: true, counts }
  } catch (err) {
    if (err.name === 'AbortError') {
      notifyListeners('sync-timeout', {})
    } else {
      notifyListeners('sync-error', { error: err.message })
    }
    return { synced: false, error: err.message }
  } finally {
    isSyncing = false
  }
}

function startHeartbeat() {
  stopHeartbeat()
  heartbeatTimer = setInterval(checkConnectivity, HEARTBEAT_INTERVAL)
}

function stopHeartbeat() {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer)
    heartbeatTimer = null
  }
}

export function initMobileSync() {
  lastSyncAt = localStorage.getItem('mobile_last_sync_at')

  initPullToRefresh(() => syncNow(false))

  checkConnectivity()
  startHeartbeat()

  window.addEventListener('online', () => {
    isOnline = true
    notifyListeners('online', {})
    syncNow(true)
  })

  window.addEventListener('offline', () => {
    isOnline = false
    notifyListeners('offline', {})
  })

  notifyListeners('ready', { isOnline })
}

export { isOnline, isSyncing, syncNow, checkConnectivity }
