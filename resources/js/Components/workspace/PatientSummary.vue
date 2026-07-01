<template>
  <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
    <div class="p-4 md:p-5">
      <div class="flex items-start gap-4">
        <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-700 dark:text-primary-300 font-bold text-xl md:text-2xl flex-shrink-0">
          {{ patient.name?.charAt(0) || '?' }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h2 class="text-lg md:text-xl font-bold font-heading text-slate-900 dark:text-white">{{ patient.name }}</h2>
            <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded"
              :class="isPrimaryDoctor
                ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'"
            >{{ isPrimaryDoctor ? 'Primary' : 'Shared' }}</span>
          </div>
          <p class="text-xs md:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
            {{ patient.code }} <span class="mx-1">•</span> {{ patient.phone || '—' }} <span v-if="patient.email" class="mx-1">•</span> {{ patient.email }}
          </p>
        </div>
        <button @click="$emit('action')" class="p-2 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors flex-shrink-0">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" /></svg>
        </button>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2 mt-4">
        <div v-for="field in summaryFields" :key="field.key" class="bg-slate-50 dark:bg-slate-800/50 rounded-lg p-2.5">
          <p class="text-[10px] font-medium text-slate-400 dark:text-slate-500 uppercase tracking-wider">{{ field.label }}</p>
          <p class="text-xs md:text-sm font-medium text-slate-800 dark:text-slate-200 mt-0.5 truncate" :title="field.value">
            {{ field.value || '—' }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  patient: Object,
  isPrimaryDoctor: Boolean,
})

defineEmits(['action'])

const summaryFields = computed(() => {
  const p = props.patient || {}
  const age = p.date_of_birth ? calculateAge(p.date_of_birth) : (p.age || '—')
  return [
    { key: 'id', label: 'Patient ID', value: p.code || p.uuid?.slice(0, 8) },
    { key: 'mrn', label: 'MRN', value: p.medical_record_number },
    { key: 'age', label: 'Age', value: age },
    { key: 'gender', label: 'Gender', value: p.gender },
    { key: 'blood', label: 'Blood Group', value: p.blood_group },
    { key: 'weight', label: 'Weight', value: p.weight ? `${p.weight} kg` : null },
    { key: 'height', label: 'Height', value: p.height ? `${p.height} cm` : null },
    { key: 'status', label: 'Status', value: p.medical_status || (p.deleted_at ? 'Archived' : 'Active') },
    { key: 'diagnosis', label: 'Diagnosis', value: p.diagnosis },
    { key: 'allergies', label: 'Allergies', value: p.allergies },
    { key: 'chronic', label: 'Chronic Diseases', value: p.chronic_diseases },
    { key: 'phone', label: 'Phone', value: p.phone },
    { key: 'last_visit', label: 'Last Visit', value: formatDate(p.last_visit_date || p.last_visit) },
    { key: 'next_appt', label: 'Next Appointment', value: formatDate(p.next_appointment_date || p.next_appointment) },
    { key: 'address', label: 'Address', value: p.address },
  ]
})

function calculateAge(dob) {
  const birth = new Date(dob)
  const today = new Date()
  let age = today.getFullYear() - birth.getFullYear()
  const m = today.getMonth() - birth.getMonth()
  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--
  return age
}

function formatDate(d) {
  if (!d) return null
  return new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>
