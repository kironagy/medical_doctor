import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import i18n from './Plugins/i18n';
import UploadManager from './Components/UploadManager.vue';
import GlobalDialog from './Components/GlobalDialog.vue';
import ToastContainer from './Components/ToastContainer.vue';
import { configureUploads } from './Composables/useUploads';

// ── FIX-PERF-2 + FIX-PERF-4: device-aware upload configuration ────────────
// On the native Android bridge every PHP request is serialised behind a
// pthread_mutex (php_bridge.c:28, confirmed — architecturally necessary for
// php_embed). POOL_SIZE=4 therefore multiplies peak JVM heap by 4× with zero
// throughput benefit; a 5 MB chunk produces ~45 MB of transient allocation
// across 4 memory spaces, × 4 = ~180 MB peak.
// On device: pool=1 (one request at a time, matching the mutex), chunk=2 MB.
// Reduces peak to ~9 MB (see §14.2 of 03-UPLOAD-PERFORMANCE.md).
// Web build keeps pool=4, chunk=5 MB — untouched.
// detectNative() is already used in InlineFilePreview.vue and CategoryBlock.vue.
const _isNative = typeof window !== 'undefined' &&
  window.AndroidPOST !== undefined;
if (_isNative) {
  configureUploads({ poolSize: 1, chunkSize: 2 * 1024 * 1024 });
}
// ── End FIX-PERF-2 + FIX-PERF-4 ──────────────────────────────────────────

// ── FIX-PERF-8: gate client-error POSTs behind an explicit flag.
// On the NativePHP bridge each fetch() is a full PHP boot, contending for
// g_php_request_mutex with actual upload requests. Only enable when debugging.
const _logClientErrors = typeof import.meta !== 'undefined' &&
  import.meta.env?.VITE_LOG_CLIENT_ERRORS === 'true';

// ── Global error boundary ──────────────────────────────────────────
// Never allow silent failures. Every unhandled error logs to console
// for diagnostic capture and includes full context (stack, file, URI).
function captureError(context, error, extra = {}) {
  const payload = {
    context,
    message: error?.message || String(error),
    stack: error?.stack || '',
    time: new Date().toISOString(),
    url: typeof location !== 'undefined' ? location.href : '',
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '',
    ...extra,
  }
  console.error(`[AppCrash] ${context}:`, payload.message, payload.stack)
  if (_logClientErrors) {
    try {
      if (typeof fetch !== 'undefined') {
        fetch('/api/v1/log/client-error', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(payload),
        }).catch(() => {})
      }
    } catch (_) {}
  }
}

// Global unhandled promise rejection handler
window.addEventListener('unhandledrejection', (event) => {
  captureError('UnhandledPromiseRejection', event.reason, {
    promiseType: event.reason?.name || typeof event.reason,
  })
  event.preventDefault()
})

// Global window error handler
window.addEventListener('error', (event) => {
  captureError('WindowError', event.error || event.message, {
    filename: event.filename,
    lineno: event.lineno,
    colno: event.colno,
  })
  event.preventDefault()
})

const appName = import.meta.env.VITE_APP_NAME || 'prof hosam fekry ortho team';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // Mount UploadManager in a persistent, separate root appended to <body>.
        // This keeps it alive across Inertia page navigations so uploads truly
        // continue in the background while the user moves between pages.
        const umEl = document.createElement('div');
        umEl.id = 'upload-manager-root';
        umEl.setAttribute('data-turbo-permanent', '');
        document.body.appendChild(umEl);
        createApp({ render: () => h(UploadManager) })
            .use(i18n)
            .mount(umEl);

        // Mount GlobalDialog as a persistent root so confirmation dialogs
        // work on every page, including standalone pages like DoctorWorkspace.
        const gdEl = document.createElement('div');
        gdEl.id = 'global-dialog-root';
        document.body.appendChild(gdEl);
        createApp({ render: () => h(GlobalDialog) })
            .use(i18n)
            .mount(gdEl);

        // Mount ToastContainer as a persistent root so toasts appear on every page.
        const tcEl = document.createElement('div');
        tcEl.id = 'toast-container-root';
        document.body.appendChild(tcEl);
        createApp({ render: () => h(ToastContainer) })
            .use(i18n)
            .mount(tcEl);

        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)

        // Vue global error handler for render/component errors
        vueApp.config.errorHandler = (err, instance, info) => {
          captureError('VueError', err, {
            info,
            componentName: instance?.type?.name || instance?.type?.__name || 'Unknown',
          })
        }

        return vueApp.mount(el);
    },
    // No top progress bar and no corner spinner on navigation.
    progress: false,
});
