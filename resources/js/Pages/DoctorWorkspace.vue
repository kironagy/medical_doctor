<template>
  <div class="h-[100dvh] flex bg-slate-50 dark:bg-slate-950 overflow-hidden" @keydown.escape="closeAllMenus" @touchstart="handleTouchStart" @touchmove="handleTouchMove" @touchend="handleTouchEnd" @touchcancel="handleTouchEnd">
    <!-- Sidebar Overlay (Mobile) -->
    <div v-if="mobilePatientListOpen && isMobile" class="fixed inset-0 bg-slate-900/50 z-30" @click="mobilePatientListOpen = false"></div>

    <!-- Sidebar -->
    <div
      v-show="!isMobile || mobilePatientListOpen"
      class="flex-shrink-0 z-100 transition-all duration-300"
      :class="[
        isMobile && mobilePatientListOpen ? 'fixed inset-y-0 left-0 w-[300px]' : '',
        !isMobile ? 'hidden md:block' : '',
        !isMobile && sidebarOpen ? 'w-[300px] lg:w-[320px]' : '',
        !isMobile && !sidebarOpen ? 'w-0 overflow-hidden' : ''
      ]"
    >
      <PatientListSidebar
        :user="user"
        :mobileOpen="mobilePatientListOpen"
        :collapsed="!isMobile && !sidebarOpen"
        @close="mobilePatientListOpen = false"
        @add-patient="showAddPatient = true"
      />
    </div>

    <!-- Main Workspace -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Header -->
      <WorkspaceHeader
        @toggle-patients="mobilePatientListOpen = !mobilePatientListOpen"
        @toggle-actions="toggleActionMenu"
      />

      <!-- Three-dot Dropdown -->
      <Teleport to="body">
        <div v-if="showActionMenu && selectedPatient" class="fixed inset-0 z-[200]" @click="showActionMenu = false"></div>
        <div
          v-if="showActionMenu && selectedPatient"
          class="fixed z-[200] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl py-1.5 min-w-[200px]"
          :style="actionMenuStyle"
        >
          <button @click="openEditPatient" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            {{ $t('workspace.edit_patient') }}
          </button>
          <button @click="openShareModal" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
            {{ $t('workspace.share') }}
          </button>
          <button @click="handlePrint" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
            {{ $t('workspace.print_record') }}
          </button>
          <button @click="handleExport" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-start">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
            {{ $t('workspace.export_pdf') }}
          </button>
          <hr class="my-1 border-slate-100 dark:border-slate-700" />
          <button v-if="selectedPatient?.status === 'archived'" @click="handleRestore" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition-colors text-start">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
            {{ $t('common.restore') }}
          </button>
          <button v-else @click="handleArchive" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-start">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
            {{ $t('workspace.archive') }}
          </button>
          <button v-if="selectedPatient?.status !== 'archived'" @click="handleDelete" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-start">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            {{ $t('workspace.delete') }}
          </button>
        </div>
      </Teleport>

      <!-- PTR Indicator (fixed at viewport top) -->
      <div v-if="ptrVisible" class="fixed left-0 right-0 flex flex-col items-center justify-center pointer-events-none z-50" style="top:0;height:64px;padding-top:8px" :style="{ transform: `translateY(${pullDistance - 64}px)` }">
        <div class="w-9 h-9 rounded-full bg-white dark:bg-slate-800 shadow-lg flex items-center justify-center text-primary-600 dark:text-primary-400" :style="{ transform: `scale(${ptrScale})`, opacity: ptrOpacity }">
          <svg v-if="!isRefreshing" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" opacity="0.15"/>
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :stroke-dasharray="`${ptrArcLen} ${ptrCircumference}`" transform="rotate(-90 12 12)"/>
            <g :style="{ transform: `rotate(${ptrArrowRotation}deg)`, transformOrigin: '12px 12px' }">
              <path d="M12 5 L12 17 M8 13 L12 17 L16 13" stroke-linejoin="round"/>
            </g>
          </svg>
          <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" class="animate-spin">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-dasharray="35 75" stroke-linecap="round" transform="rotate(-90 12 12)"/>
          </svg>
        </div>
        <span v-if="thresholdReached && !isRefreshing" class="text-xs font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap mt-0.5">{{ $t('workspace.release_to_refresh') || 'Release to refresh' }}</span>
        <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap mt-0.5">{{ $t('workspace.refreshing') || 'Refreshing' }}</span>
      </div>
      <!-- Scrollable Content -->
      <div ref="scrollContainer" class="flex-1 overflow-y-auto overscroll-contain" :class="isMobile ? 'pb-20' : ''" :style="ptrContentStyle">
        <!-- Settings Panel -->
        <div v-if="showSettings">
          <WorkspaceSettings />
        </div>

        <!-- Loading Skeleton -->
        <div v-else-if="loadingPatient" class="max-w-4xl mx-auto px-3 md:px-6 py-4 md:py-6 space-y-5">
          <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-5 animate-pulse">
            <div class="flex items-center gap-4">
              <div class="w-16 h-16 rounded-full bg-slate-200 dark:bg-slate-700"></div>
              <div class="flex-1 space-y-3">
                <div class="h-5 bg-slate-200 dark:bg-slate-700 rounded w-1/3"></div>
                <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-1/2"></div>
              </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 mt-4">
              <div v-for="i in 15" :key="i" class="h-12 bg-slate-200 dark:bg-slate-700 rounded-lg"></div>
            </div>
          </div>
          <div class="flex gap-2">
            <div v-for="i in 6" :key="i" class="h-9 w-24 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
          </div>
          <div v-for="i in 3" :key="i" class="h-24 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
        </div>

        <!-- No Patient Selected -->
        <div v-else-if="!selectedPatient" class="flex items-center justify-center h-64 px-4">
          <div class="text-center">
            <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center">
              <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
            </div>
            <h3 class="text-lg font-bold font-heading text-slate-900 dark:text-white mb-1">{{ $t('workspace.select_patient') }}</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $t('workspace.select_patient_desc') }}</p>
            <button @click="mobilePatientListOpen = true" class="md:hidden mt-4 px-5 py-2.5 bg-primary-600 text-white rounded-xl text-sm font-medium active:scale-95 transition-all">
              {{ $t('workspace.view_patient_list') }}
            </button>
          </div>
        </div>

        <!-- Workspace Content -->
        <div v-else class="max-w-4xl mx-auto px-3 md:px-6 py-4 md:py-6 space-y-5">
          <!-- Section 1: Patient Summary (3-second understanding) -->
          <div ref="summaryRef" class="workspace-section">
            <PatientSummary :patient="currentPatient" :isPrimaryDoctor="isPrimaryDoctor" @action="toggleActionMenu" />
          </div>

          <!-- Section 2: Quick Actions -->
          <div ref="actionsRef" v-if="selectedPatient" class="workspace-section">
            <div class="flex items-center gap-2 mb-3">
              <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.quick_actions') }}</h3>
            </div>
            <QuickActions
              @share="openShareModal"
              @appointment="scrollToSection('appointments')"
              @notes="scrollToSection('notes')"
              @upload="scrollToSection('records')"
              @history="scrollToSection('timeline')"
              @categories="showCategoryManager = true"
            />
          </div>

          <!-- Section 3: Appointments -->
          <div ref="appointmentsRef" class="workspace-section bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.appointments') }}</h3>
                <span class="text-[11px] text-slate-400">({{ upcomingVisits.length }} {{ $t('workspace.upcoming') }})</span>
              </div>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
              <div v-for="visit in upcomingVisits" :key="visit.id" class="px-4 py-3 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 flex items-center justify-center flex-shrink-0">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-slate-900 dark:text-white">{{ visit.visit_type || $t('workspace.visit') }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ visit.reason || '—' }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-xs font-medium text-primary-600 dark:text-primary-400">{{ new Date(visit.visit_date || visit.created_at).toLocaleDateString() }}</p>
                </div>
              </div>
              <div v-if="upcomingVisits.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">{{ $t('workspace.no_appointments') }}</div>
            </div>
          </div>

          <!-- Section 4: Recent Visits -->
          <div ref="visitsRef" class="workspace-section bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.recent_visits') }}</h3>
                <span class="text-[11px] text-slate-400">({{ pastVisits.length }})</span>
              </div>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
              <div v-for="visit in pastVisits" :key="visit.id" class="px-4 py-3 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 flex items-center justify-center flex-shrink-0">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-slate-900 dark:text-white">{{ visit.visit_type || $t('workspace.visit') }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ visit.reason || '—' }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ new Date(visit.visit_date || visit.created_at).toLocaleDateString() }}</p>
                  <p v-if="visit.next_visit_date" class="text-[10px] text-primary-600 dark:text-primary-400">{{ $t('workspace.next') }}: {{ new Date(visit.next_visit_date).toLocaleDateString() }}</p>
                </div>
              </div>
              <div v-if="pastVisits.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">{{ $t('workspace.no_visits') }}</div>
            </div>
          </div>

          <!-- Section 5: Dynamic Categories (lazy loaded on scroll) -->
          <div ref="recordsRef" class="workspace-section space-y-3">
            <div class="flex items-center justify-between mb-1">
              <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.medical_records') }}</h3>
              <button @click="showCategoryManager = true" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>
                {{ $t('workspace.manage_categories') }}
              </button>
            </div>
            <CategoryBlock
              v-for="cat in categories"
              :key="cat.slug"
              :slug="cat.slug"
              :name="cat.name"
              :icon="getCategoryIcon(cat.icon)"
              :color="cat.color || '#6b7280'"
              :allCategories="categories"
            />
          </div>

          <!-- Section 7: Notes -->
          <div ref="notesRef" class="workspace-section bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.notes') }}</h3>
                <span class="text-[11px] text-slate-400">({{ allNotes.length }})</span>
              </div>
              <button v-if="canEdit" @click="showAddNote = true" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                {{ $t('workspace.add_note') }}
              </button>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800 max-h-72 overflow-y-auto">
              <div v-for="note in allNotes" :key="note.id" class="px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-[11px] font-medium text-slate-600 dark:text-slate-300">{{ note.author?.name || 'Doctor' }}</span>
                  <div class="flex items-center gap-2">
                    <span v-if="note.category" class="text-[10px] px-1.5 py-0.5 rounded font-medium bg-slate-100 dark:bg-slate-800 text-slate-500">{{ note.category.replace(/_/g, ' ') }}</span>
                    <span class="text-[10px] text-slate-400">{{ new Date(note.created_at).toLocaleDateString() }}</span>
                    <div v-if="canEdit" class="opacity-0 group-hover:opacity-100 flex items-center gap-1">
                      <button @click="editNote(note)" class="p-1 text-slate-400 hover:text-primary-600 transition-colors" title="Edit">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                      </button>
                      <button @click="deleteNote(note)" class="p-1 text-slate-400 hover:text-rose-600 transition-colors" title="Delete">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                      </button>
                    </div>
                  </div>
                </div>
                <div class="text-xs text-slate-700 dark:text-slate-300 prose prose-slate dark:prose-invert max-w-none line-clamp-3" v-html="note.content"></div>
              </div>
              <div v-if="allNotes.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">{{ $t('workspace.no_notes') }}</div>
            </div>
          </div>

          <!-- Section 8: Patient Sharing -->
          <div ref="sharingRef" class="workspace-section bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.patient_sharing') }}</h3>
              </div>
              <button v-if="canShare" @click="openShareModal" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                {{ $t('workspace.share_btn') }}
              </button>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
              <div v-for="share in shares" :key="share.id" class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 flex items-center justify-center text-xs font-bold flex-shrink-0">{{ share.doctor?.name?.charAt(0) || 'D' }}</div>
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ share.doctor?.name || 'Doctor' }}</p>
                    <p class="text-xs text-slate-500">{{ share.access_level === 'read_write' ? $t('patients.read_write') : $t('patients.read_only') }}</p>
                    <p class="text-[10px] text-slate-400">{{ $t('patients.shared_date') }} {{ new Date(share.created_at).toLocaleDateString() }}</p>
                  </div>
                </div>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                  <button v-if="canShare" @click="editSharePermission(share)" class="text-[11px] font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 px-2 py-1 rounded hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors">
                    {{ $t('patients.edit_permission') }}
                  </button>
                  <button @click="removeShare(share)" class="text-[11px] font-medium text-rose-600 dark:text-rose-400 hover:text-rose-700 px-2 py-1 rounded hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors">
                    {{ $t('common.revoke') }}
                  </button>
                </div>
              </div>
              <div v-if="shares.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">{{ $t('workspace.not_shared') }}</div>
            </div>
          </div>

          <!-- Section 9: Timeline (lazy loaded) -->
          <div ref="timelineRef" class="workspace-section bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                  <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">{{ $t('workspace.timeline') }}</h3>
              </div>
            </div>
            <div class="p-4 space-y-3 max-h-96 overflow-y-auto" ref="timelineScrollRef" @scroll="onTimelineScroll">
              <div v-for="event in displayedTimeline" :key="event.id" class="flex gap-3">
                <div class="flex flex-col items-center flex-shrink-0">
                  <div class="w-2.5 h-2.5 rounded-full mt-1.5" :class="event.type === 'file' ? 'bg-emerald-500' : event.type === 'note' ? 'bg-amber-500' : 'bg-blue-500'"></div>
                  <div class="w-px flex-1 bg-slate-200 dark:bg-slate-700 mt-1"></div>
                </div>
                <div class="flex-1 pb-3">
                  <p class="text-xs font-medium text-slate-900 dark:text-white">{{ event.title }}</p>
                  <p class="text-[11px] text-slate-500 mt-0.5">{{ event.description }}</p>
                  <p class="text-[10px] text-slate-400 mt-0.5">{{ event.date }}</p>
                </div>
              </div>
              <div v-if="timelineLoading" class="text-center py-4">
                <div class="w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
              </div>
              <div v-if="!timelineHasMore && displayedTimeline.length > 0" class="text-center text-xs text-slate-400 py-2">{{ $t('workspace.timeline_end') }}</div>
              <div v-if="displayedTimeline.length === 0" class="text-center text-sm text-slate-400 py-4">{{ $t('workspace.no_timeline') }}</div>
            </div>
          </div>

          <!-- Section 10: Archive -->
          <div ref="archiveRef" class="workspace-section text-center pb-4 space-y-3">
            <template v-if="selectedPatient">
              <button v-if="selectedPatient.status === 'archived'" @click="handleRestore" class="px-6 py-2.5 text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-800 transition-colors inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                {{ $t('common.restore') }}
              </button>
              <button v-else @click="handleArchive" class="px-6 py-2.5 text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-xl border border-rose-200 dark:border-rose-800 transition-colors inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
                {{ $t('workspace.archive_patient') }}
              </button>
            </template>
            <div class="text-xs text-slate-400">{{ $t('workspace.show_archived') }} — <button @click="toggleShowArchived" class="text-primary-600 dark:text-primary-400 hover:underline">{{ $t('workspace.archived_patients') }}</button></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile Bottom Bar -->
    <MobileBottomBar
      @toggle-patients="mobilePatientListOpen = !mobilePatientListOpen"
      @scroll-to="scrollToSection"
    />

    <!-- Inline Preview -->
    <InlineFilePreview />

    <!-- Note Modal -->
    <WorkspaceModal :modelValue="showNoteModal" @update:modelValue="showNoteModal = false" :title="editingNote ? $t('workspace.edit_note') : $t('workspace.add_note')" size="sm">
      <form @submit.prevent="submitNoteForm" class="space-y-4">
        <textarea v-model="noteFormContent" class="input-field w-full" rows="4" :placeholder="$t('workspace.note_placeholder')" required></textarea>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showNoteModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
          <BaseButton type="submit">{{ editingNote ? $t('workspace.save') : $t('workspace.add_note') }}</BaseButton>
        </div>
      </form>
    </WorkspaceModal>

    <!-- Modals -->
    <AddPatientModal v-model="showAddPatient" @saved="onPatientSaved" />
    <EditPatientModal v-model="showEditPatient" :patient="currentPatient" @saved="onPatientUpdated" />
    <CategoryManagerModal v-model="showCategoryManager" @saved="onCategoriesUpdated" />
    <SharePatientModal
      v-model="showShareModal"
      :patient="currentPatient"
      :existingShare="editingShare"
      @shared="onShareCreated"
      @updated="onShareUpdated"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import axios from 'axios'
