<template>
  <div class="card-surface p-4 md:p-6 border-2 !border-primary-500 dark:!border-primary-700">
    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
      
      <!-- Right Side: Name and Code -->
      <div class="flex flex-col items-end w-full md:w-auto text-right">
        <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-white mb-2">
          الاسم: {{ doctor.name }}
        </h2>
        <p class="text-sm font-bold text-primary-600 dark:text-primary-400">
          # الكود : {{ doctor.id }}
        </p>
      </div>

      <!-- Middle: Details -->
      <div class="flex flex-col md:flex-row items-center gap-6 w-full md:w-auto justify-center md:justify-end text-sm text-slate-700 dark:text-slate-300 font-medium">
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
          <span>التخصص: {{ doctor.specialization || '—' }}</span>
        </div>
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
          <span dir="ltr">البريد: {{ doctor.email || '—' }}</span>
        </div>
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
          <span dir="ltr">الهاتف: {{ doctor.phone || '—' }}</span>
        </div>
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          <span>المرضى: {{ doctor.patients_count || 0 }}</span>
        </div>
      </div>

      <!-- Left Side: Actions -->
      <div class="flex flex-col items-start gap-2.5 w-full md:w-auto">
        <!-- Buttons -->
        <div class="flex items-center gap-3 w-full justify-start md:justify-end">
          <button @click="$emit('suspend')" class="px-4 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors" :class="doctor.status === 'active' ? 'bg-rose-50 hover:bg-rose-100 dark:hover:bg-rose-900/20 border border-rose-200 text-rose-600' : 'bg-emerald-50 hover:bg-emerald-100 dark:hover:bg-emerald-900/20 border border-emerald-200 text-emerald-600'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
            {{ doctor.status === 'active' ? 'إيقاف' : 'تفعيل' }}
          </button>
          <button @click="$emit('edit')" class="btn-primary !px-4 !py-1.5 flex items-center gap-1.5 text-xs font-bold">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            تعديل
          </button>
          <button @click="$emit('delete')" class="px-4 py-1.5 bg-rose-50 hover:bg-rose-100 dark:hover:bg-rose-900/20 border border-rose-200 text-rose-600 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            حذف
          </button>
        </div>

        <!-- Status Badge -->
        <div class="flex items-center gap-1.5 font-bold text-sm" :class="doctor.status === 'active' ? 'text-emerald-600' : 'text-rose-600'">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
          <span>الحالة: {{ doctor.status === 'active' ? 'نشط' : 'موقوف' }}</span>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
defineProps({
  doctor: {
    type: Object,
    required: true
  }
})

defineEmits(['edit', 'delete', 'suspend'])
</script>
