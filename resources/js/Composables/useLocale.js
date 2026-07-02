import { ref, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

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

    const applyLocale = (l) => {
        if (i18nLocale.value !== l) {
            i18nLocale.value = l;
        }
        document.documentElement.lang = l;
        document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
        try { localStorage.setItem('locale', l) } catch (e) { /* noop */ }
    };

    // Apply if different from what i18n already has
    if (i18nLocale.value !== locale.value) {
        applyLocale(locale.value);
    } else {
        // Ensure direction is correct even if locale matches
        const dir = locale.value === 'ar' ? 'rtl' : 'ltr';
        if (document.documentElement.dir !== dir) {
            document.documentElement.dir = dir;
        }
    }

    watch(locale, (newLocale) => {
        applyLocale(newLocale);
        router.put('/settings/preferences', { locale: newLocale }, {
            preserveScroll: true,
            preserveState: true,
        });
    });

    return { locale };
}
