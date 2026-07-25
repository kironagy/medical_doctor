// ═════════════════════════════════════════════════════════════════════════
// useSyncEngine — Phase 7: Automatic Connectivity-Based Sync Engine
// ═════════════════════════════════════════════════════════════════════════
//
// Architecture:
//   SQLite is the LOCAL SOURCE OF TRUTH.
//   The API is the REMOTE SOURCE OF TRUTH.
//   This engine connects them with ROBUST ordering.
//
// Sync Order (MANDATORY):
//   1. Patients (pending_create / pending_update)
//   2. Files (pending_upload / failed) — only after patient is synced
//   3. Deletes (pending_delete)
//
// Features:
//   ✔ Detects online/offline transitions
//   ✔ Automatically syncs when connection returns
//   ✔ Resumes after app restart
//   ✔ Resumes after process kill
//   ✔ Never loses pending operations
//   ✔ Never duplicates uploads
//   ✔ Handles retry logic
//   ✔ Reports progress to the UI
//   ✔ Continues from where it stopped
//   ✔ Is idempotent
//
// Singleton Pattern:
//   Module-level state is initialized ONCE on first import. Event listeners
//   and heartbeat are registered once. The composable function just exposes
//   the shared reactive state. Multiple components can use useSyncEngine()
//   without duplicating listeners or intervals.
// ═════════════════════════════════════════════════════════════════════════

import axios from 'axios'
import { ref, computed } from 'vue'
import { useWorkspace } from './useWorkspace'

// ─── Module-level state ──────────────────────────────────────────────────
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const isSyncing = ref(false)
const lastSyncResult = ref(null)
const pendingSummary = ref({ patients: 0, files: 0, deletes: 0, total: 0 })

/** Track previous online state so the heartbeat can detect transitions. */
let previousOnlineState = typeof navigator !== 'undefined' ? navigator.onLine : true

/**
 * Deduplication guard: prevents concurrent sync triggers from multiple sources.
 * All network detection paths (native bridge, browser events, connection API,
 * visibility change, heartbeat) call this function. Only one sync runs at a
 * time. The flag is cleared when sync completes (success or failure).
 */
let onlineSyncGuard = false

const SYNC_ENDPOINT = '/_native/api/sync/engine'
const PENDING_ENDPOINT = '/_native/api/sync/pending-summary'

// ─── Singleton Initialisation (runs ONCE when module is first imported) ──
let initialised = false

function initialise() {
    if (initialised) return
    initialised = true

    if (typeof window === 'undefined') return

    // Sync previousOnlineState with the current nav state
    previousOnlineState = navigator.onLine

    // ═════════════════════════════════════════════════════════════════════
    //  NETWORK DETECTION PATH 1: Browser connectivity events
    // ═════════════════════════════════════════════════════════════════════
    // Standard browser API. Fires when the browser detects connectivity
    // changes. May not fire reliably in Android WebViews.
    window.addEventListener('online', handleOnlineEvent)
    window.addEventListener('offline', handleOfflineEvent)

    // ═════════════════════════════════════════════════════════════════════
    //  NETWORK DETECTION PATH 2: Network Information API
    // ═════════════════════════════════════════════════════════════════════
    // Provides real-time network type/quality changes. Used as a fallback
    // when browser 'online' event doesn't fire in WebViews.
    if (navigator.connection && typeof navigator.connection.addEventListener === 'function') {
        navigator.connection.addEventListener('change', handleConnectionChange)
    }

    // ═════════════════════════════════════════════════════════════════════
    //  NETWORK DETECTION PATH 3: Visibility / focus changes
    // ═════════════════════════════════════════════════════════════════════
    // When the user switches back to the app (e.g. after turning on WiFi
    // in Settings), these events fire. We re-check connectivity and sync.
    document.addEventListener('visibilitychange', handleVisibilityChange)
    window.addEventListener('focus', handleWindowFocus)

    // ═════════════════════════════════════════════════════════════════════
    //  NETWORK DETECTION PATH 4: Native bridge callback
    // ═════════════════════════════════════════════════════════════════════
    // The native Android NetworkStateManager can call these functions
    // directly via webView.evaluateJavascript("window.__onNetworkAvailable()").
    // They route through attemptSync() so the onlineSyncGuard prevents
    // duplicates with other detection paths.
    window.__triggerSync = () => attemptSync('native:bridge')
    window.__onNetworkAvailable = () => attemptSync('native:bridge') // alias for native use

    // Also check if the native bridge exposes a network state property
    checkNativeNetworkState()

    // ═════════════════════════════════════════════════════════════════════
    //  NETWORK DETECTION PATH 5: Periodic heartbeat (fallback)
    // ═════════════════════════════════════════════════════════════════════
    // Runs every 15 seconds. Detects offline→online transitions by
    // tracking previousOnlineState. Also refreshes the pending summary.
    // This is a last-resort fallback when all event-based paths fail.
    setInterval(runHeartbeat, 30000)

    // Initial heartbeat + summary fetch
    setTimeout(() => {
        runHeartbeat()
        refreshPendingSummary()
    }, 500)

    console.log('[SyncEngine] Initialized with 5 network detection paths')
}

