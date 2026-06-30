<template>
  <div class="mb-6">
    <template v-if="!disabled">
      <div class="border border-slate-300 dark:border-slate-600 rounded-xl overflow-hidden shadow-sm focus-within:ring-1 focus-within:ring-primary-500 focus-within:border-primary-500 dark:focus-within:ring-primary-400 dark:focus-within:border-primary-400">
        <!-- Toolbar -->
        <div class="bg-slate-50 dark:bg-slate-800 px-3 py-2 border-b border-slate-200 dark:border-slate-600 flex items-center gap-1 flex-wrap">
          <button type="button" @click="execCmd('bold')" class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded" title="Bold">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 12h9a4 4 0 014 4 4 4 0 01-4 4H6z" /></svg>
          </button>
          <button type="button" @click="execCmd('italic')" class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded" title="Italic">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 4h4M12 4v16M8 20h8" /></svg>
          </button>
          <button type="button" @click="execCmd('underline')" class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded" title="Underline">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12M7 8v8a5 5 0 0010 0V8" /></svg>
          </button>
          <div class="w-px h-6 bg-slate-300 dark:bg-slate-600 mx-1"></div>
          <button type="button" @click="execCmd('insertUnorderedList')" class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded" title="Bullet List">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>
          </button>
          <button type="button" @click="execCmd('insertOrderedList')" class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded" title="Numbered List">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>
          </button>
          <div class="w-px h-6 bg-slate-300 dark:bg-slate-600 mx-1"></div>
          <button type="button" @click="execCmd('formatBlock', 'h3')" class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded" title="Heading">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v8M8 8h8" /></svg>
          </button>
        </div>
        <!-- Editor -->
        <div
          ref="editorRef"
          contenteditable="true"
          class="w-full border-0 focus:ring-0 p-4 text-slate-900 dark:text-white bg-white dark:bg-slate-900 min-h-[150px] outline-none prose prose-slate dark:prose-invert max-w-none"
          :placeholder="$t('editor.placeholder') || 'Type a new note...'"
          @input="updateContent"
        ></div>
      </div>
      <div class="mt-3 flex justify-end">
        <BaseButton @click="saveNote" size="sm" :disabled="isSaving">
          <span v-if="isSaving">{{ $t('editor.saving') || 'Saving...' }}</span>
          <span v-else>{{ $t('editor.save_note') || 'Save Note' }}</span>
        </BaseButton>
      </div>
    </template>

    <!-- Read-only banner -->
    <div v-else class="bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center text-sm text-slate-500 dark:text-slate-400 italic mb-4">
      <p>{{ $t('editor.read_only') || 'You have Read Only access. Notes cannot be added.' }}</p>
    </div>

    <!-- Notes List -->
    <div v-if="notes.length > 0" class="mt-8 space-y-4">
      <div v-for="note in notes" :key="note.id" class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-700">
        <div class="flex justify-between items-center mb-2">
          <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ note.author?.name || $t('editor.doctor') || 'Doctor' }}</span>
          <span class="text-xs text-slate-400 dark:text-slate-500">{{ new Date(note.created_at).toLocaleString() }}</span>
        </div>
        <div class="text-sm text-slate-800 dark:text-slate-200 prose prose-slate dark:prose-invert max-w-none" v-html="note.content"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import BaseButton from '@/Components/BaseButton.vue';

const props = defineProps({
  patientId: [String, Number],
  category: String,
  notes: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false }
});

const emit = defineEmits(['saved']);
const content = ref('');
const isSaving = ref(false);
const editorRef = ref(null);

const execCmd = (cmd, value = null) => {
  document.execCommand(cmd, false, value);
  editorRef.value?.focus();
};

const updateContent = () => {
  content.value = editorRef.value?.innerHTML || '';
};

const saveNote = async () => {
  if (!content.value.trim() || isSaving.value) return;

  isSaving.value = true;
  try {
    await axios.post('/notes', {
      patient_id: props.patientId,
      category: props.category,
      content: content.value
    });
    content.value = '';
    editorRef.value.innerHTML = '';
    emit('saved');
  } catch (error) {
    console.error('Failed to save note', error);
  } finally {
    isSaving.value = false;
  }
};
</script>

<style scoped>
/* Add prose styles for rich text content */
.prose :where(p):not(:where([class~="not-prose"] *)) {
  margin-top: 0.5em;
  margin-bottom: 0.5em;
}
.prose :where(ul):not(:where([class~="not-prose"] *)) {
  margin-top: 0.5em;
  margin-bottom: 0.5em;
  padding-left: 1.5em;
  list-style-type: disc;
}
.prose :where(ol):not(:where([class~="not-prose"] *)) {
  margin-top: 0.5em;
  margin-bottom: 0.5em;
  padding-left: 1.5em;
  list-style-type: decimal;
}
.prose :where(h3):not(:where([class~="not-prose"] *)) {
  margin-top: 0.75em;
  margin-bottom: 0.5em;
  font-weight: 600;
  font-size: 1.25rem;
}
</style>
