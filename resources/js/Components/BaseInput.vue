<template>
  <div class="mb-4">
    <label v-if="label" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1" :for="id">
      {{ label }}
    </label>
    <div class="relative">
      <input
        :id="id"
        :type="type"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        :placeholder="placeholder"
        :required="required"
        class="input-field"
        :class="{ 'border-rose-500 focus:ring-rose-500 focus:border-rose-500 dark:border-rose-500 dark:focus:ring-rose-500': error }"
      />
      <slot name="icon"></slot>
    </div>
    <p v-if="error" class="mt-1 text-sm text-rose-500 dark:text-rose-400">{{ error }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: [String, Number],
  label: String,
  type: { type: String, default: 'text' },
  placeholder: String,
  error: String,
  required: Boolean,
});

const id = computed(() => `input-${Math.random().toString(36).substring(2, 9)}`);

defineEmits(['update:modelValue']);
</script>
