<template>
  <BaseCard>
    <form @submit.prevent="submit" class="space-y-6">
      <h2 class="text-lg font-heading font-semibold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
        {{ $t('settings.profile') }}
      </h2>
      
      <!-- Avatar Section -->
      <div class="flex items-center space-x-6 rtl:space-x-reverse mb-6">
        <div class="relative group">
          <img 
            :src="avatarPreview || $page.props.auth.user.avatar_url" 
            class="w-24 h-24 rounded-full object-cover border-4 border-white dark:border-slate-800 shadow-sm"
          />
          <div class="absolute inset-0 bg-black/40 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer" @click="triggerFileInput">
            <CameraIcon class="w-6 h-6 text-white" />
          </div>
          <input type="file" ref="fileInput" class="hidden" accept="image/*" @change="onFileChange" />
        </div>
        
        <div>
          <BaseButton type="button" variant="secondary" size="sm" class="mb-2" @click="triggerFileInput">
            {{ $t('settings.upload_avatar') }}
          </BaseButton>
          <div v-if="$page.props.auth.user.avatar_path || avatarPreview" class="mt-1">
            <button type="button" @click="removeAvatar" class="text-xs text-rose-500 hover:text-rose-700 font-medium">
              {{ $t('settings.remove_avatar') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Cropper Modal -->
      <BaseDialog v-model="showCropper" :title="$t('settings.upload_avatar')" size="md">
        <div class="w-full bg-black rounded-lg overflow-hidden flex items-center justify-center">
          <img ref="cropperImage" :src="rawImageUrl" class="max-w-full max-h-full block" />
        </div>
        <div class="mt-4 flex justify-end space-x-3 rtl:space-x-reverse">
          <BaseButton type="button" variant="ghost" @click="cancelCrop">{{ $t('common.cancel') }}</BaseButton>
          <BaseButton type="button" @click="applyCrop">{{ $t('settings.upload_avatar') }}</BaseButton>
        </div>
      </BaseDialog>

      <!-- Profile Form -->
      <BaseInput
        v-model="form.name"
        :label="$t('settings.name')"
        required
        :error="form.errors.name"
      />

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <BaseInput
          v-model="form.phone"
          :label="$t('settings.phone')"
          :error="form.errors.phone"
        />
        <BaseInput
          v-model="form.email"
          :label="$t('settings.email')"
          type="email"
          required
          :error="form.errors.email"
        />
      </div>

      <div class="pt-4 flex justify-end">
        <BaseButton type="submit" :loading="form.processing">{{ $t('settings.save_changes') }}</BaseButton>
      </div>
    </form>
  </BaseCard>
</template>

<script setup>
import { ref, onUnmounted } from 'vue';
import { useForm, usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseCard from '@/Components/BaseCard.vue';
import BaseInput from '@/Components/BaseInput.vue';
import BaseButton from '@/Components/BaseButton.vue';
import BaseDialog from '@/Components/BaseDialog.vue';
import { useDialog } from '@/Composables/useDialog';
import { CameraIcon } from '@heroicons/vue/24/solid';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

const { t } = useI18n();
const page = usePage();
const user = page.props.auth.user;
const dialog = useDialog();

const form = useForm({
  name: user.name,
  phone: user.phone || '',
  email: user.email,
  avatar: null,
});

const fileInput = ref(null);
const avatarPreview = ref(null);

// Cropper state
const showCropper = ref(false);
const rawImageUrl = ref(null);
const cropperImage = ref(null);
let cropperInstance = null;

const triggerFileInput = () => {
  fileInput.value.click();
};

const onFileChange = (e) => {
  const file = e.target.files[0];
  if (!file) return;

  if (rawImageUrl.value) {
    URL.revokeObjectURL(rawImageUrl.value);
  }
  rawImageUrl.value = URL.createObjectURL(file);
  showCropper.value = true;
  
  // Need to wait for DOM update to init cropper
  setTimeout(() => {
    if (cropperInstance) cropperInstance.destroy();
    cropperInstance = new Cropper(cropperImage.value, {
      aspectRatio: 1,
      viewMode: 1,
      dragMode: 'move',
      autoCropArea: 1,
      restore: false,
      guides: false,
      center: false,
      highlight: false,
      cropBoxMovable: true,
      cropBoxResizable: true,
      toggleDragModeOnDblclick: false,
    });
  }, 100);
};

const cancelCrop = () => {
  showCropper.value = false;
  if (cropperInstance) {
    cropperInstance.destroy();
    cropperInstance = null;
  }
  fileInput.value.value = '';
};

const applyCrop = () => {
  if (!cropperInstance) return;
  
  cropperInstance.getCroppedCanvas({
    width: 400,
    height: 400,
  }).toBlob((blob) => {
    const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
    form.avatar = file;
    
    if (avatarPreview.value) {
      URL.revokeObjectURL(avatarPreview.value);
    }
    avatarPreview.value = URL.createObjectURL(blob);
    
    cancelCrop();
  }, 'image/jpeg', 0.9);
};

const removeAvatar = async () => {
  const confirmed = await dialog.confirm({
    title: t('settings.remove_avatar_title'),
    message: t('settings.remove_avatar_confirm'),
    confirmText: t('settings.remove_avatar'),
    style: 'warning',
  })
  if (!confirmed) return
  router.delete('/settings/avatar', {
    preserveScroll: true,
    onSuccess: () => {
      avatarPreview.value = null;
      form.avatar = null;
    }
  });
};

const submit = () => {
  // Inertia doesn't support PUT with FormData out of the box nicely unless _method is used
  // We'll use POST and append _method=PUT to let Laravel know it's an update.
  
  const payload = new FormData();
  payload.append('_method', 'POST'); // Actually we mapped the route to POST /settings/profile in web.php
  payload.append('name', form.name);
  payload.append('email', form.email);
  payload.append('phone', form.phone);
  if (form.avatar) {
    payload.append('avatar', form.avatar);
  }

  router.post('/settings/profile', payload, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      form.clearErrors();
    },
    onError: (errors) => {
      form.setError(errors);
    }
  });
};

onUnmounted(() => {
  if (rawImageUrl.value) URL.revokeObjectURL(rawImageUrl.value);
  if (avatarPreview.value) URL.revokeObjectURL(avatarPreview.value);
  if (cropperInstance) cropperInstance.destroy();
});
</script>
