<template>
  <div class="card-surface overflow-hidden" :class="padding ? 'p-4 sm:p-5 md:p-6' : ''">
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
