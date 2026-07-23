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

const SYNC_ENDPOINT = '/_native/api/sync/engine'
const PENDING_ENDPOINT = '/_native/api/sync/pending-summary'

// ─── Singleton Initialisation (runs ONCE when module is first imported) ──
let initialised = false

function initialise() {
    if (initialised) return
    initialised = true

    if (typeof window === 'undefined') return

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
    console.log('[SyncEngine] Browser online event')
    isOnline.value = true
    // Small delay to ensure network is truly available
    await new Promise(r => setTimeout(r, 1000))
    await runHeartbeat()

    // Auto-sync when connection is restored
    const summary = pendingSummary.value
    if (summary.total > 0) {
        console.log('[SyncEngine] Connection restored, auto-syncing', summary.total, 'pending operations')
        await triggerSync()
    }
}

function handleOfflineEvent() {
    console.log('[SyncEngine] Browser offline event')
    isOnline.value = false
}

async function runHeartbeat() {
    // navigator.onLine is the authoritative source for connectivity.
    // The local Laravel embedded server is always reachable, so pinging
    // a local endpoint would never detect remote server issues.
    // The real connectivity check happens in the server-side SyncEngineService
    // when it tries to reach the remote API.
    isOnline.value = typeof navigator !== 'undefined' ? navigator.onLine : true

    if (isOnline.value) {
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
        console.log('[SyncEngine] Already syncing, skipping duplicate trigger')
        return lastSyncResult.value
    }

    isSyncing.value = true
    console.log('[SyncEngine] Starting sync cycle...')

    try {
        const res = await axios.post(SYNC_ENDPOINT, {}, { timeout: 120000 })
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

        console.log('[SyncEngine] Sync complete:', lastSyncResult.value)

        // Refresh the pending summary
        await refreshPendingSummary()

        // If sync included patients or files, refresh the patient list
        if (data.results?.patients > 0 || data.results?.files?.uploaded > 0) {
            const { refreshPatientList } = useWorkspace()
            await refreshPatientList()
        }

        return lastSyncResult.value
    } catch (err) {
        lastSyncResult.value = {
            success: false,
            error: err.message || 'Sync request failed',
            timestamp: new Date().toISOString(),
        }
        console.error('[SyncEngine] Sync failed:', err.message)
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
        const res = await axios.get(PENDING_ENDPOINT, { timeout: 5000 })
        pendingSummary.value = res.data || { patients: 0, files: 0, deletes: 0, total: 0 }
    } catch {
        // Silently fail — endpoint may not be available during initial load
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