import PatientListSidebar from '@/Components/workspace/PatientListSidebar.vue'
import WorkspaceHeader from '@/Components/workspace/WorkspaceHeader.vue'
import PatientSummary from '@/Components/workspace/PatientSummary.vue'
import QuickActions from '@/Components/workspace/QuickActions.vue'
import CategoryBlock from '@/Components/workspace/CategoryBlock.vue'
import MobileBottomBar from '@/Components/workspace/MobileBottomBar.vue'
import InlineFilePreview from '@/Components/workspace/InlineFilePreview.vue'
import AddPatientModal from '@/Components/workspace/AddPatientModal.vue'
import EditPatientModal from '@/Components/workspace/EditPatientModal.vue'
import CategoryManagerModal from '@/Components/workspace/CategoryManagerModal.vue'
import WorkspaceSettings from '@/Components/workspace/WorkspaceSettings.vue'
import SharePatientModal from '@/Components/workspace/SharePatientModal.vue'
import WorkspaceModal from '@/Components/workspace/WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import { usePullToRefresh } from '@/Composables/usePullToRefresh'

const props = defineProps({
  patients: Array,
  categories: Array,
  user: Object,
})

const {
  setPatients,
  selectedPatient,
  selectedPatientId,
  workspaceData,
  loadingPatient,
  isMobile,
  sidebarOpen,
  mobilePatientListOpen,
  canShare,
  canEdit,
  allFiles,
  allNotes,
  visits,
  shares,
  stats,
  categories,
  isPrimaryDoctor,
  openPreview,
  refreshWorkspaceData,
  showAddPatient,
  showEditPatient,
  showCategoryManager,
  showActionMenu,
  showSettings,
  closeSettings,
  expandedCategories,
  selectPatient,
  refreshPatientList,
  toggleSidebar,
  showArchived,
  archivedPatients,
  fetchArchivedPatients,
  archivePatient,
  restorePatient,
} = useWorkspace()

