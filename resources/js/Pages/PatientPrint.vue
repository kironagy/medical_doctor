<template>
  <div class="print-container">
    <div class="no-print text-center py-4 border-b bg-white sticky top-0 z-50 shadow-sm">
      <button @click="handlePrint" class="px-6 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 mx-2">{{ $t('workspace.print_record') }}</button>
      <button @click="handleDownload" class="px-6 py-2 bg-slate-100 text-slate-700 rounded-lg text-sm font-medium hover:bg-slate-200 mx-2">{{ $t('workspace.export_pdf') }}</button>
      <button @click="handleClose" class="px-6 py-2 text-slate-500 rounded-lg text-sm font-medium hover:bg-slate-100 mx-2">{{ $t('common.close') }}</button>
    </div>

    <div class="max-w-4xl mx-auto px-8 py-8">
      <!-- Header -->
      <div class="text-center mb-8 border-b pb-6">
        <h1 class="text-2xl font-bold text-slate-900">{{ patient.name }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ patient.code }} | {{ patient.phone || '—' }} | {{ patient.email || '—' }}</p>
        <p class="text-xs text-slate-400 mt-1">{{ $t('patients.information') }}</p>
      </div>

      <!-- Patient Info -->
      <div class="grid grid-cols-2 gap-4 mb-8">
        <div v-if="patient.date_of_birth" class="print-field">
          <span class="print-label">{{ $t('patients.full_name') }}</span>
          <span class="print-value">{{ patient.name }}</span>
        </div>
        <div v-if="patient.date_of_birth" class="print-field">
          <span class="print-label">{{ $t('settings.profile') }}</span>
          <span class="print-value">{{ patient.gender ? patient.gender + ' • ' : '' }}{{ patient.date_of_birth ? new Date(patient.date_of_birth).toLocaleDateString() : '—' }}{{ patient.age ? ' • Age: ' + patient.age : '' }}</span>
        </div>
        <div v-if="patient.blood_group" class="print-field">
          <span class="print-label">Blood Group</span>
          <span class="print-value">{{ patient.blood_group }}</span>
        </div>
        <div v-if="patient.weight" class="print-field">
          <span class="print-label">Weight</span>
          <span class="print-value">{{ patient.weight }} kg</span>
        </div>
        <div v-if="patient.height" class="print-field">
          <span class="print-label">Height</span>
          <span class="print-value">{{ patient.height }} cm</span>
        </div>
        <div v-if="patient.allergies" class="print-field col-span-2">
          <span class="print-label">Allergies</span>
          <span class="print-value">{{ patient.allergies }}</span>
        </div>
        <div v-if="patient.chronic_diseases" class="print-field col-span-2">
          <span class="print-label">Chronic Diseases</span>
          <span class="print-value">{{ patient.chronic_diseases }}</span>
        </div>
        <div v-if="patient.diagnosis" class="print-field col-span-2">
          <span class="print-label">{{ $t('patients.diagnosis') }}</span>
          <span class="print-value">{{ patient.diagnosis }}</span>
        </div>
      </div>

      <!-- Files -->
      <div v-if="files.length > 0" class="mb-8">
        <h2 class="text-lg font-bold text-slate-900 border-b pb-2 mb-3">Medical Files ({{ files.length }})</h2>
        <table class="print-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Category</th>
              <th>Uploaded</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in files" :key="f.id">
              <td>{{ f.title || f.file_name || '—' }}</td>
              <td>{{ f.category || '—' }}</td>
              <td>{{ new Date(f.created_at).toLocaleDateString() }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Notes -->
      <div v-if="notes.length > 0" class="mb-8">
        <h2 class="text-lg font-bold text-slate-900 border-b pb-2 mb-3">Clinical Notes ({{ notes.length }})</h2>
        <div v-for="n in notes" :key="n.id" class="mb-3 pb-3 border-b border-slate-100">
          <p class="text-xs text-slate-400 mb-1">{{ n.author?.name || 'Doctor' }} — {{ new Date(n.created_at).toLocaleString() }}</p>
          <p class="text-sm text-slate-700" v-html="n.content"></p>
        </div>
      </div>

      <!-- Visits -->
      <div v-if="visits.length > 0" class="mb-8">
        <h2 class="text-lg font-bold text-slate-900 border-b pb-2 mb-3">Visits ({{ visits.length }})</h2>
        <table class="print-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in visits" :key="v.id">
              <td>{{ new Date(v.visit_date || v.created_at).toLocaleDateString() }}</td>
              <td>{{ v.visit_type || '—' }}</td>
              <td>{{ v.reason || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Footer -->
      <div class="text-center text-xs text-slate-400 border-t pt-4 mt-8">
        <p>Exported by: {{ exportedBy }}</p>
        <p>Date: {{ new Date(exportedAt).toLocaleString() }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  patient: Object,
  files: Array,
  notes: Array,
  visits: Array,
  exportedAt: String,
  exportedBy: String,
  doctorName: String,
})

function handlePrint() {
  window.print()
}

function handleDownload() {
  window.print()
}

function handleClose() {
  window.close()
}
</script>

<style>
@media print {
  .no-print { display: none !important; }
  .print-container { padding: 0 !important; }
  body { background: white !important; }
  @page { margin: 1.5cm; }
}

body {
  background: #f8fafc;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.print-container {
  background: white;
  min-height: 100vh;
}

.print-field {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.print-label {
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.print-value {
  font-size: 14px;
  color: #1e293b;
}

.print-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.print-table th {
  text-align: left;
  padding: 8px 12px;
  background: #f1f5f9;
  color: #64748b;
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-bottom: 2px solid #e2e8f0;
}

.print-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #f1f5f9;
  color: #334155;
}

@media print {
  .no-print { display: none !important; }
}
</style>
