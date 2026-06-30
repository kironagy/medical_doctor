import { ref, watch, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

export function useTheme() {
    const page = usePage();
    
    const getPreference = () => {
        return page.props.auth?.user?.preferences?.theme || 'system';
    };

    const theme = ref(getPreference());

    const applyTheme = (t) => {
        if (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    };

    onMounted(() => {
        applyTheme(theme.value);
        
        // Listen for system theme changes if system mode is selected
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (theme.value === 'system') {
                applyTheme('system');
            }
        });
    });

    watch(theme, (newTheme) => {
        applyTheme(newTheme);
        // Persist to backend
        router.put('/settings/preferences', { theme: newTheme }, {
            preserveScroll: true,
            preserveState: true,
        });
    });

    return { theme };
}
