// ═══════════════════════════════════════════════════════════════════
// NativeBridge — Android runtime permission & native feature bridge
// ═══════════════════════════════════════════════════════════════════
//
// Architecture:
//   Android side:   MainActivity.NativePermissionJSBridge registered as "NativePHP"
//   JS side:        window.NativePHP.checkPermission(perm) → sync
//                   window.NativePHP.requestPermission(perm, cbId) → async (callback)
//                   window.NativePHP.openAppSettings()
//
// Permission string mapping:
//   JS alias       → Android permission string
//   'camera'       → android.permission.CAMERA
//   'files'        → android.permission.READ_MEDIA_IMAGES (Android 13+)
//   'audio'        → android.permission.RECORD_AUDIO
//   'notifications'→ android.permission.POST_NOTIFICATIONS
//   'location'     → android.permission.ACCESS_FINE_LOCATION
//   'storage'      → android.permission.READ_MEDIA_IMAGES (Android 13+)

const ANDROID_PERMISSION_MAP = {
  camera: 'android.permission.CAMERA',
  files: 'android.permission.READ_MEDIA_IMAGES',
  audio: 'android.permission.RECORD_AUDIO',
  notifications: 'android.permission.POST_NOTIFICATIONS',
  location: 'android.permission.ACCESS_FINE_LOCATION',
  storage: 'android.permission.READ_MEDIA_IMAGES',
}

const PERMISSION_GRANTED = 'granted'
const PERMISSION_DENIED = 'denied'
const PERMISSION_PERMANENTLY_DENIED = 'permanently_denied'

const PERMISSION_LABELS = {
  camera: {
    title: 'Camera Access',
    message: 'This app needs camera access to take photos of medical documents and records.',
    settingsTitle: 'Camera Access is Blocked',
    settingsMessage: 'Please enable Camera permission in Settings to take photos.',
  },
  files: {
    title: 'Photo & Media Access',
    message: 'This app needs access to your photos and files to attach medical documents.',
    settingsTitle: 'Media Access is Blocked',
    settingsMessage: 'Please enable Media permission in Settings to attach files.',
  },
  audio: {
    title: 'Microphone Access',
    message: 'This app needs microphone access to record audio with videos.',
    settingsTitle: 'Microphone Access is Blocked',
    settingsMessage: 'Please enable Microphone permission in Settings to record audio.',
  },
  notifications: {
    title: 'Notifications',
    message: 'Enable notifications to receive updates about your patients.',
    settingsTitle: 'Notifications are Blocked',
    settingsMessage: 'Please enable Notifications in Settings to receive updates.',
  },
  storage: {
    title: 'Storage Access',
    message: 'This app needs storage access to save and share medical files.',
    settingsTitle: 'Storage Access is Blocked',
    settingsMessage: 'Please enable Storage permission in Settings.',
  },
}

// Track granted permissions across page navigations (module-level, survives Inertia nav)
const grantedCache = {}

let callbackCounter = 0

function mapPermission(alias) {
  return ANDROID_PERMISSION_MAP[alias] || alias
}

function getLabel(alias) {
  return PERMISSION_LABELS[alias] || {
    title: 'Permission Required',
    message: `This action requires "${alias}" permission.`,
    settingsTitle: 'Permission Blocked',
    settingsMessage: `Please enable "${alias}" in Settings.`,
  }
}

function hasNativeBridge() {
  return typeof window !== 'undefined' && !!window.NativePHP
}

function isNativeAndroid() {
  return typeof navigator !== 'undefined' && navigator.userAgent.includes('Android')
}

