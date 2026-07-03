const isNativePHP = () => {
  return typeof window !== 'undefined' && (
    window.native ||
    navigator.userAgent.includes('NativePHP') ||
    navigator.userAgent.includes('nativephp')
  )
}

const isNativeAndroid = () => {
  return typeof navigator !== 'undefined' && navigator.userAgent.includes('Android')
}

export function useNativeBridge() {
  function detectNative() {
    return isNativePHP()
  }

  function detectPlatform() {
    if (isNativeAndroid()) return 'android'
    return 'web'
  }

  async function requestPermission(permission) {
    if (!isNativePHP()) return true

    if (window.native?.permissions?.request) {
      try {
        const result = await window.native.permissions.request(permission)
        return result === 'granted'
      } catch (e) {
        console.warn('[Native] Permission request failed:', e)
        return false
      }
    }

    return true
  }

  async function pickFiles(options = {}) {
    const { multiple = true, accept = '*/*' } = options

    if (!isNativePHP()) {
      return null
    }

    if (window.native?.files?.pick) {
      try {
        const result = await window.native.files.pick({ multiple, accept })
        return Array.isArray(result) ? result : result ? [result] : []
      } catch (e) {
        console.warn('[Native] File pick failed:', e)
        return []
      }
    }

    return null
  }

  async function takePhoto() {
    if (!isNativePHP()) return null

    if (window.native?.camera?.takePhoto) {
      try {
        return await window.native.camera.takePhoto()
      } catch (e) {
        console.warn('[Native] Camera failed:', e)
        return null
      }
    }

    return null
  }

  async function recordVideo() {
    if (!isNativePHP()) return null

    if (window.native?.camera?.recordVideo) {
      try {
        return await window.native.camera.recordVideo()
      } catch (e) {
        console.warn('[Native] Video recording failed:', e)
        return null
      }
    }

    return null
  }

  function openSettings() {
    if (isNativePHP() && window.native?.settings?.open) {
      window.native.settings.open()
    }
  }

  return {
    detectNative,
    detectPlatform,
    requestPermission,
    pickFiles,
    takePhoto,
    recordVideo,
    openSettings,
  }
}