const dialog = useDialog()
const toast = useToast()

const { t } = useI18n()

const scrollContainer = ref(null)

const {
  pullDistance, pullProgress, isPulling, isRefreshing, thresholdReached,
  handleTouchStart, handleTouchMove, handleTouchEnd,
} = usePullToRefresh({
  scrollContainer,
  onRefresh: async () => {
    if (refreshPromise) return
    refreshPromise = (async () => {
      await refreshPatientList()
      await refreshWorkspaceData()
    })()
    try { await refreshPromise } finally { refreshPromise = null }
  },
})

const PTR_RADIUS = 12
const PTR_CIRCUMFERENCE = 2 * Math.PI * PTR_RADIUS

const ptrVisible = computed(() => pullDistance.value > 0 || isRefreshing.value)
const ptrScale = computed(() => 0.3 + pullProgress.value * 0.7)
const ptrOpacity = computed(() => Math.min(pullProgress.value * 2, 1))
const ptrArcLen = computed(() => pullProgress.value * PTR_CIRCUMFERENCE)
const ptrArrowRotation = computed(() => pullProgress.value * 180)
const ptrContentStyle = computed(() => ({
  transform: `translateY(${pullDistance.value}px)`,
  willChange: 'transform',
}))

let refreshPromise = null

onMounted(() => {
  refreshPatientList()
})