/**
 * Safely attempt a sync. Used by all network detection paths.
 * Prevents duplicate syncs via the onlineSyncGuard flag.
 */
async function attemptSync(source) {
    if (onlineSyncGuard) {
        console.log('[SyncEngine] ⏭ Sync already triggered by another path (' + source + ' skipped, guard active)')
        return
    }
    onlineSyncGuard = true
    console.log('[SyncEngine] 🌐 Network restored via ' + source + ' — starting sync')

    isOnline.value = true
    previousOnlineState = true

    // Small delay to ensure the embedded server and network are stable
    await new Promise(r => setTimeout(r, 500))
    try {
        await triggerSync()
    } finally {
        // CRITICAL: Always clear guard — even if triggerSync() throws.
        // Without this finally, onlineSyncGuard would leak to 'true' forever
        // and ALL future sync attempts would be silently blocked.
        onlineSyncGuard = false
    }
}

async function handleOnlineEvent() {
    console.log('[SyncEngine] 🔵 PATH 1: Browser online event (navigator.onLine=' + navigator.onLine + ')')
    await attemptSync('browser:online')
}

function handleOfflineEvent() {
    console.log('[SyncEngine] 🔴 Browser offline event')
    isOnline.value = false
    previousOnlineState = false
}

function handleConnectionChange() {
    const online = typeof navigator !== 'undefined' ? navigator.onLine : true
    console.log('[SyncEngine] 🟡 PATH 2: Connection API change (navigator.onLine=' + online + ')')
    if (online) {
        attemptSync('connection:api')
    } else {
        isOnline.value = false
        previousOnlineState = false
    }
}

function handleVisibilityChange() {
    if (document.visibilityState === 'visible') {
        const online = typeof navigator !== 'undefined' ? navigator.onLine : true
        console.log('[SyncEngine] 🟢 PATH 3: App became visible (navigator.onLine=' + online + ')')
        if (online) {
            attemptSync('visibility:change')
        }
    }
}

function handleWindowFocus() {
    const online = typeof navigator !== 'undefined' ? navigator.onLine : true
    console.log('[SyncEngine] 🟢 PATH 3: Window focused (navigator.onLine=' + online + ')')
    if (online) {
        attemptSync('window:focus')
    }
}

function checkNativeNetworkState() {
    // Check if the native bridge exposes a network state
    const nativeOnline = window.NativePHP?.networkState ?? window.NativePHP?.isOnline ?? null
    if (nativeOnline === true) {
        console.log('[SyncEngine] 🟣 PATH 4: Native bridge reports ONLINE')
        attemptSync('native:bridge')
    }
}

async function runHeartbeat() {
    // Skip when page is hidden (app in background, screen off)
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
        return
    }

    const currentState = typeof navigator !== 'undefined' ? navigator.onLine : true
    const stateChanged = currentState !== previousOnlineState

    // ── Detect offline→online transition ──────────────────────────────
    // This catches cases where the browser 'online' event didn't fire
    // (known issue with Android WebViews). The heartbeat detects the
    // state change and triggers the sync engine as a fallback.
    if (currentState && !previousOnlineState) {
        console.log('[SyncEngine] 💓 PATH 5: Heartbeat detected offline→online transition (prev=' + previousOnlineState + ' → cur=' + currentState + ')')
        // Use attemptSync() so the onlineSyncGuard prevents duplicate syncs
        // when multiple detection paths fire simultaneously.
        await attemptSync('heartbeat:transition')
        return
    }

    // ── Detect online→offline transition ──────────────────────────────
    if (!currentState && previousOnlineState) {
        console.log('[SyncEngine] Heartbeat detected online→offline transition')
        isOnline.value = false
        previousOnlineState = false
        return
    }

    // ── No state change — just update summary ─────────────────────────
    isOnline.value = currentState
    if (currentState) {
        await refreshPendingSummary()
    }
}

