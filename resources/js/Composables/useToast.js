import { ref } from 'vue';

const toasts = ref([])
let idCounter = 0

export function useToast() {
  function toast({ type = 'info', message, duration = 5000 }) {
    const id = ++idCounter
    toasts.value.push({ id, type, message, duration })
    if (duration > 0) {
      setTimeout(() => removeToast(id), duration)
    }
    return id
  }

  function success(message, duration) {
    return toast({ type: 'success', message, duration })
  }

  function error(message, duration) {
    return toast({ type: 'error', message, duration })
  }

  function warning(message, duration) {
    return toast({ type: 'warning', message, duration })
  }

  function info(message, duration) {
    return toast({ type: 'info', message, duration })
  }

  function removeToast(id) {
    const idx = toasts.value.findIndex(t => t.id === id)
    if (idx !== -1) toasts.value.splice(idx, 1)
  }

  return { toasts, toast, success, error, warning, info, removeToast }
}
