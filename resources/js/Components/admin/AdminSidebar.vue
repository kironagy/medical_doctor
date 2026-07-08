<template>
  <aside
    class="flex flex-col h-full bg-white dark:bg-slate-900 border-l border-slate-200 dark:border-slate-800 relative"
    dir="rtl"
  >
    <!-- Add Doctor Button at the top (Desktop Only) -->
    <div v-if="!isMobile" class="p-3 pb-2 flex-shrink-0">
      <button
        @click="$emit('add-doctor')"
        class="w-full flex items-center justify-center gap-2 py-2.5 text-sm font-bold text-white bg-primary-600 hover:bg-primary-700 rounded-lg transition-colors shadow-sm"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
        طبيب جديد
      </button>
    </div>

    <!-- Search Box -->
    <div class="px-3 py-3 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="بحث بالاسم / البريد..."
          class="w-full pl-3 pr-10 py-2.5 text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-900 dark:text-white placeholder:text-slate-400 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-right font-medium"
        />
        <svg class="absolute right-3 top-3 w-4.5 h-4.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
      </div>
    </div>

    <!-- Doctors List -->
    <div class="flex-1 overflow-y-auto overscroll-contain p-2 space-y-1">
      <div v-for="doctor in filteredDoctors" :key="doctor.id">
        <button
          @click="selectAndClose(doctor.id)"
          class="w-full text-right p-3.5 rounded-xl border transition-all"
          :class="selectedDoctorId === doctor.id
            ? 'bg-teal-50 dark:bg-teal-950/20 border-primary-500 dark:border-primary-400 shadow-sm'
            : 'bg-white dark:bg-slate-900 border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/50'"
        >
          <div class="flex items-center justify-between gap-2 mb-2">
            <!-- Status Badge -->
            <span class="text-xs px-2.5 py-0.5 rounded-lg font-bold bg-teal-50 dark:bg-teal-950/50 border border-teal-200 dark:border-teal-800" :class="doctor.status === 'active' ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">
              {{ doctor.status === 'active' ? 'نشط' : 'موقوف' }}
            </span>

            <!-- Name -->
            <p class="text-sm font-bold text-slate-900 dark:text-white truncate text-left">
              {{ doctor.name }}
            </p>
          </div>

          <!-- Specialization & Email -->
          <div class="flex items-center justify-end gap-1.5 text-xs text-slate-500 dark:text-slate-400" dir="ltr">
            <span class="truncate font-semibold">{{ doctor.specialization || '—' }}</span>
          </div>
          <div class="flex items-center justify-end gap-1.5 text-xs text-slate-400 dark:text-slate-500 mt-0.5" dir="ltr">
            <span class="truncate">{{ doctor.email || '—' }}</span>
          </div>
        </button>
      </div>

      <div v-if="filteredDoctors.length === 0" class="flex flex-col items-center justify-center h-40 text-slate-400 dark:text-slate-500 px-4">
        <svg class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
        <p class="text-sm">لا يوجد أطباء</p>
      </div>
    </div>

    <!-- Floating Action Button for Mobile -->
    <button
      v-if="isMobile"
      @click="$emit('add-doctor')"
      class="absolute bottom-24 left-6 z-50 w-12 h-12 bg-primary-600 hover:bg-primary-700 active:scale-95 text-white rounded-full flex items-center justify-center shadow-lg transition-transform"
    >
      <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
    </button>

    <!-- Pagination & Settings Button at the bottom -->
    <div class="border-t border-slate-100 dark:border-slate-800 p-3 bg-white dark:bg-slate-900 space-y-2.5 flex-shrink-0">
      <!-- Pagination -->
      <div v-if="doctorsMeta && doctorsMeta.last_page > 1" class="flex items-center justify-between gap-2 text-xs font-bold">
        <button
          @click="refreshDoctorList(doctorsMeta.current_page - 1)"
          :disabled="doctorsMeta.current_page <= 1"
          class="px-3 py-1.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-800 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors"
        >
          السابق
        </button>
        <span class="text-slate-500">{{ doctorsMeta.current_page }} / {{ doctorsMeta.last_page }}</span>
        <button
          @click="refreshDoctorList(doctorsMeta.current_page + 1)"
          :disabled="doctorsMeta.current_page >= doctorsMeta.last_page"
          class="px-3 py-1.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-800 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors"
        >
          التالي
        </button>
      </div>

      <!-- Settings Button -->
      <button
        @click="$emit('open-settings')"
        class="w-full flex items-center justify-center gap-2 py-2.5 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg text-sm transition-colors"
      >
        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
        الإعدادات
      </button>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  user: Object,
  mobileOpen: Boolean,
  doctors: { type: Object, required: true }, // paginated data
})

const emit = defineEmits(['close', 'add-doctor', 'open-settings', 'select'])

const { locale } = useI18n()
const isRtl = computed(() => locale.value === 'ar')

const searchQuery = ref('')
const isMobile = ref(false)

// Computed: filter doctors client-side
const filteredDoctors = computed(() => {
  if (!props.doctors?.data) return []
  const q = searchQuery.value.toLowerCase().trim()
  if (!q) return props.doctors.data
  return props.doctors.data.filter(d =>
    (d.name && d.name.toLowerCase().includes(q)) ||
    (d.email && d.email.toLowerCase().includes(q)) ||
    (d.specialization && d.specialization.toLowerCase().includes(q))
  )
})

const doctorsMeta = computed(() => {
  const d = props.doctors
  if (!d) return null
  return {
    current_page: d.current_page,
    last_page: d.last_page,
    from: d.from,
    to: d.to,
    total: d.total
  }
})

const selectedDoctorId = ref(null)

function selectAndClose(id) {
  selectedDoctorId.value = id
  emit('select', id)
  emit('close')
}

function refreshDoctorList(page) {
  if (page < 1) return
  router.get('/admin/doctors', { page }, { preserveState: true, preserveScroll: true })
}

function updateIsMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  updateIsMobile()
  window.addEventListener('resize', updateIsMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', updateIsMobile)
})



// Resize handler for mobile detection
window.addEventListener('resize', () => {
  isMobile.value = window.innerWidth < 768
})
</script>
