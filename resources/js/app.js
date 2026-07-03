import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import i18n from './Plugins/i18n';
import UploadManager from './Components/UploadManager.vue';
import GlobalDialog from './Components/GlobalDialog.vue';
import ToastContainer from './Components/ToastContainer.vue';

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

const appName = import.meta.env.VITE_APP_NAME || 'Medical Plus';

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
    progress: {
        color: '#0f766e',
        showSpinner: true,
    },
});
