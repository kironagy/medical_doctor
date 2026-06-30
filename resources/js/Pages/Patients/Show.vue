<template>
  <AppLayout :title="patient.name">
    <!-- Read Only Banner -->
    <div v-if="!isPrimaryDoctor" class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-4 flex items-start gap-3">
      <div class="flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      </div>
      <div>
        <h3 class="text-sm font-medium text-amber-800 dark:text-amber-300">{{ $t('show.shared_patient') }}</h3>
        <div class="mt-1 text-sm text-amber-700 dark:text-amber-400">
          <p v-if="!canEdit">{{ $t('show.read_only_msg') }}</p>
          <p v-else>{{ $t('show.read_write_msg') }}</p>
        </div>
      </div>
    </div>

    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-3">
          <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">{{ patient.name }}</h1>
          <span v-if="!isPrimaryDoctor && !canEdit" class="px-2 py-1 bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 text-xs font-bold rounded uppercase tracking-wider">
            {{ $t('show.read_only') }}
          </span>
        </div>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ patient.code }} • {{ patient.phone || $t('show.no_phone') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <BaseButton v-if="canShare" variant="outline" @click="showShareModal = true">{{ $t('show.share') }}</BaseButton>
        <BaseButton v-if="canEdit" variant="primary" @click="router.visit(`/patients/${patient.uuid}/edit`)">{{ $t('patients.edit') }}</BaseButton>
      </div>
    </div>

    <!-- Basic Information -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8 bg-white dark:bg-slate-800 p-6 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
      <div>
        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1">{{ $t('patients.email') }}</p>
        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ patient.email || $t('show.not_provided') }}</p>
      </div>
      <div>
        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1">{{ $t('patients.address') }}</p>
        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ patient.address || $t('show.not_provided') }}</p>
      </div>
      <div>
        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1">{{ $t('patients.diagnosis') }}</p>
        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ patient.diagnosis || $t('patients.not_specified') }}</p>
      </div>
      <div>
        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1">{{ $t('show.created_at') }}</p>
        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ new Date(patient.created_at).toLocaleDateString() }}</p>
      </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="border-b border-slate-200 dark:border-slate-700 mb-6 overflow-x-auto hide-scrollbar">
      <nav class="-mb-px flex gap-0 min-w-max">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          class="whitespace-nowrap py-4 px-4 border-b-2 font-medium text-sm transition-colors"
          :class="[
            activeTab === tab.id
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600'
          ]"
        >
          {{ tab.name }}
        </button>
      </nav>
    </div>

    <!-- Active Tab Content -->
    <div class="mt-6">
      <BaseCard>
        <RichTextEditor
          :patientId="patient.id"
          :category="activeTab"
          :notes="filteredNotes"
          :disabled="!canEdit"
          @saved="reloadFiles"
          class="mb-8"
        />

        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ currentTabName }} {{ $t('show.files') }}</h2>
          <BaseButton v-if="canEdit" @click="$refs.fileManager.triggerUpload()">{{ $t('show.upload_file') }}</BaseButton>
        </div>

        <div v-if="filteredFiles.length === 0" class="text-center py-12 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl mb-6">
          <svg class="mx-auto h-12 w-12 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
          <h3 class="mt-2 text-sm font-medium text-slate-900 dark:text-white">{{ $t('show.no_files') }}</h3>
          <p v-if="canEdit" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t('show.upload_prompt') }}</p>
        </div>

        <FileManager
          ref="fileManager"
          :patientId="patient.uuid"
          :category="activeTab"
          :files="filteredFiles"
          :canEdit="canEdit"
          @uploaded="reloadFiles"
          @preview="handlePreview"
        />
      </BaseCard>
    </div>

    <!-- Shared Media Player -->
    <UnifiedMediaViewer :show="!!activeMedia" :file="activeMedia" @close="activeMedia = null" />

    <PatientShareModal
      :show="showShareModal"
      :patientUuid="patient.uuid"
      @close="showShareModal = false"
    />
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseCard from '@/Components/BaseCard.vue';
import BaseButton from '@/Components/BaseButton.vue';
import FileManager from '@/Components/FileManager.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import PatientShareModal from '@/Components/PatientShareModal.vue';
import UnifiedMediaViewer from '@/Components/UnifiedMediaViewer.vue';

const { t } = useI18n();

const props = defineProps({
  patient: Object,
  files: Array,
  notes: Array,
  permissions: Object
});

const page = usePage();
const currentUser = computed(() => page.props.auth.user);

const isPrimaryDoctor = computed(() => {
  return props.patient.primary_doctor_id === currentUser.value.id || currentUser.value.roles?.includes('super-admin');
});

const canEdit = computed(() => {
  if (isPrimaryDoctor.value) return true;
  return props.permissions?.can_edit === true;
});

const canShare = computed(() => {
  if (isPrimaryDoctor.value) return true;
  return props.permissions?.can_share === true;
});

const showShareModal = ref(false);
const activeMedia = ref(null);

const tabs = computed(() => [
  { id: 'medical_history', name: t('show.tab_medical_history') },
  { id: 'pre_op',         name: t('show.tab_pre_op') },
  { id: 'post_op',        name: t('show.tab_post_op') },
  { id: 'operation_sheet',name: t('show.tab_operation_sheet') },
  { id: 'medications',    name: t('show.tab_medications') },
  { id: 'notes',          name: t('show.tab_notes') },
]);

const activeTab = ref('medical_history');

const currentTabName = computed(() => {
  return tabs.value.find(t => t.id === activeTab.value)?.name || '';
});

const filteredFiles = computed(() => {
  return props.files.filter(file => file.category === activeTab.value);
});

const filteredNotes = computed(() => {
  return props.notes.filter(note => note.category === activeTab.value);
});

const reloadFiles = () => {
  router.reload({ only: ['files', 'notes'] });
};

const handlePreview = (file) => {
  activeMedia.value = file;
};

onMounted(() => {
  const params = new URLSearchParams(window.location.search);
  const openFileUuid = params.get('open_file');
  if (openFileUuid && props.files) {
    const targetFile = props.files.find(f => f.uuid === openFileUuid);
    if (targetFile) {
      activeTab.value = targetFile.category;
      activeMedia.value = targetFile;

      const url = new URL(window.location.href);
      url.searchParams.delete('open_file');
      window.history.replaceState({}, '', url.toString());
    }
  }
});
</script>

<style scoped>
.hide-scrollbar::-webkit-scrollbar {
  display: none;
}
.hide-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
