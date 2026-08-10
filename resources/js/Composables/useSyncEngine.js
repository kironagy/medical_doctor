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
import { isNativeApp } from '../Utils/api'

// ─── Shared reactive state ──────────────────────────────────────────────
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const isSyncing = ref(false)
const lastSyncResult = ref(null)
const pendingSummary = ref({ patients: 0, files: 0, deletes: 0, notes: 0, total: 0 })

const MANUAL_SYNC_ENDPOINT = '/_native/api/sync/manual'
const SYNC_STATE_ENDPOINT = '/_native/api/sync/state'

/**
 * Wait for a backgrounded sync to finish by polling its state.
 *
 * On the device the sync now runs on the queue worker runtime instead of inside
 * the HTTP request, so /manual returns as soon as the job is queued. Polling a
 * small state endpoint keeps the UI's "syncing" indicator honest without
 * holding a request open (which is what used to block every other screen).
 */
async function waitForQueuedSync() {
    const POLL_MS = 2000
    const MAX_MS = 60 * 60 * 1000
    const startedAt = Date.now()

    while (Date.now() - startedAt < MAX_MS) {
        await new Promise(r => setTimeout(r, POLL_MS))

        try {
            const { data } = await axios.get(SYNC_STATE_ENDPOINT, { timeout: 10000 })
            if (!data?.running) {
                return data?.result || { success: true, stats: {} }
            }
        } catch (e) {
            // A failed poll is not a failed sync — keep waiting.
            console.warn('[SyncEngine] sync state poll failed:', e.message)
        }
    }

    return { success: true, stats: {}, message: 'Sync still running in the background' }
}
const PENDING_ENDPOINT = '/_native/api/sync/pending-summary'
const NETWORK_STATUS_ENDPOINT = '/_native/api/sync/network-status'

/**
 * Re-check real connectivity via an actual outbound request instead of
 * trusting navigator.onLine.
 *
 * Why this exists: the 'online'/'offline' DOM events below are not reliable
 * in this WebView — NetworkStateManager.kt hit the identical failure mode on
 * the native side (see its class doc) and fixed it by querying
 * ConnectivityManager fresh on every check instead of caching a value
 * updated by a registered callback, because that callback "did not always
 * fire" after toggling connectivity without restarting the app. The DOM
 * events here are the JS-side equivalent of that same unreliable callback,
 * so isOnline could get stuck offline even once the network was back,
 * blocking uploads/add-patient with a false "internet required" state until
 * the whole app was killed and relaunched. This hits a local endpoint that
 * does a real reachability check against production, called whenever the
 * app becomes visible/focused again — the exact moment a user returns after
 * toggling connectivity — not on a timer (see this file's "NO timers" note).
 */
async function checkRealConnectivity() {
    if (!isNativeApp()) return
    try {
        const { data } = await axios.get(NETWORK_STATUS_ENDPOINT, { timeout: 4000 })
        isOnline.value = !!data?.online
    } catch (e) {
        // The embedded local server itself is unreachable — extremely rare
        // (it's on-device). Leave isOnline as last known rather than guess.
    }
}

// Update online state when browser online/offline events fire, then
// self-correct with a real probe — see checkRealConnectivity() above.
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => { isOnline.value = true })
    window.addEventListener('offline', () => { isOnline.value = false })

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkRealConnectivity()
    })
    window.addEventListener('focus', checkRealConnectivity)
    window.addEventListener('pageshow', checkRealConnectivity)
    checkRealConnectivity()
}

/**
 * Trigger a full manual sync cycle (11-step pipeline).
 * Called strictly when the user clicks "Sync Now".
 */
async function triggerSync() {
    // /_native/* routes only exist in the embedded mobile build — on the
    // website they 404, and previously fired anyway (see refreshPendingSummary
    // below for the same issue at import time).
    if (!isNativeApp()) {
        return { success: false, error: 'Sync is only available in the mobile app' }
    }

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

        // On the device the pipeline is queued onto the worker runtime and this
        // call returns immediately, so follow its progress via the state
        // endpoint instead of assuming it already finished.
        let data = res.data
        if (data?.queued) {
            console.log('[SyncEngine] ⏳ Sync queued on the worker runtime, polling for completion...')
            const finished = await waitForQueuedSync()
            data = { ...data, ...finished }
        }

        console.log('[SyncEngine] ✅ Manual sync finished:', data)

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
    if (!isNativeApp()) return
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
    if (!isNativeApp()) return
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