// ── UI helpers ─────────────────────────────────────────────────────
function createPermissionDialog({ title, message, confirmText = 'Allow', denyText = 'Not Now' }) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div')
    overlay.id = 'native-permission-overlay'
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:24px;'

    const modal = document.createElement('div')
    modal.style.cssText = 'background:#fff;border-radius:16px;padding:24px;max-width:340px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);font-family:-apple-system,BlinkMacSystemFont,sans-serif;'
    modal.dir = document.documentElement.dir || 'ltr'

    const titleEl = document.createElement('p')
    titleEl.textContent = title
    titleEl.style.cssText = 'font-size:16px;font-weight:600;color:#1e293b;text-align:center;margin:0 0 8px;'

    const msgEl = document.createElement('p')
    msgEl.textContent = message
    msgEl.style.cssText = 'font-size:14px;color:#64748b;text-align:center;margin:0 0 20px;line-height:1.5;'

    const btnContainer = document.createElement('div')
    btnContainer.style.cssText = 'display:flex;gap:8px;'

    const denyBtn = document.createElement('button')
    denyBtn.textContent = denyText
    denyBtn.style.cssText = 'flex:1;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#475569;font-size:14px;font-weight:500;cursor:pointer;-webkit-appearance:none;'

    const grantBtn = document.createElement('button')
    grantBtn.textContent = confirmText
    grantBtn.style.cssText = 'flex:1;padding:10px;border:none;border-radius:10px;background:#0d9488;color:#fff;font-size:14px;font-weight:600;cursor:pointer;-webkit-appearance:none;'

    modal.appendChild(titleEl)
    modal.appendChild(msgEl)
    modal.appendChild(btnContainer)
    btnContainer.appendChild(denyBtn)
    btnContainer.appendChild(grantBtn)
    overlay.appendChild(modal)
    document.body.appendChild(overlay)

    function cleanup() { overlay.remove() }
    const handleGrant = () => { cleanup(); resolve(true) }
    const handleDeny = () => { cleanup(); resolve(false) }

    grantBtn.addEventListener('click', handleGrant)
    denyBtn.addEventListener('click', handleDeny)
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) { cleanup(); resolve(false) }
    })
  })
}

function createPermanentlyDeniedDialog({ title, message }) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div')
    overlay.id = 'native-settings-overlay'
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:24px;'

    const modal = document.createElement('div')
    modal.style.cssText = 'background:#fff;border-radius:16px;padding:24px;max-width:340px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);font-family:-apple-system,BlinkMacSystemFont,sans-serif;'
    modal.dir = document.documentElement.dir || 'ltr'

    const titleEl = document.createElement('p')
    titleEl.textContent = title
    titleEl.style.cssText = 'font-size:16px;font-weight:600;color:#1e293b;text-align:center;margin:0 0 8px;'

    const msgEl = document.createElement('p')
    msgEl.textContent = message
    msgEl.style.cssText = 'font-size:14px;color:#64748b;text-align:center;margin:0 0 20px;line-height:1.5;'

    const btnContainer = document.createElement('div')
    btnContainer.style.cssText = 'display:flex;gap:8px;'

    const cancelBtn = document.createElement('button')
    cancelBtn.textContent = 'Cancel'
    cancelBtn.style.cssText = 'flex:1;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#475569;font-size:14px;font-weight:500;cursor:pointer;-webkit-appearance:none;'

    const settingsBtn = document.createElement('button')
    settingsBtn.textContent = 'Open Settings'
    settingsBtn.style.cssText = 'flex:1;padding:10px;border:none;border-radius:10px;background:#0d9488;color:#fff;font-size:14px;font-weight:600;cursor:pointer;-webkit-appearance:none;'

    modal.appendChild(titleEl)
    modal.appendChild(msgEl)
    modal.appendChild(btnContainer)
    btnContainer.appendChild(cancelBtn)
    btnContainer.appendChild(settingsBtn)
    overlay.appendChild(modal)
    document.body.appendChild(overlay)

    function cleanup() { overlay.remove() }

    cancelBtn.addEventListener('click', () => { cleanup(); resolve(false) })
    settingsBtn.addEventListener('click', () => {
      cleanup()
      if (window.NativePHP?.openAppSettings) {
        try { window.NativePHP.openAppSettings() } catch (e) {}
      }
      resolve(false)
    })
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) { cleanup(); resolve(false) }
    })
  })
}

// ── Permission request with native bridge ─────────────────────────
function requestNativePermission(androidPerm) {
  return new Promise((resolve) => {
    const cbId = 'perm_' + (++callbackCounter) + '_' + Date.now()

    if (!window.__nphpPermCallbacks) {
      window.__nphpPermCallbacks = {}
    }
    window.__nphpPermCallbacks[cbId] = (result) => {
      delete window.__nphpPermCallbacks[cbId]
      resolve(result)
    }

    // Timeout safeguard: resolve as denied if no response within 30s
    const timeout = setTimeout(() => {
      delete window.__nphpPermCallbacks[cbId]
      resolve(PERMISSION_DENIED)
    }, 30000)

    // Wrap resolve to clear timeout
    const originalResolve = window.__nphpPermCallbacks[cbId]
    window.__nphpPermCallbacks[cbId] = (result) => {
      clearTimeout(timeout)
      originalResolve(result)
    }

    try {
      window.NativePHP.requestPermission(androidPerm, cbId)
    } catch (e) {
      clearTimeout(timeout)
      delete window.__nphpPermCallbacks[cbId]
      resolve(PERMISSION_DENIED)
    }
  })
}

