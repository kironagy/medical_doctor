<template>
  <WorkspaceModal :modelValue="modelValue" @update:modelValue="$emit('update:modelValue', $event)" @close="$emit('update:modelValue', false)" :title="$t('category_manager.title')" size="lg">
    <div class="space-y-4">
      <div class="space-y-2">
        <div v-for="(cat, idx) in categories" :key="cat.slug"
          class="flex items-center gap-2 p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800/50"
        >
          <div class="flex flex-col gap-0.5 text-slate-400">
            <button type="button" @click="moveUp(idx)" :disabled="idx === 0" class="disabled:opacity-30 hover:text-slate-600"><ChevronUpIcon class="w-3 h-3" /></button>
            <button type="button" @click="moveDown(idx)" :disabled="idx === categories.length - 1" class="disabled:opacity-30 hover:text-slate-600"><ChevronDownIcon class="w-3 h-3" /></button>
          </div>

          <div class="flex gap-1">
            <button v-for="c in colorOptions" :key="c" type="button"
              class="w-5 h-5 rounded-full border-2 transition-all shrink-0"
              :class="cat.color === c ? 'border-slate-900 dark:border-white scale-110' : 'border-transparent'"
              :style="{ backgroundColor: c }" @click="cat.color = c"
            />
          </div>

          <input v-model="cat.name" class="flex-1 input-field text-sm py-1 px-2" :placeholder="$t('category_manager.name_placeholder')" />

          <button type="button" class="p-1.5 rounded-lg transition-colors"
            :class="cat.is_visible !== false ? 'text-slate-400' : 'text-slate-300 dark:text-slate-600'"
            @click="cat.is_visible = !(cat.is_visible !== false)"
          >
            <EyeIcon v-if="cat.is_visible !== false" class="w-4 h-4" />
            <EyeSlashIcon v-else class="w-4 h-4" />
          </button>

          <button type="button" class="p-1.5 rounded-lg text-rose-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors" @click="removeCategory(idx)">
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div v-if="showAddForm" class="flex items-center gap-2 p-3 border border-dashed border-slate-300 dark:border-slate-600 rounded-xl bg-slate-50 dark:bg-slate-800/30">
        <input ref="newNameInput" v-model="newCatName" class="flex-1 input-field text-sm py-1 px-2" :placeholder="$t('category_manager.new_name_placeholder')"
          @keydown.enter.prevent="addCategory" />
        <BaseButton size="sm" variant="secondary" @click="addCategory">{{ $t('category_manager.add') }}</BaseButton>
        <BaseButton size="sm" variant="ghost" @click="cancelAdd">{{ $t('category_manager.cancel') }}</BaseButton>
      </div>

      <div class="flex items-center gap-3 pt-2">
        <BaseButton v-if="!showAddForm" variant="secondary" size="sm" @click="openAddForm">
          <PlusIcon class="w-4 h-4 me-1" /> {{ $t('category_manager.add_category') }}
        </BaseButton>
        <div class="flex-1" />
        <BaseButton :loading="saving" @click="save">{{ $t('category_manager.save_changes') }}</BaseButton>
      </div>
    </div>
  </WorkspaceModal>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue'
import axios from 'axios'
import WorkspaceModal from './WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import { ChevronUpIcon, ChevronDownIcon, EyeIcon, EyeSlashIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue: Boolean,
})
const emit = defineEmits(['update:modelValue', 'saved'])

const categories = ref([])
const saving = ref(false)
const showAddForm = ref(false)
const newNameInput = ref(null)
const newCatName = ref('')

const colorOptions = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#14b8a6', '#6b7280']

onMounted(() => fetchCategories())

async function fetchCategories() {
  try {
    const res = await axios.get('/api/v1/categories')
    categories.value = res.data
  } catch { console.error('Failed to load categories') }
}

function moveUp(idx) { if (idx > 0) { const a = categories.value; [a[idx - 1], a[idx]] = [a[idx], a[idx - 1]] } }
function moveDown(idx) { if (idx < categories.value.length - 1) { const a = categories.value; [a[idx], a[idx + 1]] = [a[idx + 1], a[idx]] } }
function removeCategory(idx) { categories.value.splice(idx, 1) }
function openAddForm() { newCatName.value = ''; showAddForm.value = true; nextTick(() => newNameInput.value?.focus()) }
function cancelAdd() { showAddForm.value = false }

function addCategory() {
  const name = newCatName.value.trim()
  if (!name) return
  const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'untitled'
  if (categories.value.some(c => c.slug === slug)) return
  categories.value.push({ slug, name, icon: 'folder', color: '#6b7280', order: categories.value.length + 1, is_visible: true })
  showAddForm.value = false
}

async function save() {
  saving.value = true
  try {
    const payload = categories.value.map((c, i) => ({
      slug: c.slug, name: c.name || c.slug, icon: c.icon || 'folder',
      color: c.color || '#6b7280', order: i + 1, is_visible: c.is_visible !== false,
    }))
    await axios.put('/api/v1/categories', { categories: payload })
    emit('saved')
    emit('update:modelValue', false)
  } catch { console.error('Failed to save categories') }
  finally { saving.value = false }
}
</script>
