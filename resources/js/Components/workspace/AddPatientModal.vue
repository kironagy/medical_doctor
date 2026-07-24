<template>
  <WorkspaceModal :modelValue="modelValue" @update:modelValue="$emit('update:modelValue', $event)" @close="$emit('update:modelValue', false)" title="إضافة مريض" size="md" persistent>
    <div class="text-start">
      <form @submit.prevent="submit" class="space-y-4 pt-2">
        <div>
          <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">الاسم</label>
          <input v-model="form.name" class="input-field w-full" required />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-500">{{ errors.name }}</p>
        </div>

        <div>
          <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">التليفون</label>
          <input v-model="form.phone" class="input-field w-full" />
          <p v-if="errors.phone" class="mt-1 text-xs text-rose-500">{{ errors.phone }}</p>
        </div>

        <div>
          <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">العنوان</label>
          <input v-model="form.address" class="input-field w-full" />
        </div>

        <div>
          <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">التشخيص</label>
          <textarea v-model="form.diagnosis" class="input-field w-full" rows="3"></textarea>
        </div>

        <div class="pt-4 flex justify-start gap-3 flex-row-reverse">
          <button type="submit" :disabled="saving" class="btn-primary !px-6">
            <span v-if="saving">جاري الحفظ...</span>
            <span v-else>حفظ</span>
          </button>
          <button type="button" @click="$emit('update:modelValue', false)" class="px-6 py-2 border border-primary-500 text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-lg text-sm font-bold transition-colors">
            إلغاء
          </button>
        </div>
      </form>
    </div>
  </WorkspaceModal>
</template>

<script setup>
const trace = (msg) => { try { fetch('/_native/api/debug/trace', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({message:msg})}).catch(()=>{}); } catch(e) {} }
import { ref, reactive } from 'vue'
import WorkspaceModal from './WorkspaceModal.vue'
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

const form = reactive({
  name: '', phone: '', address: '', diagnosis: ''
})

function resetForm() {
  Object.keys(form).forEach(k => form[k] = '')
  errors.value = {}
}

async function submit() {
  trace('[TRACE_P1] AddPatientModal.submit() ENTERED with form: ' + JSON.stringify({...form}))
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form }
    trace('[TRACE_P2] Calling addPatientToWorkspace() with payload: ' + JSON.stringify(payload))
    const result = await addPatientToWorkspace(payload)
    trace('[TRACE_P10] addPatientToWorkspace() returned: ' + JSON.stringify(result))
    if (result.success) {
      resetForm()
      emit('saved', result.patient)
      emit('update:modelValue', false)
      toast.success('تمت إضافة المريض بنجاح')
    } else {
      errors.value = result.errors || {}
      const serverMsg = result.errors?._server_error
      if (serverMsg) {
        toast.error(serverMsg)
        console.error('[AddPatient] Server error:', serverMsg, 'errorId:', result.errorId)
      } else {
        toast.error('حدث خطأ أثناء الإضافة')
      }
    }
  } catch (e) {
    if (e.response?.status === 422) {
      const errs = e.response.data?.errors || {}
      errors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
    }
    toast.error('حدث خطأ أثناء الإضافة')
  } finally {
    saving.value = false
  }
}
</script>
