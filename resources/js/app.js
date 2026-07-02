import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import i18n from './Plugins/i18n';
import UploadManager from './Components/UploadManager.vue';
import GlobalDialog from './Components/GlobalDialog.vue';
import ToastContainer from './Components/ToastContainer.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Medical Plus';
const isMobile = typeof window.__NATIVE_MOBILE__ !== 'undefined' && window.__NATIVE_MOBILE__ === true;

let mobileSyncManager = null;

if (isMobile) {
  import('./Mobile/MobileSyncManager').then(mod => {
    mobileSyncManager = mod;
    mod.initMobileSync();

    import('./Components/MobileSyncStatus.vue').then(({ default: MobileSyncStatus }) => {
      const ssEl = document.createElement('div');
      ssEl.id = 'mobile-sync-status-root';
      document.body.appendChild(ssEl);
      const ssApp = createApp({
        render: () => h(MobileSyncStatus, { syncManager: mod })
      }).mount(ssEl);
    });
  });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const umEl = document.createElement('div');
        umEl.id = 'upload-manager-root';
        umEl.setAttribute('data-turbo-permanent', '');
        document.body.appendChild(umEl);
        createApp({ render: () => h(UploadManager) })
            .use(i18n)
            .mount(umEl);

        const gdEl = document.createElement('div');
        gdEl.id = 'global-dialog-root';
        document.body.appendChild(gdEl);
        createApp({ render: () => h(GlobalDialog) })
            .use(i18n)
            .mount(gdEl);

        const tcEl = document.createElement('div');
        tcEl.id = 'toast-container-root';
        document.body.appendChild(tcEl);
        createApp({ render: () => h(ToastContainer) })
            .use(i18n)
            .mount(tcEl);

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);

        if (isMobile && mobileSyncManager) {
          app.config.globalProperties.$sync = mobileSyncManager;
        }

        return app;
    },
    progress: {
        color: '#0f766e',
        showSpinner: true,
    },
});
