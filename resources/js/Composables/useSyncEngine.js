// ═════════════════════════════════════════════════════════════════════════
// useSyncEngine — Offline-First Manual Synchronization Engine
// ═════════════════════════════════════════════════════════════════════════
//
// Architecture:
//   SQLite is the SOLE LOCAL SOURCE OF TRUTH.
//   Synchronization happens ONLY when the user explicitly presses "Sync Now".
//
// Features:
//   ✔ NO automatic synchronization
//   ✔ NO timers / NO setInterval
//   ✔ NO heartbeat polling
//   ✔ NO hidden background sync
//   ✔ Strictly manual trigger via triggerSync()
//   ✔ Reports step-by-step pipeline results to the UI
// ═════════════════════════════════════════════════════════════════════════

import axios from 'axios'
import { ref, computed } from 'vue'
import { useWorkspace } from './useWorkspace'

// ─── Shared reactive state ──────────────────────────────────────────────
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const isSyncing = ref(false)
const lastSyncResult = ref(null)
const pendingSummary = ref({ patients: 0, files: 0, deletes: 0, notes: 0, total: 0 })

const MANUAL_SYNC_ENDPOINT = '/_native/api/sync/manual'
const PENDING_ENDPOINT = '/_native/api/sync/pending-summary'

// Update online state when browser online/offline events fire (purely for UI status icon)
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => { isOnline.value = true })
    window.addEventListener('offline', () => { isOnline.value = false })
}

/**
 * Trigger a full manual sync cycle (11-step pipeline).
 * Called strictly when the user clicks "Sync Now".
 */
async function triggerSync() {
    if (isSyncing.value) {
        console.log('[SyncEngine] ⚠ Sync already in progress, skipping duplicate trigger')
        return lastSyncResult.value
    }

    isSyncing.value = true
    console.log('[SyncEngine] 🚀 Starting explicit manual sync...')

    const syncToken = typeof localStorage !== 'undefined'
        ? localStorage.getItem('np_api_token')
        : null
    const syncHeaders = syncToken ? { Authorization: 'Bearer ' + syncToken } : {}

    try {
        const res = await axios.post(MANUAL_SYNC_ENDPOINT, {}, {
            headers: syncHeaders,
            timeout: 300000, // 5 minutes for full manual pipeline
        })

        console.log('[SyncEngine] ✅ Manual sync finished:', res.data)
        const data = res.data

        lastSyncResult.value = {
            success: data.success ?? true,
            stats: data.stats || {},
            timestamp: new Date().toISOString(),
            message: data.message || 'Sync completed successfully',
        }

        // Refresh pending summary and patient workspace list
        await refreshPendingSummary()
        const { refreshPatientList } = useWorkspace()
        await refreshPatientList()

        return lastSyncResult.value
    } catch (err) {
        console.error('[SyncEngine] ❌ Manual sync failed:', err.message)
        lastSyncResult.value = {
            success: false,
            error: err.response?.data?.message || err.message || 'Sync failed',
            timestamp: new Date().toISOString(),
        }
        return lastSyncResult.value
    } finally {
        isSyncing.value = false
    }
}

/**
 * Pull server changes (patients: new/updated/deleted) into local SQLite —
 * same endpoint the app-boot hydration uses (BootstrapController::refreshCache
 * -> DownloadSyncService::cacheFirstPage() + deltaSyncPatients()). Unlike the
 * boot-time hydration this is NOT gated to "once per launch": it's meant to
 * be called explicitly on a manual user action (pull-to-refresh), consistent
 * with this file's "strictly manual trigger" design. Silent no-op if offline
 * or no token — never touches local data on failure.
 */
async function refreshFromServer() {
    if (typeof navigator !== 'undefined' && !navigator.onLine) return
    const apiToken = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null
    if (!apiToken) return

    try {
        await axios.post('/_native/api/bootstrap/refresh', {}, {
            headers: { Authorization: 'Bearer ' + apiToken },
            timeout: 15000,
        })
    } catch (e) {
        console.warn('[SyncEngine] refreshFromServer failed (non-fatal):', e.message)
    }
}

/**
 * Refresh the summary of pending SQLite operations.
 */
async function refreshPendingSummary() {
    try {
        const res = await axios.get(PENDING_ENDPOINT, { timeout: 5000 })
        pendingSummary.value = res.data || { patients: 0, files: 0, deletes: 0, notes: 0, total: 0 }
    } catch (e) {
        console.warn('[SyncEngine] Failed to refresh pending summary:', e.message)
    }
}

const hasPendingOperations = computed(() => {
    return pendingSummary.value.total > 0
})

const pendingSummaryText = computed(() => {
    const s = pendingSummary.value
    const parts = []
    if (s.patients > 0) parts.push(`${s.patients} patient${s.patients !== 1 ? 's' : ''}`)
    if (s.files > 0) parts.push(`${s.files} file${s.files !== 1 ? 's' : ''}`)
    if (s.notes > 0) parts.push(`${s.notes} note${s.notes !== 1 ? 's' : ''}`)
    if (s.deletes > 0) parts.push(`${s.deletes} delete${s.deletes !== 1 ? 's' : ''}`)
    if (parts.length === 0) return 'All synced'
    return `${parts.join(', ')} pending`
})

// Initial summary fetch on import
refreshPendingSummary()

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
        refreshFromServer,
    }
}
