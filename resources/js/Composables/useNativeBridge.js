const isNativePHP = () => {
  const detected = typeof window !== 'undefined' && (
    window.native ||
    navigator.userAgent.includes('NativePHP') ||
    navigator.userAgent.includes('nativephp')
  )
  if (detected) console.log('[NativeBridge] NativePHP detected')
  return detected
}

const isNativeAndroid = () => {
  const detected = typeof navigator !== 'undefined' && navigator.userAgent.includes('Android')
  return detected
}

const PERMISSION_DENIED = 'denied'
const PERMISSION_GRANTED = 'granted'
const PERMISSION_PERMANENTLY_DENIED = 'permanently_denied'

const permissionCache = {}

const PERMISSION_LABELS = {
  camera: {
    title: 'Camera Access',
    message: 'This app needs camera access to take photos of medical documents and records.',
  },
  gallery: {
    title: 'Photo Library Access',
    message: 'This app needs access to your photo library to attach medical images and documents.',
  },
  files: {
    title: 'File Access',
    message: 'This app needs file access to upload medical documents and records.',
  },
  storage: {
    title: 'Storage Access',
    message: 'This app needs storage access to save and share medical files.',
  },
}

function getLabel(permission) {
  const key = permission.toLowerCase()
  if (key.includes('camera')) return PERMISSION_LABELS.camera
  if (key.includes('gallery') || key.includes('photo')) return PERMISSION_LABELS.gallery
  if (key.includes('file')) return PERMISSION_LABELS.files
  if (key.includes('storage')) return PERMISSION_LABELS.storage
  return { title: 'Permission Required', message: `This action requires "${permission}" permission.` }
}

function createPermissionDialog({ title, message, confirmText = 'Allow', denyText = 'Not Now' }) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div')
    overlay.id = 'native-permission-overlay'
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:24px;'

    const modal = document.createElement('div')
    modal.style.cssText = 'background:#fff;border-radius:16px;padding:24px;max-width:340px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);'
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
    denyBtn.style.cssText = 'flex:1;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#475569;font-size:14px;font-weight:500;cursor:pointer;'

    const grantBtn = document.createElement('button')
    grantBtn.textContent = confirmText
    grantBtn.style.cssText = 'flex:1;padding:10px;border:none;border-radius:10px;background:#0d9488;color:#fff;font-size:14px;font-weight:600;cursor:pointer;'

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
    modal.style.cssText = 'background:#fff;border-radius:16px;padding:24px;max-width:340px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);'
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
    cancelBtn.style.cssText = 'flex:1;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#475569;font-size:14px;font-weight:500;cursor:pointer;'

    const settingsBtn = document.createElement('button')
    settingsBtn.textContent = 'Open Settings'
    settingsBtn.style.cssText = 'flex:1;padding:10px;border:none;border-radius:10px;background:#0d9488;color:#fff;font-size:14px;font-weight:600;cursor:pointer;'

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
      if (window.native?.settings?.open) {
        window.native.settings.open()
      }
      resolve(false)
    })
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) { cleanup(); resolve(false) }
    })
  })
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
    console.log(`[NativeBridge] requestPermission called: "${permission}"`)

    if (!isNativePHP()) {
      console.log('[NativeBridge] Not in NativePHP - skipping permission request')
      return PERMISSION_GRANTED
    }

    const cached = permissionCache[permission]
    if (cached === PERMISSION_PERMANENTLY_DENIED) {
      console.log('[NativeBridge] Permission was permanently denied - showing settings dialog')
      const label = getLabel(permission)
      await createPermanentlyDeniedDialog({
        title: `${label.title} is Blocked`,
        message: `${label.message} Please enable this permission in your device settings.`,
      })
      return PERMISSION_PERMANENTLY_DENIED
    }

    // Show explanation dialog first
    const label = getLabel(permission)
    console.log('[NativeBridge] Showing permission explanation dialog')
    const allowed = await createPermissionDialog({
      title: label.title,
      message: label.message,
    })
    if (!allowed) {
      console.log('[NativeBridge] User denied permission explanation')
      return PERMISSION_DENIED
    }

    // Check if native permission API exists
    if (window.native?.permissions?.request) {
      console.log('[NativeBridge] Calling window.native.permissions.request()')
      try {
        const result = await window.native.permissions.request(permission)
        console.log(`[NativeBridge] Permission result: "${result}"`)

        if (result === 'granted') {
          permissionCache[permission] = PERMISSION_GRANTED
          return PERMISSION_GRANTED
        }
        if (result === 'permanently_denied') {
          permissionCache[permission] = PERMISSION_PERMANENTLY_DENIED
          await createPermanentlyDeniedDialog({
            title: `${label.title} is Blocked`,
            message: `${label.message} Please enable this permission in your device settings.`,
          })
          return PERMISSION_PERMANENTLY_DENIED
        }
        // denied
        permissionCache[permission] = PERMISSION_DENIED
        return PERMISSION_DENIED
      } catch (e) {
        console.error('[NativeBridge] Permission request threw exception:', e)
        permissionCache[permission] = PERMISSION_DENIED
        return PERMISSION_DENIED
      }
    } else {
      console.warn('[NativeBridge] window.native.permissions.request is NOT available')
      return PERMISSION_GRANTED
    }
  }

  async function pickFiles(options = {}) {
    const { multiple = true, accept = '*/*' } = options

    if (!isNativePHP()) {
      console.log('[NativeBridge] pickFiles: Not NativePHP - returning null for web fallback')
      return null
    }

    const perm = await requestPermission('files')
    if (perm !== PERMISSION_GRANTED) {
      console.log('[NativeBridge] pickFiles: Permission not granted')
      return []
    }

    if (window.native?.files?.pick) {
      console.log('[NativeBridge] Calling window.native.files.pick()')
      try {
        const result = await window.native.files.pick({ multiple, accept })
        console.log('[NativeBridge] File pick result:', result ? `${result.length} files` : 'null')
        return Array.isArray(result) ? result : result ? [result] : []
      } catch (e) {
        console.error('[NativeBridge] File pick failed:', e)
        return []
      }
    } else {
      console.warn('[NativeBridge] window.native.files.pick is NOT available')
      return null
    }
  }

  async function takePhoto() {
    if (!isNativePHP()) {
      console.log('[NativeBridge] takePhoto: Not NativePHP - returning null for web fallback')
      return null
    }

    const perm = await requestPermission('camera')
    if (perm !== PERMISSION_GRANTED) {
      console.log('[NativeBridge] takePhoto: Camera permission not granted')
      return null
    }

    if (window.native?.camera?.takePhoto) {
      console.log('[NativeBridge] Calling window.native.camera.takePhoto()')
      try {
        const result = await window.native.camera.takePhoto()
        console.log('[NativeBridge] Photo taken:', result ? 'success' : 'null')
        return result
      } catch (e) {
        console.error('[NativeBridge] Camera failed:', e)
        return null
      }
    } else {
      console.warn('[NativeBridge] window.native.camera.takePhoto is NOT available')
      return null
    }
  }

  async function recordVideo() {
    if (!isNativePHP()) {
      console.log('[NativeBridge] recordVideo: Not NativePHP - returning null')
      return null
    }

    const perm = await requestPermission('camera')
    if (perm !== PERMISSION_GRANTED) return null

    if (window.native?.camera?.recordVideo) {
      try {
        return await window.native.camera.recordVideo()
      } catch (e) {
        console.error('[NativeBridge] Video recording failed:', e)
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
    PERMISSION_DENIED,
    PERMISSION_GRANTED,
    PERMISSION_PERMANENTLY_DENIED,
  }
}
