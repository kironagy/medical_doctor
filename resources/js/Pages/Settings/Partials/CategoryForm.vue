<template>
  <BaseCard>
    <div class="space-y-6">
      <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4">
        <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white">
          {{ $t('settings.categories') }}
        </h2>
      </div>

      <div class="space-y-3">
        <div
          v-for="(cat, idx) in categories"
          :key="cat.slug"
          class="flex items-center gap-3 p-4 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800/50"
        >
          <button
            type="button"
            class="flex flex-col gap-0.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
            @click="moveUp(idx)"
            :disabled="idx === 0"
          >
            <ChevronUpIcon class="w-3 h-3" />
          </button>
          <button
            type="button"
            class="flex flex-col gap-0.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
            @click="moveDown(idx)"
            :disabled="idx === categories.length - 1"
          >
            <ChevronDownIcon class="w-3 h-3" />
          </button>

          <input
            :value="cat.slug"
            disabled
            class="w-32 text-xs font-mono text-slate-400 dark:text-slate-500 bg-transparent border-0 p-0"
          />

          <input
            v-model="cat.name"
            class="flex-1 input-field text-sm py-1.5 px-2.5"
            :placeholder="$t('settings.category_name')"
          />

          <div class="flex gap-1">
            <button
              v-for="c in colorOptions"
              :key="c"
              type="button"
              class="w-6 h-6 rounded-full border-2 transition-all shrink-0"
              :class="cat.color === c ? 'border-slate-900 dark:border-white scale-110' : 'border-transparent'"
              :style="{ backgroundColor: c }"
              @click="cat.color = c"
            />
          </div>

          <button
            type="button"
            class="p-1.5 rounded-lg transition-colors"
            :class="cat.is_visible ? 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300' : 'text-slate-300 dark:text-slate-600'"
            @click="cat.is_visible = !cat.is_visible"
          >
            <EyeIcon v-if="cat.is_visible" class="w-4 h-4" />
            <EyeSlashIcon v-else class="w-4 h-4" />
          </button>

          <button
            type="button"
            class="p-1.5 rounded-lg text-rose-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors"
            @click="removeCategory(idx)"
          >
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div v-if="showAddForm" class="flex items-center gap-3 p-4 border border-dashed border-slate-300 dark:border-slate-600 rounded-xl bg-slate-50 dark:bg-slate-800/30">
        <input
          ref="newNameInput"
          v-model="newCategory.name"
          class="flex-1 input-field text-sm py-1.5 px-2.5"
          :placeholder="$t('settings.new_category_name')"
          @keydown.enter.prevent="addCategory"
        />
        <BaseButton size="sm" variant="secondary" @click="addCategory">
          {{ $t('common.add') }}
        </BaseButton>
        <BaseButton size="sm" variant="ghost" @click="cancelAdd">
          {{ $t('common.cancel') }}
        </BaseButton>
      </div>

      <div class="flex items-center gap-3 pt-2">
        <BaseButton v-if="!showAddForm" variant="secondary" size="sm" @click="openAddForm">
          <PlusIcon class="w-4 h-4 me-1" />
          {{ $t('settings.add_category') }}
        </BaseButton>

        <div class="flex-1" />

        <span v-if="saved" class="text-sm text-emerald-600 dark:text-emerald-400 font-medium">
          {{ $t('settings.saved') }}
        </span>

        <BaseButton :loading="saving" @click="saveCategories">
          {{ $t('common.save') }}
        </BaseButton>
      </div>
    </div>
  </BaseCard>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue'
import axios from 'axios'
import BaseCard from '@/Components/BaseCard.vue'
import BaseButton from '@/Components/BaseButton.vue'
import {
  ChevronUpIcon,
  ChevronDownIcon,
  EyeIcon,
  EyeSlashIcon,
  TrashIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'

const categories = ref([])
const saving = ref(false)
const saved = ref(false)
const showAddForm = ref(false)
const newNameInput = ref(null)
const newCategory = ref({ name: '', slug: '', color: '#3b82f6', is_visible: true })

const colorOptions = [
  '#3b82f6', '#ef4444', '#10b981', '#f59e0b',
  '#8b5cf6', '#ec4899', '#06b6d4', '#14b8a6', '#6b7280',
]

function slugify(text) {
  return text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'untitled'
}

async function fetchCategories() {
  try {
    const res = await axios.get('/api/v1/categories')
    categories.value = res.data
  } catch (e) {
    console.error('Failed to load categories', e)
  }
}

function moveUp(idx) {
  if (idx <= 0) return
  const arr = categories.value
  ;[arr[idx - 1], arr[idx]] = [arr[idx], arr[idx - 1]]
}

function moveDown(idx) {
  if (idx >= categories.value.length - 1) return
  const arr = categories.value
  ;[arr[idx], arr[idx + 1]] = [arr[idx + 1], arr[idx]]
}

function removeCategory(idx) {
  categories.value.splice(idx, 1)
}

function openAddForm() {
  newCategory.value = { name: '', slug: '', color: '#3b82f6', is_visible: true }
  showAddForm.value = true
  nextTick(() => newNameInput.value?.focus())
}

function cancelAdd() {
  showAddForm.value = false
}

function addCategory() {
  const name = newCategory.value.name.trim()
  if (!name) return
  const slug = slugify(name)
  if (categories.value.some(c => c.slug === slug)) return
  categories.value.push({
    slug,
    name,
    icon: 'folder',
    color: newCategory.value.color,
    order: categories.value.length + 1,
    is_visible: true,
  })
  showAddForm.value = false
}

async function saveCategories() {
  saving.value = true
  saved.value = false
  try {
    const payload = categories.value.map((c, i) => ({
      slug: c.slug,
      name: c.name || c.slug,
      icon: c.icon || 'folder',
      color: c.color || '#6b7280',
      order: i + 1,
      is_visible: c.is_visible ?? true,
    }))
    await axios.put('/api/v1/categories', { categories: payload })
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  } catch (e) {
    console.error('Failed to save categories', e)
  } finally {
    saving.value = false
  }
}

onMounted(fetchCategories)
</script>
