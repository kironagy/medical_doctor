import { ref } from 'vue';

const dialogState = ref({
  isOpen: false,
  type: 'confirm', // 'confirm', 'alert'
  style: 'info', // 'info', 'danger', 'success', 'warning'
  title: '',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  resolve: null,
  reject: null
});

export function useDialog() {
  const confirm = ({ title, message, confirmText = 'Confirm', cancelText = 'Cancel', style = 'info' }) => {
    return new Promise((resolve) => {
      dialogState.value = {
        isOpen: true,
        type: 'confirm',
        style,
        title,
        message,
        confirmText,
        cancelText,
        resolve,
        reject: null
      };
    });
  };

  const alert = ({ title, message, confirmText = 'OK', style = 'info' }) => {
    return new Promise((resolve) => {
      dialogState.value = {
        isOpen: true,
        type: 'alert',
        style,
        title,
        message,
        confirmText,
        cancelText: '',
        resolve,
        reject: null
      };
    });
  };

  const close = (result = false) => {
    dialogState.value.isOpen = false;
    if (dialogState.value.resolve) {
      dialogState.value.resolve(result);
    }
    // Small delay to allow animation to finish before clearing
    setTimeout(() => {
      dialogState.value.resolve = null;
    }, 300);
  };

  return {
    state: dialogState,
    confirm,
    alert,
    close
  };
}
