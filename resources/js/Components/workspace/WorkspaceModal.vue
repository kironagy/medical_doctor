<template>
  <BaseDialog
    :modelValue="modelValue"
    @update:modelValue="handleUpdate"
    @close="handleClose"
    :title="title"
    :size="size"
    :persistent="persistent"
  >
    <slot />
  </BaseDialog>
</template>

<script setup>
import BaseDialog from '@/Components/BaseDialog.vue'

defineProps({
  modelValue: Boolean,
  title: String,
  size: { type: String, default: 'md' },
  position: { type: String, default: 'center' },
  persistent: Boolean,
})

const emit = defineEmits(['update:modelValue', 'close'])

function handleUpdate(val) {
  emit('update:modelValue', val)
}
function handleClose() {
  emit('update:modelValue', false)
  emit('close')
}
</script>