// ─── Public API ──────────────────────────────────────────────────────────

/**
 * Trigger a full sync cycle (patients → files → deletes).
 * Returns the sync result object.
 */
async function triggerSync() {
    if (isSyncing.value) {
        console.log('[SyncEngine] ⚠ Already syncing, skipping duplicate trigger')
        return lastSyncResult.value
    }

    isSyncing.value = true
    console.log('[SyncEngine] 🚀 POST ' + SYNC_ENDPOINT + ' — Starting full sync cycle...')

    try {
        const res = await axios.post(SYNC_ENDPOINT, {}, { timeout: 120000 })
        console.log('[SyncEngine] ✅ POST ' + SYNC_ENDPOINT + ' — status=' + res.status + ' success=' + (res.data?.success ?? 'unknown'))
        const data = res.data

        lastSyncResult.value = {
            success: data.success,
            patients: data.results?.patients || 0,
            files_uploaded: data.results?.files?.uploaded || 0,
            files_failed: data.results?.files?.failed || 0,
            deletes: data.results?.deletes || 0,
            timestamp: new Date().toISOString(),
            message: data.message || '',
        }

        console.log('[SyncEngine] 📊 Sync result:', JSON.stringify(lastSyncResult.value))

        // Refresh the pending summary
        await refreshPendingSummary()

        // If sync included patients or files, refresh the patient list
        if (data.results?.patients > 0 || data.results?.files?.uploaded > 0) {
            console.log('[SyncEngine] 🔄 Sync changed data, refreshing patient list')
            const { refreshPatientList } = useWorkspace()
            await refreshPatientList()
        } else {
            console.log('[SyncEngine] ➖ No changes from sync, skipping patient list refresh')
        }

        return lastSyncResult.value
    } catch (err) {
        console.error('[SyncEngine] ❌ POST ' + SYNC_ENDPOINT + ' FAILED:', err.message, '(status=' + (err.response?.status || 'none') + ' response=' + JSON.stringify(err.response?.data || {}).substring(0, 200) + ')')
        lastSyncResult.value = {
            success: false,
            error: err.message || 'Sync request failed',
            timestamp: new Date().toISOString(),
        }
        return lastSyncResult.value
    } finally {
        isSyncing.value = false
    }
}

/**
 * Refresh the pending operations summary from the local Laravel.
 */
async function refreshPendingSummary() {
    try {
        console.log('[SyncEngine] 📡 GET ' + PENDING_ENDPOINT)
        const res = await axios.get(PENDING_ENDPOINT, { timeout: 5000 })
        const summary = res.data || { patients: 0, files: 0, deletes: 0, total: 0 }
        pendingSummary.value = summary
        console.log('[SyncEngine] 📡 Pending summary:', JSON.stringify(summary))
    } catch (e) {
        console.warn('[SyncEngine] ⚠ GET ' + PENDING_ENDPOINT + ' failed:', e.message, '(status=' + (e.response?.status || 'none') + ')')
        // Keep previous value — don't reset to 0
    }
}

/**
 * Computed: whether there are any pending operations to sync.
 */
const hasPendingOperations = computed(() => {
    return pendingSummary.value.total > 0
})

/**
 * Computed: a human-readable summary of pending operations.
 */
const pendingSummaryText = computed(() => {
    const s = pendingSummary.value
    const parts = []
    if (s.patients > 0) parts.push(`${s.patients} patient${s.patients !== 1 ? 's' : ''}`)
    if (s.files > 0) parts.push(`${s.files} file${s.files !== 1 ? 's' : ''}`)
    if (s.deletes > 0) parts.push(`${s.deletes} delete${s.deletes !== 1 ? 's' : ''}`)
    if (parts.length === 0) return 'All synced'
    return `${parts.join(', ')} pending`
})

// ─── Initialise on import ───────────────────────────────────────────────
initialise()

// ─── Composable ──────────────────────────────────────────────────────────
export function useSyncEngine() {
    return {
        isOnline,
        isSyncing,
        lastSyncResult,
        pendingSummary,
        hasPendingOperations,
        pendingSummaryText,
        triggerSync,
        refreshPendingSummary,
    }
}
