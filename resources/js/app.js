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

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#0f766e',
        showSpinner: true,
    },
});
