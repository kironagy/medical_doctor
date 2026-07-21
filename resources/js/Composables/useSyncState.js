/**
 * useSyncState.js — Shared reactive sync state composable.
 *
 * Provides global sync status, connectivity tracking, and pending
 * operations count to all components. This is the single source of
 * truth for sync-related UI state.
 *
 * Architecture:
 * - Module-level reactive state shared across all useSyncState() callers
 * - Connectivity listeners (online/offline) set up once at module level
 * - Polling managed via startPolling/stopPolling lifecycle functions
 * - Components should call startPolling() in onMounted, stopPolling() in onUnmounted
 */
import { ref, computed } from 'vue'
import axios from 'axios'

// ── Module-level state (shared across all component instances) ─────
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const lastSyncAt = ref(null)
const pendingCount = ref(0)
const syncInProgress = ref(false)
const lastSyncError = ref(null)
const recentlyChangedResources = ref([])

// Polling state (module-level, shared)
let pollIntervalId = null
let pollRefCount = 0

// ── Connectivity listeners (set up once at module level) ──────────
if (typeof window !== 'undefined') {
  window.addEventListener('online', () => {
    isOnline.value = true
    console.log('[useSyncState] Network online')
  })

  window.addEventListener('offline', () => {
    isOnline.value = false
    console.log('[useSyncState] Network offline')
  })
}

/**
 * Start the sync state polling interval.
 * Reference-counted: each call increments the ref count.
 * The interval only runs when at least one caller is active.
 */
function startSyncPolling() {
  pollRefCount++
  if (pollIntervalId) return // Already polling

  pollIntervalId = setInterval(async () => {
    try {
      const res = await axios.get('/api/native/sync/status', {
        timeout: 5000,
        headers: { 'Accept': 'application/json' },
      })
      if (res.data) {
        pendingCount.value = res.data.pending_count ?? 0
        lastSyncAt.value = res.data.last_sync_at ?? null
        syncInProgress.value = res.data.sync_in_progress ?? false
      }
    } catch (e) {
      // Silent — background polling shouldn't disrupt the UI
    }
  }, 15000) // Poll every 15 seconds

  console.log('[useSyncState] Polling started (refCount=' + pollRefCount + ')')
}

/**
 * Stop the sync state polling.
 * Reference-counted: only stops when all callers have stopped.
 */
function stopSyncPolling() {
  pollRefCount = Math.max(0, pollRefCount - 1)
  if (pollRefCount === 0 && pollIntervalId) {
    clearInterval(pollIntervalId)
    pollIntervalId = null
    console.log('[useSyncState] Polling stopped')
  }
}

// ── Composable ────────────────────────────────────────────────────
export function useSyncState() {
  /**
   * Add a resource to the recently changed list.
   */
  function markResourceChanged(resource) {
    recentlyChangedResources.value = [
      { ...resource, changedAt: new Date().toISOString() },
      ...recentlyChangedResources.value,
    ].slice(0, 50) // Keep last 50 changes
  }

  /**
   * Clear recently changed resources.
   */
  function clearRecentChanges() {
    recentlyChangedResources.value = []
  }

  /**
   * Force a sync state refresh from the server.
   */
  async function refreshSyncState() {
    try {
      const res = await axios.get('/api/native/sync/status', {
        timeout: 5000,
        headers: { 'Accept': 'application/json' },
      })
      if (res.data) {
        pendingCount.value = res.data.pending_count ?? 0
        lastSyncAt.value = res.data.last_sync_at ?? null
        syncInProgress.value = res.data.sync_in_progress ?? false
      }
    } catch (e) {
      // Silent fail
    }
  }

  return {
    // State (shared)
    isOnline,
    lastSyncAt,
    pendingCount,
    syncInProgress,
    lastSyncError,
    recentlyChangedResources,

    // Computed helpers
    hasPendingOperations: computed(() => pendingCount.value > 0),
    isConnected: computed(() => isOnline.value),
    isSyncing: computed(() => syncInProgress.value),
    pendingOpsCount: computed(() => pendingCount.value),

    // Methods
    markResourceChanged,
    clearRecentChanges,
    refreshSyncState,
    startSyncPolling,
    stopSyncPolling,
  }
}
