import { createI18n } from 'vue-i18n';
import en from '../Locales/en.json';
import ar from '../Locales/ar.json';

let initialLocale = 'en';
if (typeof window !== 'undefined') {
  try {
    const stored = localStorage.getItem('locale');
    if (stored === 'ar' || stored === 'en') {
      initialLocale = stored;
    }
  } catch (e) { /* noop */ }
}

// Apply direction immediately to prevent flash
if (typeof document !== 'undefined') {
  document.documentElement.lang = initialLocale;
  document.documentElement.dir = initialLocale === 'ar' ? 'rtl' : 'ltr';
}

const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'en',
  messages: {
    en,
    ar
  },
  warnHtmlMessage: false,
  silentTranslationWarn: true,
  missingWarn: false,
  fallbackWarn: false,
  datetimeFormats: {},
  numberFormats: {}
});

export default i18n;
