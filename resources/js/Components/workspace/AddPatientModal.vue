<template>
  <WorkspaceModal :modelValue="modelValue" @update:modelValue="$emit('update:modelValue', $event)" @close="$emit('update:modelValue', false)" title="Add Patient" size="lg" persistent>
    <form @submit.prevent="submit" class="space-y-5">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Full Name *</label>
          <input v-model="form.name" class="input-field" required placeholder="e.g. John Doe" />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-500">{{ errors.name }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone</label>
          <input v-model="form.phone" class="input-field" placeholder="+1 234 567 890" />
          <p v-if="errors.phone" class="mt-1 text-xs text-rose-500">{{ errors.phone }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email</label>
          <input v-model="form.email" type="email" class="input-field" placeholder="patient@example.com" />
          <p v-if="errors.email" class="mt-1 text-xs text-rose-500">{{ errors.email }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Date of Birth</label>
          <input v-model="form.date_of_birth" type="date" class="input-field" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Gender</label>
          <select v-model="form.gender" class="input-field">
            <option value="">—</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Blood Group</label>
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

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Medical Record Number</label>
          <input v-model="form.medical_record_number" class="input-field" placeholder="e.g. MRN-001234" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Weight (kg)</label>
            <input v-model="form.weight" type="number" step="0.1" min="0" max="500" class="input-field" placeholder="70" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Height (cm)</label>
            <input v-model="form.height" type="number" step="0.1" min="0" max="300" class="input-field" placeholder="175" />
          </div>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Address</label>
          <input v-model="form.address" class="input-field" placeholder="123 Medical St, City" />
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Diagnosis</label>
          <textarea v-model="form.diagnosis" class="input-field" rows="2" placeholder="Brief description of condition..."></textarea>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Allergies</label>
          <textarea v-model="form.allergies" class="input-field" rows="2" placeholder="List known allergies..."></textarea>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Chronic Diseases</label>
          <textarea v-model="form.chronic_diseases" class="input-field" rows="2" placeholder="List chronic conditions..."></textarea>
        </div>
      </div>

      <div class="sticky bottom-0 bg-white dark:bg-slate-900 pt-4 pb-1 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-3 -mx-1 px-1">
        <button type="button" @click="$emit('update:modelValue', false)" class="px-5 py-2.5 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors rounded-lg">Cancel</button>
        <BaseButton type="submit" :loading="saving">Save Patient</BaseButton>
      </div>
    </form>
  </WorkspaceModal>
</template>

<script setup>
import { ref, reactive } from 'vue'
import WorkspaceModal from './WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  modelValue: Boolean,
})
const emit = defineEmits(['update:modelValue', 'saved'])

const saving = ref(false)
const errors = ref({})
const toast = useToast()
const { addPatient: addPatientToWorkspace } = useWorkspace()

const bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']

const form = reactive({
  name: '', phone: '', email: '', address: '', diagnosis: '',
  date_of_birth: '', gender: '', blood_group: '', weight: '', height: '',
  allergies: '', chronic_diseases: '', medical_status: '', medical_record_number: '',
})

function resetForm() {
  Object.keys(form).forEach(k => form[k] = '')
  errors.value = {}
}

async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form }
    if (!payload.weight) delete payload.weight
    if (!payload.height) delete payload.height
    const result = await addPatientToWorkspace(payload)
    if (result.success) {
      resetForm()
      emit('saved', result.patient)
      emit('update:modelValue', false)
      toast.success('Patient created')
    } else {
      errors.value = result.errors || {}
      toast.error('Failed to create patient')
    }
  } catch (e) {
    if (e.response?.status === 422) {
      const errs = e.response.data?.errors || {}
      errors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
    }
    toast.error('Failed to create patient')
  } finally {
    saving.value = false
  }
}
</script>
