import { createI18n } from 'vue-i18n';
import en from '../Locales/en.json';
import ar from '../Locales/ar.json';

const i18n = createI18n({
  legacy: false, // use Composition API
  locale: 'en', // default locale
  fallbackLocale: 'en',
  messages: {
    en,
    ar
  },
  warnHtmlMessage: false, // Turn off HTML warnings
  silentTranslationWarn: true, // Turn off translation warnings
  missingWarn: false, // Turn off missing translation warnings
  fallbackWarn: false, // Turn off fallback warnings
  datetimeFormats: {},
  numberFormats: {}
});

export default i18n;
