<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="modelValue" class="fixed inset-0 z-[150] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="$emit('update:modelValue', false)"></div>

        <!-- Modal Box -->
        <div class="relative bg-white dark:bg-slate-900 rounded-[28px] shadow-2xl w-full max-w-sm p-8 text-center flex flex-col items-center border border-slate-100 dark:border-slate-800 animate-scale-up">
          <!-- Big Settings Cogwheel Icon -->
          <div class="w-16 h-16 rounded-full bg-teal-50 dark:bg-teal-900/20 text-teal-600 dark:text-teal-400 flex items-center justify-center mb-4 transition-transform duration-700 hover:rotate-90">
            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>

          <!-- Title -->
          <h2 class="text-2xl font-black text-slate-800 dark:text-white mb-6 tracking-wide">الإعدادات</h2>

          <!-- Action Buttons Stack -->
          <div class="w-full space-y-3.5 mb-6">
            <!-- Downloaded Patients (المرضى المحفوظين Offline) — app only -->
            <button
              v-if="detectNative()"
              type="button"
              @click="openOfflinePackages"
              class="w-full flex items-center justify-between px-5 py-3 border border-teal-500/30 dark:border-teal-500/20 text-teal-700 dark:text-teal-400 bg-teal-50/10 hover:bg-teal-50/30 rounded-xl text-sm font-bold transition-all active:scale-[0.98]"
            >
              <span class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                المرضى المحفوظين Offline
              </span>
            </button>

            <!-- 1. Change Appearance (تغيير المظهر) -->
            <button
              type="button"
              @click="toggleTheme"
              class="w-full flex items-center justify-between px-5 py-3 border border-teal-500/30 dark:border-teal-500/20 text-teal-700 dark:text-teal-400 bg-teal-50/10 hover:bg-teal-50/30 rounded-xl text-sm font-bold transition-all active:scale-[0.98]"
            >
              <span class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
                تغيير المظهر
              </span>
              <span class="text-xs font-semibold text-slate-400 uppercase">{{ theme === 'dark' ? 'داكن' : 'مضيء' }}</span>
            </button>

            <!-- 2. English / عربي -->
            <button
              type="button"
              @click="toggleLocale"
              class="w-full flex items-center justify-between px-5 py-3 border border-teal-500/30 dark:border-teal-500/20 text-teal-700 dark:text-teal-400 bg-teal-50/10 hover:bg-teal-50/30 rounded-xl text-sm font-bold transition-all active:scale-[0.98]"
            >
              <span class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 002 2h2a2.5 2.5 0 002.5-2.5V10a2 2 0 00-2-2h-1a2 2 0 00-2-2V5a2 2 0 00-2-2H9.05a2 2 0 00-2 2v.935z" />
                </svg>
                English / عربي
              </span>
              <span class="text-xs font-semibold text-slate-400">{{ locale === 'ar' ? 'العربية' : 'English' }}</span>
            </button>

            <!-- Admin Categories Manager (إدارة الأقسام) -->
            <button
              v-if="$page.props.auth?.user?.role === 'super-admin'"
              type="button"
              @click="openCategoryManager"
              class="w-full flex items-center justify-between px-5 py-3 border border-teal-500/30 dark:border-teal-500/20 text-teal-700 dark:text-teal-400 bg-teal-50/10 hover:bg-teal-50/30 rounded-xl text-sm font-bold transition-all active:scale-[0.98]"
            >
              <span class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                </svg>
                إدارة الأقسام
              </span>
            </button>

            <!-- 4. Download App (تحميل التطبيق) -->
            <button
              type="button"
              @click="handleDownloadApp"
              :disabled="downloadingApp"
              class="w-full flex items-center justify-between px-5 py-3 border border-teal-500/30 dark:border-teal-500/20 text-teal-700 dark:text-teal-400 bg-teal-50/10 hover:bg-teal-50/30 rounded-xl text-sm font-bold transition-all active:scale-[0.98] disabled:opacity-60"
            >
              <span class="flex items-center gap-2">
                <svg v-if="!downloadingApp" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                تحميل التطبيق
              </span>
              <span class="text-xs font-semibold text-slate-400 font-sans" v-if="version">{{ version }}</span>
            </button>

            <!-- 5. Logout (خروج) -->
            <button
              type="button"
              @click="logout"
              class="w-full flex items-center justify-center gap-2 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition-all active:scale-[0.98]"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              خروج
            </button>
          </div>

          <!-- Cancel (إلغاء) -->
          <button
            type="button"
            @click="$emit('update:modelValue', false)"
            class="px-6 py-2 bg-teal-50/50 hover:bg-teal-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-teal-600 dark:text-teal-400 rounded-lg text-xs font-bold transition-colors"
          >
            إلغاء
          </button>
        </div>
      </div>
    </Transition>
    
    <!-- Category Manager Modal (Admin only) -->
    <CategoryManagerModal v-model="showCategoryManager" />

    <!-- Offline Packages Manager Overlay -->
    <Transition name="fade">
      <div v-if="showOfflinePackages" class="fixed inset-0 z-[160] flex items-center justify-center p-4 overflow-y-auto">
        <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" @click="showOfflinePackages = false"></div>
        <div class="relative bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 md:p-8 border border-slate-200 dark:border-slate-800 z-10">
          <div class="flex items-center justify-between mb-4 border-b pb-3 border-slate-200 dark:border-slate-800">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
              <svg class="w-5 h-5 text-teal-600 dark:text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              المرضى المحفوظين Offline
            </h3>
            <button @click="showOfflinePackages = false" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 bg-slate-100 dark:bg-slate-800 text-sm font-bold">
              إغلاق
            </button>
          </div>
          <OfflinePackagesManager />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from '@/Composables/useTheme'
