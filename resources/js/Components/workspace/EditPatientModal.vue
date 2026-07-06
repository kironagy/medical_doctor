<template>
  <WorkspaceModal :modelValue="modelValue" @update:modelValue="$emit('update:modelValue', $event)" @close="$emit('update:modelValue', false)" :title="$t('patients.edit_information')" size="lg" persistent>
    <form @submit.prevent="submit" class="space-y-5">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.full_name') }} *</label>
          <input v-model="form.name" class="input-field" required />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-500">{{ errors.name }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.phone') }}</label>
          <input v-model="form.phone" class="input-field" />
          <p v-if="errors.phone" class="mt-1 text-xs text-rose-500">{{ errors.phone }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.email') }}</label>
          <input v-model="form.email" type="email" class="input-field" />
          <p v-if="errors.email" class="mt-1 text-xs text-rose-500">{{ errors.email }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.date_of_birth') }}</label>
          <input v-model="form.date_of_birth" type="date" class="input-field" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.gender') }}</label>
          <select v-model="form.gender" class="input-field">
            <option value="">—</option>
            <option value="Male">{{ $t('patients.gender_male') }}</option>
            <option value="Female">{{ $t('patients.gender_female') }}</option>
            <option value="Other">{{ $t('patients.gender_other') }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.blood_group') }}</label>
          <select v-model="form.blood_group" class="input-field">
            <option value="">—</option>
            <option v-for="bg in bloodGroups" :key="bg" :value="bg">{{ bg }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Medical Status</label>
          <select v-model="form.medical_status" class="input-field">
            <option value="">—</option>
            <option value="Active">Active</option>
            <option value="In Treatment">In Treatment</option>
            <option value="Recovered">Recovered</option>
            <option value="Critical">Critical</option>
            <option value="Discharged">Discharged</option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.weight') }}</label>
            <input v-model="form.weight" type="number" step="0.1" min="0" max="500" class="input-field" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.height') }}</label>
            <input v-model="form.height" type="number" step="0.1" min="0" max="300" class="input-field" />
          </div>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.address') }}</label>
          <input v-model="form.address" class="input-field" />
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.diagnosis') }}</label>
          <textarea v-model="form.diagnosis" class="input-field" rows="2"></textarea>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.allergies') }}</label>
          <textarea v-model="form.allergies" class="input-field" rows="2"></textarea>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.chronic_diseases') }}</label>
          <textarea v-model="form.chronic_diseases" class="input-field" rows="2"></textarea>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $t('patients.medical_record_number') }}</label>
          <input v-model="form.medical_record_number" class="input-field" />
        </div>
      </div>

        <div class="sticky bottom-0 bg-white dark:bg-slate-900 pt-4 pb-1 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-3 -mx-1 px-1">
         <button type="button" @click="$emit('update:modelValue', false)" class="px-5 py-2.5 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors rounded-lg">{{ $t('common.cancel') }}</button>
         <BaseButton type="submit" :loading="saving">{{ $t('patients.update_patient') }}</BaseButton>
       </div>
    </form>
  </WorkspaceModal>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import axios from 'axios'
import WorkspaceModal from './WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'

const props = defineProps({
  modelValue: Boolean,
  patient: Object,
})
const emit = defineEmits(['update:modelValue', 'saved'])

const saving = ref(false)
const errors = ref({})

const bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']

const form = reactive({
  name: '', phone: '', email: '', address: '', diagnosis: '',
  date_of_birth: '', gender: '', blood_group: '', weight: '', height: '',
  allergies: '', chronic_diseases: '', medical_status: '', medical_record_number: '',
})

watch(() => props.patient, (p) => {
  if (p) {
    Object.assign(form, {
      name: p.name || '',
      phone: p.phone || '',
      email: p.email || '',
      address: p.address || '',
      diagnosis: p.diagnosis || '',
      date_of_birth: p.date_of_birth || '',
      gender: p.gender || '',
      blood_group: p.blood_group || '',
      weight: p.weight || '',
      height: p.height || '',
      allergies: p.allergies || '',
      chronic_diseases: p.chronic_diseases || '',
      medical_status: p.medical_status || '',
      medical_record_number: p.medical_record_number || '',
    })
    errors.value = {}
  }
}, { immediate: true })

async function submit() {
  if (!props.patient?.uuid) return
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form }
    if (!payload.weight) delete payload.weight
    if (!payload.height) delete payload.height
    await axios.put(`/api/v1/workspace/patients/${props.patient.uuid}`, payload)
    emit('saved', { ...props.patient, ...form })
    emit('update:modelValue', false)
  } catch (e) {
    if (e.response?.status === 422) {
      const errs = e.response.data?.errors || {}
      errors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
    }
  } finally {
    saving.value = false
  }
}
</script>
