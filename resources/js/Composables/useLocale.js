import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

export function useLocale() {
    const page = usePage();
    const { locale: i18nLocale } = useI18n();

    const getPreference = () => {
        try {
            const stored = localStorage.getItem('locale');
            if (stored === 'ar' || stored === 'en') return stored;
        } catch (e) { /* noop */ }
        return page.props.auth?.user?.preferences?.locale || 'en';
    };

    const locale = ref(getPreference());

    let persisting = false;

    const applyLocale = (l) => {
        if (i18nLocale.value !== l) {
            i18nLocale.value = l;
        }
        document.documentElement.lang = l;
        document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
        try { localStorage.setItem('locale', l) } catch (e) { /* noop */ }
        document.documentElement.style.setProperty('--direction', l === 'ar' ? 'rtl' : 'ltr');
    };

    if (i18nLocale.value !== locale.value) {
        applyLocale(locale.value);
    } else {
        const dir = locale.value === 'ar' ? 'rtl' : 'ltr';
        if (document.documentElement.dir !== dir) {
            document.documentElement.dir = dir;
        }
    }

    let persistTimer
    watch(locale, (newLocale) => {
        applyLocale(newLocale);
        clearTimeout(persistTimer)
        persistTimer = setTimeout(() => {
            axios.put('/settings/preferences', { locale: newLocale })
        }, 300)
    });

    return { locale };
}