const summaryRef = ref(null)
const actionsRef = ref(null)
const recordsRef = ref(null)
const appointmentsRef = ref(null)
const visitsRef = ref(null)
const notesRef = ref(null)
const sharingRef = ref(null)
const timelineRef = ref(null)
const timelineScrollRef = ref(null)
const archiveRef = ref(null)

const showShareModal = ref(false)
const editingShare = ref(null)

const showNoteModal = ref(false)
const editingNote = ref(null)
const noteFormContent = ref('')

const actionMenuStyle = ref({})
const timelinePage = ref(1)
const timelineItems = ref([])
const timelineLoading = ref(false)
const timelineHasMore = ref(true)
const timelinePageSize = 20

const currentPatient = computed(() => {
  return workspaceData.value?.patient || selectedPatient.value || {}
})

const displayedTimeline = computed(() => {
  return timelineItems.value
})

function getCategoryIcon(icon) {
  const map = {
    folder: '📁', clipboard: '📋', scissors: '✂️', heart: '❤️',
    pill: '💊', beaker: '🔬', camera: '📷', calendar: '📅',
    document: '📄', chart: '📊', lab: '🧪', xray: '🔍',
  }
  return map[icon] || '📁'
}

function closeAllMenus() {
  showActionMenu.value = false
  showCategoryManager.value = false
}

