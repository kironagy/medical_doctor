import { ref, watch, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

export function useLocale() {
    const page = usePage();
    const { locale: i18nLocale } = useI18n();
    
    const getPreference = () => {
        return page.props.auth?.user?.preferences?.locale || 'en';
    };

    const locale = ref(getPreference());

    const applyLocale = (l) => {
        i18nLocale.value = l;
        document.documentElement.lang = l;
        document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
        try { localStorage.setItem('locale', l) } catch (e) { /* noop */ }
    };

    onMounted(() => {
        applyLocale(locale.value);
    });

    watch(locale, (newLocale) => {
        applyLocale(newLocale);
        router.put('/settings/preferences', { locale: newLocale }, {
            preserveScroll: true,
            preserveState: true,
        });
    });

    return { locale };
}
