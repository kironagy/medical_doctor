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
