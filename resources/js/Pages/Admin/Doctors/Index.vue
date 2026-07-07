<template>
  <AppLayout :title="$t('doctors.management')" :show-settings="true">
    <div class="max-w-7xl mx-auto space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 class="text-2xl font-bold font-heading text-slate-900 dark:text-white">{{ $t('doctors.management') }}</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Manage doctor accounts</p>
        </div>
        <Link href="/admin/doctors/create" class="px-4 py-2.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-xl shadow-sm hover:shadow transition-all flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          {{ $t('doctors.add_new') }}
        </Link>
      </div>

      <!-- Simple Table Card -->
      <BaseCard :padding="false">
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-start text-slate-500 dark:text-slate-400">
            <thead class="text-xs text-slate-700 dark:text-slate-300 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
              <tr>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Name</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Email</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Specialization</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Patients</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Status</th>
                <th scope="col" class="px-6 py-4 font-medium whitespace-nowrap">Last Login</th>
                <th scope="col" class="px-6 py-4 font-medium text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doctor in doctors.data" :key="doctor.id" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                <td class="px-6 py-4 font-medium text-slate-900 dark:text-white">{{ doctor.name }}</td>
                <td class="px-6 py-4 whitespace-nowrap">{{ doctor.email }}</td>
                <td class="px-6 py-4 whitespace-nowrap">{{ doctor.specialization || '-' }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-center">{{ doctor.patients_count }}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span :class="[
                    'px-2.5 py-1 rounded-full text-xs font-semibold',
                    doctor.status === 'active'
                      ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                      : 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400'
                  ]">
                    {{ doctor.status === 'active' ? $t('doctors.status_active') : $t('doctors.status_suspended') }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-slate-400 dark:text-slate-500">
                  {{ doctor.last_login_at ? new Date(doctor.last_login_at).toLocaleDateString() : $t('doctors.never') }}
                </td>
                <td class="px-6 py-4 text-end whitespace-nowrap">
                  <div class="flex items-center justify-end gap-2">
                    <Link :href="`/admin/doctors/${doctor.id}/edit`" class="px-2.5 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/30 hover:bg-primary-100 dark:hover:bg-primary-900/50 rounded-lg transition-colors">
                      {{ $t('common.edit') }}
                    </Link>
                    <button
                      @click="toggleStatus(doctor)"
                      :class="[
                        'px-2.5 py-1.5 text-xs font-medium rounded-lg transition-colors',
                        doctor.status === 'active'
                          ? 'text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-900/20 hover:bg-rose-100 dark:hover:bg-rose-900/30'
                          : 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/30'
                      ]"
                    >
                      {{ doctor.status === 'active' ? $t('doctors.suspend') : $t('doctors.activate') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Empty State -->
        <div v-if="doctors.data.length === 0" class="text-center py-12">
          <p class="text-slate-600 dark:text-slate-300">{{ $t('doctors.no_doctors_yet') }}</p>
        </div>

        <!-- Pagination -->
        <div v-if="doctors.data.length > 0" class="p-4 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between">
          <span class="text-sm text-slate-500 dark:text-slate-400">
            {{ $t('doctors.showing', { from: doctors.from, to: doctors.to, total: doctors.total }) }}
          </span>
          <div class="flex gap-2">
            <Link v-if="doctors.prev_page_url" :href="doctors.prev_page_url" class="px-3 py-1.5 text-sm font-medium bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors">
              {{ $t('doctors.previous') }}
            </Link>
            <Link v-if="doctors.next_page_url" :href="doctors.next_page_url" class="px-3 py-1.5 text-sm font-medium bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors">
              {{ $t('doctors.next') }}
            </Link>
          </div>
        </div>
      </BaseCard>
    </div>

    <!-- Status Change Confirmation Modal -->
    <Teleport to="body">
      <div v-if="showStatusConfirm" class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4" @click="cancelStatus">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl max-w-md w-full p-6" @click.stop>
          <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">{{ statusActionTitle }}</h3>
          <p class="text-slate-600 dark:text-slate-300 mb-6">{{ statusConfirmMessage }}</p>
          <div class="flex gap-3 justify-end">
            <button @click="cancelStatus" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 rounded-lg transition-colors">
              Cancel
            </button>
            <button
              @click="confirmStatusChange"
              :class="[
                'px-4 py-2 text-sm font-medium rounded-lg',
                pendingStatusChange?.status === 'active'
                  ? 'bg-rose-600 hover:bg-rose-700 text-white'
                  : 'bg-emerald-600 hover:bg-emerald-700 text-white'
              ]"
            >
              {{ statusActionTitle }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { debounce } from 'lodash-es';
import { useDialog } from '@/Composables/useDialog';
import { useToast } from '@/Composables/useToast';

const { t } = useI18n();
const page = usePage();
const dialog = useDialog();
const toast = useToast();

const props = defineProps({
  doctors: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search || '');
const showSearch = ref(false);
const openMenu = ref(null);
const showStatusConfirm = ref(false);
const pendingStatusChange = ref(null);

const debouncedSearch = debounce(() => {
  router.get('/admin/doctors', { search: search.value }, { preserveState: true, preserveScroll: true });
}, 300);

const toggleMenu = (id) => {
  openMenu.value = openMenu.value === id ? null : id;
};

const toggleStatus = (doctor) => {
  pendingStatusChange.value = doctor;
  showStatusConfirm.value = true;
};

const statusActionTitle = computed(() => {
  if (!pendingStatusChange.value) return '';
  return pendingStatusChange.value.status === 'active' ? t('doctors.suspend') : t('doctors.activate');
});

const statusConfirmMessage = computed(() => {
  if (!pendingStatusChange.value) return '';
  const isActivating = pendingStatusChange.value.status === 'suspended';
  return `${isActivating ? t('doctors.confirm_activate') : t('doctors.confirm_suspend')} ${pendingStatusChange.value.name}?`;
});

const confirmStatusChange = () => {
  if (pendingStatusChange.value) {
    router.post(`/admin/doctors/${pendingStatusChange.value.id}/suspend`, {}, {
      onSuccess: () => {
        toast.success('Doctor status updated');
        showStatusConfirm.value = false;
        pendingStatusChange.value = null;
      },
    });
  }
};

const cancelStatus = () => {
  showStatusConfirm.value = false;
  pendingStatusChange.value = null;
};

// Close menu on click outside
watch(openMenu, (val) => {
  if (val) {
    const handler = () => { openMenu.value = null; };
    document.addEventListener('click', handler);
    return () => document.removeEventListener('click', handler);
  }
});
</script>
