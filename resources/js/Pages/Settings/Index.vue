<template>
  <AppLayout title="Settings">
    <div class="max-w-7xl mx-auto space-y-6">
      <ProfileForm />
      <PasswordForm />
      <PreferencesForm />
      <CategoryForm />
      <DownloadAppForm />
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import ProfileForm from '@/Pages/Settings/Partials/ProfileForm.vue';
import PasswordForm from '@/Pages/Settings/Partials/PasswordForm.vue';
import PreferencesForm from '@/Pages/Settings/Partials/PreferencesForm.vue';
import CategoryForm from '@/Pages/Settings/Partials/CategoryForm.vue';
import DownloadAppForm from '@/Pages/Settings/Partials/DownloadAppForm.vue';
</script>
