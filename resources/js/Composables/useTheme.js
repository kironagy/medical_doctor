import { ref, watch, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

export function useTheme() {
    const page = usePage();
    
    const getPreference = () => {
        const stored = localStorage.getItem('theme')
        if (stored) return stored
        return page.props.auth?.user?.preferences?.theme || 'system';
    };

    const theme = ref(getPreference());

    let persisting = false;

    const applyTheme = (t) => {
        if (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        try { localStorage.setItem('theme', t) } catch (e) { /* noop */ }
    };

    onMounted(() => {
        applyTheme(theme.value);
        
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (theme.value === 'system') {
                applyTheme('system');
            }
        });
    });

    watch(theme, (newTheme) => {
        applyTheme(newTheme);
        if (persisting) return;
        persisting = true;
        axios.put('/settings/preferences', { theme: newTheme }).finally(() => {
            persisting = false;
        });
    });

    return { theme };
}
