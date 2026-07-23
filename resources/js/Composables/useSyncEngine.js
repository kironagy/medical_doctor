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

    // Register browser connectivity events (once!)
    window.addEventListener('online', handleOnlineEvent)
    window.addEventListener('offline', handleOfflineEvent)

    // Start periodic heartbeat (15s interval)
    setInterval(runHeartbeat, 15000)

    // Initial heartbeat + summary fetch
    setTimeout(() => {
        runHeartbeat()
        refreshPendingSummary()
    }, 500)
}

async function handleOnlineEvent() {
    console.log('[SyncEngine] Browser online event fired (navigator.onLine=' + navigator.onLine + ')')
    isOnline.value = true
    previousOnlineState = true
    // Small delay to ensure network is truly available
    await new Promise(r => setTimeout(r, 1000))
    await runHeartbeat()

    // ── Auto-sync when connection is restored ──────────────────────────
    // ALWAYS call triggerSync() when going online, regardless of the
    // pending summary count. The server endpoint is cheap and returns
    // quickly if there's nothing to sync. This removes the fragility
    // of relying on refreshPendingSummary() being accurate (it can fail
    // silently due to timing, container resolution, etc.)
    console.log('[SyncEngine] Connection restored — triggerSync immediately')
    await triggerSync()
}

function handleOfflineEvent() {
    console.log('[SyncEngine] Browser offline event')
    isOnline.value = false
}

async function runHeartbeat() {
    const currentState = typeof navigator !== 'undefined' ? navigator.onLine : true
    const stateChanged = currentState !== previousOnlineState

    // ── Detect offline→online transition ──────────────────────────────
    // This catches cases where the browser 'online' event didn't fire
    // (known issue with Android WebViews). The heartbeat detects the
    // state change and triggers the sync engine as a fallback.
    if (currentState && !previousOnlineState) {
        console.log('[SyncEngine] Heartbeat detected offline→online transition (prev=' + previousOnlineState + ' → cur=' + currentState + ')')
        isOnline.value = true
        previousOnlineState = true

        // Give the embedded server a moment to stabilize, then sync
        await refreshPendingSummary()
        console.log('[SyncEngine] Heartbeat auto-syncing after offline→online transition')
        await triggerSync()
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