function toggleActionMenu() {
  showActionMenu.value = !showActionMenu.value
  if (showActionMenu.value && selectedPatient.value) {
    nextTick(() => {
      const btn = document.querySelector('[data-action="actions"]')
      if (btn) {
        const rect = btn.getBoundingClientRect()
        const menuW = 200
        const menuH = 320
        let top = rect.bottom + 4
        let right = window.innerWidth - rect.right
        if (right + menuW > window.innerWidth) {
          right = Math.max(8, window.innerWidth - rect.left - menuW)
        }
        if (top + menuH > window.innerHeight - 8) {
          top = Math.max(8, rect.top - menuH - 4)
        }
        actionMenuStyle.value = {
          top: `${top}px`,
          right: `${right}px`,
        }
      }
    })
  }
}

const upcomingVisits = computed(() => {
  return visits.value.filter(v => v.visit_date && new Date(v.visit_date) >= new Date())
})

const pastVisits = computed(() => {
  return visits.value.filter(v => !v.visit_date || new Date(v.visit_date) < new Date())
})

function scrollToSection(section) {
  const map = {
    summary: summaryRef, actions: actionsRef,
    records: recordsRef, appointments: appointmentsRef,
    visits: visitsRef, notes: notesRef, sharing: sharingRef,
    timeline: timelineRef, archive: archiveRef,
  }
  const el = map[section]?.value
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
  showActionMenu.value = false
}

