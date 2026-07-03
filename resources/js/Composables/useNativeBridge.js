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

function createPermissionDialog({ title, message }) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div')
    overlay.id = 'native-permission-overlay'
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:24px;animation:fadeIn 0.2s ease;'

    const modal = document.createElement('div')
    modal.style.cssText = 'background:#fff;border-radius:16px;padding:24px;max-width:340px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:slideUp 0.25s ease;'
    modal.dir = document.documentElement.dir || 'ltr'

    const icon = document.createElement('div')
    icon.style.cssText = 'width:48px;height:48px;border-radius:50%;background:#f0fdfa;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;'
    icon.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>'

    const titleEl = document.createElement('p')
    titleEl.textContent = title
    titleEl.style.cssText = 'font-size:16px;font-weight:600;color:#1e293b;text-align:center;margin:0 0 8px;'

    const msgEl = document.createElement('p')
    msgEl.textContent = message
    msgEl.style.cssText = 'font-size:14px;color:#64748b;text-align:center;margin:0 0 20px;line-height:1.5;'

    const btnContainer = document.createElement('div')
    btnContainer.style.cssText = 'display:flex;gap:8px;'

    const denyBtn = document.createElement('button')
    denyBtn.textContent = 'Not Now'
    denyBtn.style.cssText = 'flex:1;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#475569;font-size:14px;font-weight:500;cursor:pointer;transition:background 0.15s;'

    const grantBtn = document.createElement('button')
    grantBtn.textContent = 'Allow'
    grantBtn.style.cssText = 'flex:1;padding:10px;border:none;border-radius:10px;background:#0d9488;color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.15s;'

    modal.appendChild(icon)
    modal.appendChild(titleEl)
    modal.appendChild(msgEl)
    modal.appendChild(btnContainer)
    btnContainer.appendChild(denyBtn)
    btnContainer.appendChild(grantBtn)
    overlay.appendChild(modal)
    document.body.appendChild(overlay)

    function cleanup() {
      overlay.remove()
    }

    grantBtn.addEventListener('click', () => { cleanup(); resolve(true) })
    denyBtn.addEventListener('click', () => { cleanup(); resolve(false) })
    grantBtn.addEventListener('touchend', (e) => { e.preventDefault(); cleanup(); resolve(true) })
    denyBtn.addEventListener('touchend', (e) => { e.preventDefault(); cleanup(); resolve(false) })

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) { cleanup(); resolve(false) }
    })
  })
}

function createPermanentlyDeniedDialog({ title, message }) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div')
    overlay.id = 'native-settings-overlay'
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:24px;animation:fadeIn 0.2s ease;'

    const modal = document.createElement('div')
    modal.style.cssText = 'background:#fff;border-radius:16px;padding:24px;max-width:340px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:slideUp 0.25s ease;'
    modal.dir = document.documentElement.dir || 'ltr'

    const icon = document.createElement('div')
    icon.style.cssText = 'width:48px;height:48px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;'
    icon.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>'

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

    modal.appendChild(icon)
    modal.appendChild(titleEl)
    modal.appendChild(msgEl)
    modal.appendChild(btnContainer)
    btnContainer.appendChild(cancelBtn)
    btnContainer.appendChild(settingsBtn)
    overlay.appendChild(modal)
    document.body.appendChild(overlay)

    function cleanup() {
      overlay.remove()
    }

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
    if (!isNativePHP()) return PERMISSION_GRANTED

    const cached = permissionCache[permission]
    if (cached === PERMISSION_PERMANENTLY_DENIED) {
      const label = getLabel(permission)
      await createPermanentlyDeniedDialog({
        title: `${label.title} is Blocked`,
        message: `${label.message} You previously denied this permission. Please enable it in your device settings to continue.`,
      })
      return PERMISSION_PERMANENTLY_DENIED
    }

    const label = getLabel(permission)
    const allowed = await createPermissionDialog({
      title: label.title,
      message: label.message,
    })
    if (!allowed) {
      return PERMISSION_DENIED
    }

    if (!window.native?.permissions?.request) {
      permissionCache[permission] = PERMISSION_GRANTED
      return PERMISSION_GRANTED
    }

    try {
      const result = await window.native.permissions.request(permission)
      if (result === 'granted') {
        permissionCache[permission] = PERMISSION_GRANTED
        return PERMISSION_GRANTED
      }
      if (result === 'permanently_denied') {
        permissionCache[permission] = PERMISSION_PERMANENTLY_DENIED
        await createPermanentlyDeniedDialog({
          title: `${label.title} is Blocked`,
          message: `${label.message} You previously denied this permission. Please enable it in your device settings to continue.`,
        })
        return PERMISSION_PERMANENTLY_DENIED
      }
      permissionCache[permission] = PERMISSION_DENIED
      return PERMISSION_DENIED
    } catch (e) {
      console.warn('[Native] Permission request failed:', e)
      permissionCache[permission] = PERMISSION_DENIED
      return PERMISSION_DENIED
    }
  }

  async function pickFiles(options = {}) {
    const { multiple = true, accept = '*/*' } = options

    if (!isNativePHP()) return null

    const perm = await requestPermission('files')
    if (perm !== PERMISSION_GRANTED) return []

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

    const perm = await requestPermission('camera')
    if (perm !== PERMISSION_GRANTED) return null

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

    const perm = await requestPermission('camera')
    if (perm !== PERMISSION_GRANTED) return null

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
    PERMISSION_DENIED,
    PERMISSION_GRANTED,
    PERMISSION_PERMANENTLY_DENIED,
  }
}
