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

export const LOCAL_ORIGIN = 'http://127.0.0.1'

/**
 * NOTE ON RELATIVE URLS — do NOT rewrite them to an absolute local origin.
 *
 * It's tempting to think a relative URL like '/api/v1/chunk/init' is unsafe
 * because the WebView's page origin can be production (pulled there while
 * online — see MainActivity REMOTE_SERVER load), so the browser resolves it
 * to https://prof-hosam-fekry.online/api/v1/chunk/init. That's true, but it's
 * NOT a bug on its own: WebViewManager's shouldInterceptRequest() classifies
 * every request by URL PATH alone via RequestRouter (see RequestRouter.kt) —
 * a POST to any /api/ or /_native/ path is intercepted and served by the
 * embedded engine regardless of what host is in the URL. The request never
 * actually leaves the device as long as interception succeeds.
 *
 * Forcing an absolute http://127.0.0.1 origin here breaks that: it makes
 * every local call cross-origin from the page's perspective (https prod →
 * http 127.0.0.1), which triggers a CORS preflight (OPTIONS) for requests
 * that used to be same-origin and preflight-free. This was tried and caused
 * every local endpoint (categories, workspace, files, chunk/init — not just
 * uploads) to silently fail after only the OPTIONS leg completed. Keep
 * relative URLs relative.
 *
 * The real risk this guards against is interception FAILING (an exception in
 * shouldInterceptRequest/PHPWebViewClient) and WebView falling back to a real
 * network request — which, for a relative URL resolved against a production
 * page origin, actually reaches production. That fallback path is closed on
 * the native side (WebViewManager.kt: the outer try/catch and the EXTERNAL
 * branch both block any write whose resolved host isn't local before letting
 * WebView touch the real network), not by mangling URLs in JS.
 */
export function localApiUrl(path) {
  path = path.startsWith('/') ? path : '/' + path
  return path
}

/**
 * Install a request interceptor that blocks (fails loudly, never silently
 * sends) any request whose URL is ALREADY absolute and points somewhere
 * other than the local engine — e.g. a hardcoded https://prof-hosam-fekry...
 * URL written by mistake. Relative URLs are left untouched; see the note on
 * localApiUrl() above for why. The ONLY code path allowed to reach
 * production is RemoteApiService on the PHP side (Settings → Manual Sync).
 */
export function guardLocalOrigin(axiosInstance) {
  axiosInstance.interceptors.request.use((config) => {
    if (!isNativeApp()) return config

    const url = config.url || ''
    if (/^https?:\/\//i.test(url) && !url.startsWith(LOCAL_ORIGIN)) {
      const method = (config.method || 'get').toUpperCase()
      const msg = `[SECURITY] Blocked ${method} ${url} — native app must never contact a non-local host directly`
      console.error(msg)
      if (window.NativePHP?.logSecurityEvent) {
        try { window.NativePHP.logSecurityEvent(msg) } catch (e) { /* best effort */ }
      }
      return Promise.reject(new Error(msg))
    }

    return config
  })
  return axiosInstance
}