function openEditPatient() {
  showActionMenu.value = false
  showEditPatient.value = true
}

function handlePrint() {
  showActionMenu.value = false
  if (!selectedPatient.value?.uuid) return
  window.open(`/api/v1/workspace/${selectedPatient.value.uuid}/print`, '_blank')
}

function handleExport() {
  showActionMenu.value = false
  if (!selectedPatient.value?.uuid) return
  window.open(`/api/v1/workspace/${selectedPatient.value.uuid}/export`, '_blank')
}

function toggleShowArchived() {
  showArchived.value = !showArchived.value
  if (showArchived.value && archivedPatients.value.length === 0) {
    fetchArchivedPatients()
  }
}

async function handleArchive() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const uuid = selectedPatient.value.uuid
  const confirmed = await dialog.confirm({
    title: t('workspace.archive_patient_title'),
    message: t('workspace.archive_confirm'),
    confirmText: t('workspace.archive'),
    style: 'warning',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/workspace/patients/${uuid}`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    await refreshPatientList()
    await fetchArchivedPatients()
    if (patients.value.length > 0) {
      selectPatient(patients.value[0].uuid)
    }
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Archive failed', e)
    toast.error(t('common.error'))
  }
}

async function handleRestore() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const uuid = selectedPatient.value.uuid
  const confirmed = await dialog.confirm({
    title: t('common.restore'),
    message: t('workspace.restore_confirm'),
    confirmText: t('common.restore'),
    style: 'info',
  })
  if (!confirmed) return
  try {
    await axios.post(`/api/v1/workspace/patients/${uuid}/restore`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    await refreshPatientList()
    await fetchArchivedPatients()
    selectPatient(uuid)
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Restore failed', e)
    toast.error(t('common.error'))
  }
}

async function handleDelete() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const uuid = selectedPatient.value.uuid
  const confirmed = await dialog.confirm({
    title: t('workspace.delete_patient'),
    message: t('workspace.delete_confirm'),
    confirmText: t('common.delete'),
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/workspace/patients/${uuid}/force`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    await refreshPatientList()
    await fetchArchivedPatients()
    if (patients.value.length > 0) {
      selectPatient(patients.value[0].uuid)
    }
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Delete failed', e)
    toast.error(t('common.error'))
  }
}