// ── Exported composable ────────────────────────────────────────────
export function useNativeBridge() {
  function detectNative() {
    return hasNativeBridge() || isNativeAndroid()
  }

  function detectPlatform() {
    if (isNativeAndroid()) return 'android'
    return 'web'
  }

  function isCameraAvailable() {
    return !!window.native?.camera?.takePhoto
  }

  function isFilePickerAvailable() {
    return !!window.native?.files?.pick
  }

  function isPermissionsApiAvailable() {
    return !!window.NativePHP?.checkPermission
  }

  async function requestPermission(alias) {
    const androidPerm = mapPermission(alias)

    // 1. Check if already granted (cache)
    if (grantedCache[alias] === PERMISSION_GRANTED) {
      return PERMISSION_GRANTED
    }

    // 2. Check current status via native bridge
    if (window.NativePHP?.checkPermission) {
      try {
        const status = window.NativePHP.checkPermission(androidPerm)
        if (status === PERMISSION_GRANTED) {
          grantedCache[alias] = PERMISSION_GRANTED
          return PERMISSION_GRANTED
        }
        if (status === PERMISSION_PERMANENTLY_DENIED) {
          grantedCache[alias] = PERMISSION_PERMANENTLY_DENIED
          const label = getLabel(alias)
          await createPermanentlyDeniedDialog({
            title: label.settingsTitle,
            message: label.settingsMessage,
          })
          return PERMISSION_PERMANENTLY_DENIED
        }
      } catch (e) {
        // Bridge available but check failed — proceed to request
      }
    }

    // 3. Show rationale dialog before native system dialog
    const label = getLabel(alias)
    const allowed = await createPermissionDialog({
      title: label.title,
      message: label.message,
    })
    if (!allowed) {
      return PERMISSION_DENIED
    }

    // 4. Request permission via native bridge
    if (window.NativePHP?.requestPermission) {
      try {
        const result = await requestNativePermission(androidPerm)
        grantedCache[alias] = result
        if (result === PERMISSION_PERMANENTLY_DENIED) {
          await createPermanentlyDeniedDialog({
            title: label.settingsTitle,
            message: label.settingsMessage,
          })
        }
        return result
      } catch (e) {
        grantedCache[alias] = PERMISSION_DENIED
        return PERMISSION_DENIED
      }
    }

    // 5. No native bridge — assume granted (fallback)
    return PERMISSION_GRANTED
  }

  async function pickFiles(options = {}) {
    const { multiple = true, accept = '*/*' } = options

    if (!isFilePickerAvailable()) {
      return null
    }

    const perm = await requestPermission('files')
    if (perm !== PERMISSION_GRANTED) {
      return []
    }

    try {
      const result = await window.native?.files?.pick?.({ multiple, accept })
      return Array.isArray(result) ? result : result ? [result] : []
    } catch (e) {
      return []
    }
  }

  async function takePhoto() {
    if (!isCameraAvailable()) {
      return null
    }

    const perm = await requestPermission('camera')
    if (perm !== PERMISSION_GRANTED) {
      return null
    }

    try {
      const result = await window.native?.camera?.takePhoto?.()
      return result
    } catch (e) {
      return null
    }
  }

  async function recordVideo() {
    if (!window.NativePHP?.checkPermission) return null

    const camPerm = await requestPermission('camera')
    if (camPerm !== PERMISSION_GRANTED) return null

    const audioPerm = await requestPermission('audio')
    if (audioPerm !== PERMISSION_GRANTED) return null

    try {
      return await window.native?.camera?.recordVideo?.()
    } catch (e) {
      return null
    }
  }

  async function requestNotificationPermission() {
    return requestPermission('notifications')
  }

  function openSettings() {
    if (window.NativePHP?.openAppSettings) {
      try { window.NativePHP.openAppSettings() } catch (e) {}
    }
  }

  return {
    detectNative,
    detectPlatform,
    isCameraAvailable,
    isFilePickerAvailable,
    isPermissionsApiAvailable,
    requestPermission,
    pickFiles,
    takePhoto,
    recordVideo,
    requestNotificationPermission,
    openSettings,
    PERMISSION_DENIED,
    PERMISSION_GRANTED,
    PERMISSION_PERMANENTLY_DENIED,
  }
}