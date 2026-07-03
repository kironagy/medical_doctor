import { useNativeBridge } from './useNativeBridge'

const permissionState = {
  camera: { status: 'unknown', permanentlyDenied: false },
  storage: { status: 'unknown', permanentlyDenied: false },
}

export function usePermissions() {
  const { detectNative, requestPermission, openSettings } = useNativeBridge()

  function isNative() {
    return detectNative()
  }

  async function ensure(permission) {
    if (!isNative()) return true

    const state = permissionState[permission]
    if (!state) return true

    // If already granted, return immediately
    if (state.status === 'granted') return true

    // If permanently denied, we need settings
    if (state.permanentlyDenied) {
      return false
    }

    // Request the permission
    const result = await requestPermission(permission)

    if (result === 'granted') {
      state.status = 'granted'
      return true
    }

    if (result === 'denied') {
      state.status = 'denied'
      return false
    }

    state.status = 'denied'
    return false
  }

  function isPermanentlyDenied(permission) {
    const state = permissionState[permission]
    return state?.permanentlyDenied ?? false
  }

  function markPermanentlyDenied(permission) {
    if (permissionState[permission]) {
      permissionState[permission].permanentlyDenied = true
    }
  }

  function getStatus(permission) {
    const state = permissionState[permission]
    return state?.status ?? 'unknown'
  }

  return {
    ensure,
    isPermanentlyDenied,
    markPermanentlyDenied,
    getStatus,
    isNative,
  }
}