function onPatientSaved(patient) {
  showAddPatient.value = false
  if (patient?.uuid) {
    selectPatient(patient.uuid)
  }
}

function onPatientUpdated(patient) {
  showEditPatient.value = false
  refreshPatientList()
  if (patient?.uuid) {
    selectPatient(patient.uuid)
  }
}

function onCategoriesUpdated() {
  showCategoryManager.value = false
  refreshWorkspaceData()
}

const allTimelineEvents = computed(() => {
  const events = []
  const files = allFiles.value
  const notes = allNotes.value
  const vs = visits.value
  for (let i = 0; i < files.length; i++) {
    const f = files[i]
    events.push({ id: `file-${f.id}`, type: 'file', title: f.title || f.file_name || 'File uploaded', description: f.desc || '', date: f.created_at })
  }
  for (let i = 0; i < notes.length; i++) {
    const n = notes[i]
    events.push({ id: `note-${n.id}`, type: 'note', title: `Note by ${n.author?.name || 'Doctor'}`, description: (typeof n.content === 'string' ? n.content.replace(/<[^>]*>/g, '') : '').substring(0, 80) || '', date: n.created_at })
  }
  for (let i = 0; i < vs.length; i++) {
    const v = vs[i]
    events.push({ id: `visit-${v.id}`, type: 'visit', title: `Visit: ${v.visit_type || 'Checkup'}`, description: v.reason || '', date: v.visit_date || v.created_at })
  }
  events.sort((a, b) => new Date(b.date) - new Date(a.date))
  return events
})

watch(allTimelineEvents, (events) => {
  timelineItems.value = events.slice(0, timelinePageSize)
  timelineHasMore.value = events.length > timelinePageSize
  timelinePage.value = 1
  timelineLoading.value = false
}, { immediate: true })

function onTimelineScroll() {
  const el = timelineScrollRef.value
  if (!el || timelineLoading.value || !timelineHasMore.value) return
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 100) {
    loadMoreTimeline()
  }
}

function loadMoreTimeline() {
  if (timelineLoading.value) return
  timelineLoading.value = true
  setTimeout(() => {
    const nextItems = allTimelineEvents.value.slice(timelinePage.value * timelinePageSize, (timelinePage.value + 1) * timelinePageSize)
    timelineItems.value = [...timelineItems.value, ...nextItems]
    timelinePage.value++
    timelineHasMore.value = timelineItems.value.length < allTimelineEvents.value.length
    timelineLoading.value = false
  }, 200)
}

