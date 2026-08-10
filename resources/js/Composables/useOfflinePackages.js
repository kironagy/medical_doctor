import axios from 'axios'
import { ref, computed } from 'vue'
import { useSyncEngine } from '@/Composables/useSyncEngine'

// Module-scope singleton state, same pattern as useWorkspace.js — shared
// across every component without prop drilling.
const packages = ref([]) // [{patient_uuid, status, downloaded_at, last_refreshed_at, error, patient_name, patient_code}]
const loadingList = ref(false)
const busyPatientUuids = ref(new Set()) // uuids currently downloading/refreshing/deleting

const BASE = '/_native/api/offline-package'

export function useOfflinePackages() {
    const { isOnline } = useSyncEngine()

    function getPackage(patientUuid) {
        return packages.value.find((p) => p.patient_uuid === patientUuid) || null
    }

    function isBusy(patientUuid) {
        return busyPatientUuids.value.has(patientUuid)
    }

    function upsertLocal(pkg) {
        const idx = packages.value.findIndex((p) => p.patient_uuid === pkg.patient_uuid)
        if (idx === -1) {
            packages.value = [pkg, ...packages.value]
        } else {
            packages.value = packages.value.map((p, i) => (i === idx ? pkg : p))
        }
    }

    async function fetchPackages() {
        loadingList.value = true
        try {
            const res = await axios.get(`${BASE}`)
            packages.value = res.data?.data || []
        } catch (e) {
            console.error('[useOfflinePackages] fetchPackages failed', e)
        } finally {
            loadingList.value = false
        }
    }

    async function downloadPackage(patientUuid) {
        if (!isOnline.value) {
            throw new Error('offline')
        }
        busyPatientUuids.value.add(patientUuid)
        try {
            const res = await axios.post(`${BASE}/${patientUuid}`)
            upsertLocal(res.data)
            return res.data
        } finally {
            busyPatientUuids.value.delete(patientUuid)
        }
    }

    async function refreshPackage(patientUuid) {
        if (!isOnline.value) {
            throw new Error('offline')
        }
        busyPatientUuids.value.add(patientUuid)
        try {
            const res = await axios.post(`${BASE}/${patientUuid}/refresh`)
            upsertLocal(res.data)
            return res.data
        } finally {
            busyPatientUuids.value.delete(patientUuid)
        }
    }

    async function deletePackage(patientUuid) {
        busyPatientUuids.value.add(patientUuid)
        try {
            await axios.delete(`${BASE}/${patientUuid}`)
            packages.value = packages.value.filter((p) => p.patient_uuid !== patientUuid)
        } finally {
            busyPatientUuids.value.delete(patientUuid)
        }
    }

    const readyPatientUuids = computed(() =>
        new Set(packages.value.filter((p) => p.status === 'ready').map((p) => p.patient_uuid))
    )

    return {
        packages,
        loadingList,
        readyPatientUuids,
        getPackage,
        isBusy,
        fetchPackages,
        downloadPackage,
        refreshPackage,
        deletePackage,
    }
}
