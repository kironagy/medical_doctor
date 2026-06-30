<template>
  <Transition name="fade">
    <div v-if="state.isOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="handleBackdropClick"></div>
      
      <!-- Dialog Content -->
      <Transition name="slide-up" appear>
        <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col border dark:border-slate-800">
          <div class="p-6">
            <div class="flex items-start">
              <!-- Icon -->
              <div class="flex-shrink-0 flex items-center justify-center w-12 h-12 rounded-full me-4" :class="iconStyles.bg">
                <svg v-if="state.style === 'danger'" class="w-6 h-6" :class="iconStyles.text" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                <svg v-else-if="state.style === 'success'" class="w-6 h-6" :class="iconStyles.text" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                <svg v-else-if="state.style === 'warning'" class="w-6 h-6" :class="iconStyles.text" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <svg v-else class="w-6 h-6" :class="iconStyles.text" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              </div>
              
              <!-- Text -->
              <div class="flex-1 mt-1">
                <h3 class="text-lg font-heading font-bold text-slate-900 dark:text-white">{{ state.title }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">{{ state.message }}</p>
              </div>
            </div>
          </div>
          
          <!-- Actions -->
          <div class="bg-slate-50 dark:bg-slate-800/50 px-6 py-4 flex justify-end gap-3 rounded-b-2xl border-t border-slate-100 dark:border-slate-800">
            <button 
              v-if="state.type === 'confirm'" 
              @click="cancel" 
              class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200/50 dark:hover:bg-slate-700/50 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-slate-300"
            >
              {{ state.cancelText || $t('common.cancel') }}
            </button>
            <button 
              @click="confirm" 
              class="px-5 py-2 text-sm font-medium text-white rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 dark:focus:ring-offset-slate-900"
              :class="buttonStyles"
            >
              {{ state.confirmText || $t('common.confirm') }}
            </button>
          </div>
        </div>
      </Transition>
    </div>
  </Transition>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { useDialog } from '@/Composables/useDialog';

const { state, close } = useDialog();

const iconStyles = computed(() => {
  switch (state.value.style) {
    case 'danger': return { bg: 'bg-rose-100 dark:bg-rose-900/30', text: 'text-rose-600 dark:text-rose-400' };
    case 'success': return { bg: 'bg-emerald-100 dark:bg-emerald-900/30', text: 'text-emerald-600 dark:text-emerald-400' };
    case 'warning': return { bg: 'bg-amber-100 dark:bg-amber-900/30', text: 'text-amber-600 dark:text-amber-400' };
    case 'info':
    default: return { bg: 'bg-primary-100 dark:bg-primary-900/30', text: 'text-primary-600 dark:text-primary-400' };
  }
});

const buttonStyles = computed(() => {
  switch (state.value.style) {
    case 'danger': return 'bg-rose-600 hover:bg-rose-700 focus:ring-rose-500 shadow-sm shadow-rose-200 dark:shadow-none dark:focus:ring-rose-700';
    case 'success': return 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-500 shadow-sm shadow-emerald-200 dark:shadow-none dark:focus:ring-emerald-700';
    case 'warning': return 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-500 shadow-sm shadow-amber-200 dark:shadow-none dark:focus:ring-amber-700';
    case 'info':
    default: return 'bg-primary-600 hover:bg-primary-700 focus:ring-primary-500 shadow-sm shadow-primary-200 dark:shadow-none dark:focus:ring-primary-700';
  }
});

const confirm = () => close(true);
const cancel = () => close(false);

const handleBackdropClick = () => {
  if (state.value.type === 'alert') {
    close(true);
  } else {
    close(false);
  }
};

const handleKeydown = (e) => {
  if (!state.value.isOpen) return;
  if (e.key === 'Escape') cancel();
  if (e.key === 'Enter') confirm();
};

onMounted(() => {
  document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown);
});
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.95);
}
</style>