import { useLocale } from '@/Composables/useLocale'
import { useToast } from '@/Composables/useToast'
import axios from 'axios'
import CategoryManagerModal from '@/Components/workspace/CategoryManagerModal.vue'
import OfflinePackagesManager from '@/Components/workspace/OfflinePackagesManager.vue'
import { useNativeBridge } from '@/Composables/useNativeBridge'

defineProps({
  modelValue: Boolean
})

const emit = defineEmits(['update:modelValue'])

const { theme } = useTheme()
const { locale } = useLocale()
const toast = useToast()
const { detectNative } = useNativeBridge()

const version = ref('v1.0.0')
const downloadUrl = ref('')
const downloadingApp = ref(false)

const showCategoryManager = ref(false)
const showOfflinePackages = ref(false)

function openOfflinePackages() {
  emit('update:modelValue', false) // close settings modal
  setTimeout(() => {
    showOfflinePackages.value = true
  }, 200)
}

function openCategoryManager() {
  emit('update:modelValue', false) // close settings modal
  setTimeout(() => {
    showCategoryManager.value = true
  }, 300) // allow fade out
}

const GITHUB_API = 'https://api.github.com/repos/kironagy/medical_doctor/releases/latest'

async function fetchLatestVersion() {
  try {
    const res = await fetch(GITHUB_API, {
      headers: {
        'Accept': 'application/vnd.github.v3+json',
        'User-Agent': 'prof-hosam-fekry-App'
      }
    })
    if (res.ok) {
      const data = await res.json()
      version.value = data.tag_name || 'v1.0.0'
      const assets = data.assets || []
      const apkAsset = assets.find(a => a.name && a.name.endsWith('.apk'))
      if (apkAsset) {
        downloadUrl.value = apkAsset.browser_download_url
      }
    }
  } catch (e) {
    console.error('Failed to fetch release version', e)
  }
}

function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  toast.success('تم تغيير مظهر النظام بنجاح')
}

function toggleLocale() {
  locale.value = locale.value === 'ar' ? 'en' : 'ar'
  toast.success('تم تغيير لغة التطبيق')
}

function handleDownloadApp() {
  if (downloadingApp.value) return
  if (!downloadUrl.value) {
    window.open('https://github.com/kironagy/medical_doctor/releases', '_blank')
    return
  }
  downloadingApp.value = true
  try {
    if (window.AndroidBridge) {
      window.AndroidBridge.downloadApk(downloadUrl.value)
    } else {
      const link = document.createElement('a')
      link.href = downloadUrl.value
      link.download = 'prof-hosam-fekry.apk'
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
    }
    toast.success('بدء تحميل التطبيق...')
  } catch (e) {
    toast.error('فشل بدء التحميل')
  } finally {
    downloadingApp.value = false
  }
}

function logout() {
  router.post('/logout')
}

onMounted(() => {
  fetchLatestVersion()
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.animate-scale-up {
  animation: scale-up 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
@keyframes scale-up {
  0% { transform: scale(0.95); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
</style>
