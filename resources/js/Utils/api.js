/**
 * API Utility — NativePHP (mobile app) vs Website detection
 *
 * On the mobile app (NativePHP Android), API calls go to /api/v1/mobile/
 * with a Bearer token from localStorage. On the website, calls go to
 * /api/v1/ (via web.php routes) which use Laravel session auth.
 */

/**
 * Detect whether the app is running inside NativePHP (Android mobile app).
 * window.NativePHP is set by the Android WebView bridge — not present in
 * a regular browser.
 */
export function isNativeApp() {
  return typeof window !== 'undefined' && !!window.NativePHP
}

/**
 * Get the correct API URL prefix based on the runtime environment.
 *
 * - Website (browser):   /api/v1/...
 * - Mobile (NativePHP):  /api/v1/mobile/...
 */
export function getApiPrefix() {
  return isNativeApp() ? '/api/v1/mobile' : '/api/v1'
}

/**
 * Return the full API URL by replacing /api/v1/mobile/... with the
 * correct prefix for the current environment.
 *
 * Accepts any URL string and normalizes it.
 *
 * Examples:
 *   apiUrl('/api/v1/mobile/patients/uuid/notes')
 *   → On website:  '/api/v1/patients/uuid/notes'
 *   → On mobile:   '/api/v1/mobile/patients/uuid/notes'
 *
 *   apiUrl('/api/v1/mobile/patients/uuid/visits')
 *   → On website:  '/api/v1/patients/uuid/visits'
 *   → On mobile:   '/api/v1/mobile/patients/uuid/visits'
 */
export function apiUrl(path) {
  path = path.startsWith('/') ? path : '/' + path
  const prefix = getApiPrefix()
  // If the path already starts with the target prefix, return as-is
  if (path.startsWith(prefix)) return path
  // Replace /api/v1/mobile with the correct prefix
  if (path.startsWith('/api/v1/mobile')) {
    return prefix + path.substring('/api/v1/mobile'.length)
  }
  // Replace /api/v1 with the correct prefix
  if (path.startsWith('/api/v1')) {
    return prefix + path.substring('/api/v1'.length)
  }
  return prefix + path
}

/**
 * Get the axios config object for the current environment.
 *
 * - Mobile app:  includes Authorization header with Bearer token
 * - Website:     empty object (session auth is handled by cookies)
 */
export function getApiConfig() {
  if (!isNativeApp()) return {}
  const token = localStorage.getItem('np_api_token')
  return token ? { headers: { Authorization: 'Bearer ' + token } } : {}
}

/**
 * Absolute origin of the embedded PHP engine. On native the WebView's page
 * origin can be the production domain (pulled there while online — see
 * MainActivity REMOTE_SERVER load), so a bare relative URL like '/api/v1/...'
 * would resolve AGAINST PRODUCTION instead of the local engine. Every local
 * write/read must use this absolute origin, never a relative path.
 */
export const LOCAL_ORIGIN = 'http://127.0.0.1'

/**
 * Build an absolute URL to the local embedded engine. On the website this
 * just returns the path unchanged (relative, same-origin is already local).
 */
export function localApiUrl(path) {
  path = path.startsWith('/') ? path : '/' + path
  return isNativeApp() ? LOCAL_ORIGIN + path : path
}

/**
 * Install a request interceptor on the given axios instance that pins every
 * relative request to the local engine's absolute origin on native, and
 * blocks (fails loudly, does not silently send) any request whose URL is
 * already absolute and points somewhere other than the local engine or a
 * data:/blob: URL. The ONLY code path allowed to reach production is
 * RemoteApiService on the PHP side (Settings → Manual Sync); nothing
 * initiated from WebView JS should ever leave the device.
 */
export function guardLocalOrigin(axiosInstance) {
  axiosInstance.interceptors.request.use((config) => {
    if (!isNativeApp()) return config

    const url = config.url || ''
    if (url.startsWith('/')) {
      config.url = LOCAL_ORIGIN + url
      return config
    }

    if (/^https?:\/\//i.test(url) && !url.startsWith(LOCAL_ORIGIN)) {
      const method = (config.method || 'get').toUpperCase()
      const msg = `[SECURITY] Blocked ${method} ${url} — native app must never contact a non-local host directly`
      console.error(msg)
      // eslint-disable-next-line no-console
      if (window.NativePHP?.logSecurityEvent) {
        try { window.NativePHP.logSecurityEvent(msg) } catch (e) { /* best effort */ }
      }
      return Promise.reject(new Error(msg))
    }

    return config
  })
  return axiosInstance
}
