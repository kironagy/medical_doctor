<template>
  <div class="card-surface overflow-hidden" :class="padding ? 'p-6' : ''">
    <slot />
  </div>
</template>

<script setup>
defineProps({
  padding: {
    type: Boolean,
    default: true,
  }
});
</script>