function openShareModal() {
  editingShare.value = null
  showShareModal.value = true
}

function editSharePermission(share) {
  editingShare.value = share
  showShareModal.value = true
}

async function removeShare(share) {
  if (!selectedPatient.value) return
  const confirmed = await dialog.confirm({
    title: t('common.revoke'),
    message: t('patients.remove_access_confirm'),
    confirmText: t('common.revoke'),
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/patients/${selectedPatient.value.uuid}/shares/${share.id}`)
    refreshWorkspaceData()
    toast.success(t('patients.access_removed'))
  } catch (e) {
    console.error('Revoke failed', e)
    toast.error(t('common.error'))
  }
}

function onShareCreated() {
  showShareModal.value = false
  editingShare.value = null
  refreshWorkspaceData()
  toast.success(t('patients.shared_success'))
}

function onShareUpdated() {
  showShareModal.value = false
  editingShare.value = null
  refreshWorkspaceData()
  toast.success(t('patients.permission_updated'))
}

function editNote(note) {
  editingNote.value = note
  noteFormContent.value = note.content
  showNoteModal.value = true
}

async function deleteNote(note) {
  const confirmed = await dialog.confirm({
    title: t('workspace.delete_note'),
    message: t('workspace.delete_note_confirm'),
    confirmText: t('common.delete'),
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/patients/${selectedPatient.value.uuid}/notes/${note.uuid}`)
    refreshWorkspaceData()
    toast.success(t('common.success'))
  } catch (e) {
    console.error('Delete note failed', e)
    toast.error(t('common.error'))
  }
}

async function submitNoteForm() {
  if (!noteFormContent.value || !selectedPatient.value?.uuid) return
  try {
    if (editingNote.value) {
      await axios.put(`/api/v1/patients/${selectedPatient.value.uuid}/notes/${editingNote.value.uuid}`, {
        content: noteFormContent.value,
      })
      toast.success(t('workspace.note_updated'))
    } else {
      await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/notes`, {
        content: noteFormContent.value,
      })
      toast.success(t('workspace.note_added'))
    }
    showNoteModal.value = false
    editingNote.value = null
    noteFormContent.value = ''
    refreshWorkspaceData()
  } catch (e) {
    console.error('Note save failed', e)
    toast.error(t('common.error'))
  }
}

let removeStart = null
let removeFinish = null

onMounted(() => {
  performance.mark('vue-mount-start')

  try {
    const payloadSize = JSON.stringify(props).length
    console.log(`⏱️ Props Size: ${(payloadSize / 1024).toFixed(2)} KB`)
  } catch (e) {
    console.log('⏱️ Props Size: unable to stringify')
  }

  setPatients(props.patients)
  requestAnimationFrame(() => {
    performance.mark('vue-mount-end')
    performance.measure('Vue Hydration/Mount', 'vue-mount-start', 'vue-mount-end')
    console.log(`⏱️ Vue Mount Time: ${performance.getEntriesByName('Vue Hydration/Mount')[0].duration.toFixed(2)}ms`)
    performance.clearMarks()
    performance.clearMeasures()
  })

  removeStart = router.on('start', () => {
    performance.mark('inertia-nav-start')
  })

  removeFinish = router.on('finish', () => {
    performance.mark('inertia-nav-end')
    performance.measure('Inertia Navigation', 'inertia-nav-start', 'inertia-nav-end')
    const measures = performance.getEntriesByName('Inertia Navigation')
    if (measures.length > 0) {
      console.log(`⏱️ Inertia Nav Overhead: ${measures[measures.length - 1].duration.toFixed(2)}ms`)
    }
  })
})

onUnmounted(() => {
  closeAllMenus()
  showSettings.value = false
  if (removeStart) removeStart()
  if (removeFinish) removeFinish()
})
</script>
