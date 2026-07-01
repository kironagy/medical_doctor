<template>
  <div class="h-screen flex bg-slate-50 dark:bg-slate-950 overflow-hidden" @keydown.escape="closeAllMenus">
    <!-- Sidebar Overlay (Mobile) -->
    <div v-if="mobilePatientListOpen && isMobile" class="fixed inset-0 bg-slate-900/50 z-40" @click="mobilePatientListOpen = false"></div>

    <!-- Sidebar -->
    <div
      class="flex-shrink-0 z-50 transition-all duration-300"
      :class="[
        isMobile ? 'fixed inset-y-0 left-0' : 'w-[300px] lg:w-[320px] hidden md:block'
      ]"
    >
      <PatientListSidebar
        :user="user"
        :mobileOpen="mobilePatientListOpen"
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
          <button @click="openEditPatient" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            Edit Patient
          </button>
          <button @click="scrollToSection('sharing')" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
            Share
          </button>
          <button @click="handlePrint" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
            Print Record
          </button>
          <button @click="handleExport" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
            Export PDF
          </button>
          <hr class="my-1 border-slate-100 dark:border-slate-700" />
          <button @click="handleArchive" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-left">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
            Archive
          </button>
          <button @click="handleDelete" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-left">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            Delete
          </button>
        </div>
      </Teleport>

      <!-- Scrollable Content -->
      <div ref="scrollContainer" class="flex-1 overflow-y-auto overscroll-contain" :class="isMobile ? 'pb-20' : ''">
        <!-- Loading Skeleton -->
        <div v-if="loadingPatient" class="max-w-4xl mx-auto px-3 md:px-6 py-4 md:py-6 space-y-5">
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
            <h3 class="text-lg font-bold font-heading text-slate-900 dark:text-white mb-1">Select a Patient</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">Choose a patient from the sidebar to begin</p>
            <button @click="mobilePatientListOpen = true" class="md:hidden mt-4 px-5 py-2.5 bg-primary-600 text-white rounded-xl text-sm font-medium active:scale-95 transition-all">
              View Patient List
            </button>
          </div>
        </div>

        <!-- Workspace Content -->
        <div v-else class="max-w-4xl mx-auto px-3 md:px-6 py-4 md:py-6 space-y-5">
          <!-- Section 1: Patient Summary (3-second understanding) -->
          <div ref="summaryRef">
            <PatientSummary :patient="currentPatient" :isPrimaryDoctor="isPrimaryDoctor" @action="toggleActionMenu" />
          </div>

          <!-- Section 2: Quick Actions -->
          <div ref="actionsRef" v-if="selectedPatient">
            <div class="flex items-center gap-2 mb-3">
              <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Quick Actions</h3>
            </div>
            <QuickActions
              @share="scrollToSection('sharing')"
              @appointment="scrollToSection('appointments')"
              @notes="scrollToSection('notes')"
              @upload="scrollToSection('records')"
              @history="scrollToSection('history')"
              @categories="showCategoryManager = true"
            />
          </div>

          <!-- Section 3: Patient Sharing -->
          <div ref="sharingRef" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Patient Sharing</h3>
              </div>
              <button v-if="canShare" @click="showShareForm = !showShareForm" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                Share
              </button>
            </div>
            <div v-if="showShareForm && canShare" class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
              <div class="flex gap-2">
                <input v-model="searchDoctorQuery" @input="debouncedDoctorSearch" type="text" placeholder="Search doctor..." class="flex-1 px-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
              </div>
              <div v-if="doctorResults.length > 0" class="mt-2 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
                <button v-for="doc in doctorResults" :key="doc.id" @click="shareWithDoctor(doc)" class="w-full flex items-center justify-between px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-700 text-left text-xs">
                  <span class="font-medium text-slate-900 dark:text-white">{{ doc.name }}</span>
                  <span class="text-primary-600">Share</span>
                </button>
              </div>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
              <div v-for="share in shares" :key="share.id" class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 flex items-center justify-center text-xs font-bold">{{ share.doctor?.name?.charAt(0) || 'D' }}</div>
                  <div>
                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ share.doctor?.name || 'Doctor' }}</p>
                    <p class="text-xs text-slate-500">{{ share.access_level === 'read_write' ? 'Read & Write' : 'Read Only' }}</p>
                  </div>
                </div>
                <button @click="revokeShare(share)" class="text-[11px] font-medium text-rose-600 dark:text-rose-400 hover:text-rose-700 px-2 py-1 rounded hover:bg-rose-50 dark:hover:bg-rose-900/20">Revoke</button>
              </div>
              <div v-if="shares.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">Not shared with anyone</div>
            </div>
          </div>

          <!-- Section 4: Medical History Archive -->
          <div ref="historyRef" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Medical History</h3>
                <span class="text-[11px] text-slate-400">({{ allFiles.length + allNotes.length }} items)</span>
              </div>
              <button v-if="historyCollapsed" @click="historyCollapsed = false" class="text-xs font-medium text-primary-600 dark:text-primary-400">Show all</button>
            </div>
            <div v-if="!historyCollapsed" class="divide-y divide-slate-100 dark:divide-slate-800 max-h-80 overflow-y-auto">
              <div v-for="item in historyItems" :key="item.id" class="px-4 py-3 flex items-start gap-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                <div class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5"
                  :class="item.type === 'file' ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-amber-100 dark:bg-amber-900/30'"
                >
                  <svg v-if="item.type === 'file'" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                  <svg v-else class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ item.title }}</p>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-medium" :class="item.category === 'medical_history' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700' : item.category === 'pre_op' ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700' : item.category === 'operation_sheet' ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-700' : 'bg-slate-100 dark:bg-slate-800 text-slate-600'">{{ item.categoryLabel }}</span>
                  </div>
                  <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">{{ item.description }}</p>
                  <p class="text-[11px] text-slate-400 mt-0.5">{{ item.date }}</p>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                  <button v-if="item.file" @click="openPreview(item.file)" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-400 hover:text-primary-600 transition-colors" title="Preview">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                  </button>
                  <a v-if="item.file?.url" :href="item.file.url" target="_blank" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-400 hover:text-primary-600 transition-colors" title="Download">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                  </a>
                </div>
              </div>
            </div>
          </div>

          <!-- Section 5: Dynamic Categories (lazy loaded on scroll) -->
          <div ref="recordsRef" class="space-y-3">
            <div class="flex items-center justify-between mb-1">
              <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Medical Records</h3>
              <button @click="showCategoryManager = true" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>
                Manage
              </button>
            </div>
            <CategoryBlock
              v-for="cat in categories"
              :key="cat.slug"
              :slug="cat.slug"
              :name="cat.name"
              :icon="getCategoryIcon(cat.icon)"
              :color="cat.color || '#6b7280'"
            />
          </div>

          <!-- Section 6: Appointments -->
          <div ref="appointmentsRef" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Appointments</h3>
                <span class="text-[11px] text-slate-400">({{ visits.length }})</span>
              </div>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
              <div v-for="visit in visits" :key="visit.id" class="px-4 py-3 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 flex items-center justify-center flex-shrink-0">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-slate-900 dark:text-white">{{ visit.visit_type || 'Visit' }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ visit.reason || '—' }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ new Date(visit.visit_date || visit.created_at).toLocaleDateString() }}</p>
                  <p v-if="visit.next_visit_date" class="text-[10px] text-primary-600 dark:text-primary-400">Next: {{ new Date(visit.next_visit_date).toLocaleDateString() }}</p>
                </div>
              </div>
              <div v-if="visits.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">No appointments recorded</div>
            </div>
          </div>

          <!-- Section 7: Notes -->
          <div ref="notesRef" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                  <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Notes</h3>
                <span class="text-[11px] text-slate-400">({{ allNotes.length }})</span>
              </div>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800 max-h-72 overflow-y-auto">
              <div v-for="note in allNotes" :key="note.id" class="px-4 py-3">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-[11px] font-medium text-slate-600 dark:text-slate-300">{{ note.author?.name || 'Doctor' }}</span>
                  <div class="flex items-center gap-2">
                    <span class="text-[10px] px-1.5 py-0.5 rounded font-medium" :class="note.category ? 'bg-slate-100 dark:bg-slate-800 text-slate-500' : ''">{{ note.category ? note.category.replace(/_/g, ' ') : '' }}</span>
                    <span class="text-[10px] text-slate-400">{{ new Date(note.created_at).toLocaleDateString() }}</span>
                  </div>
                </div>
                <div class="text-xs text-slate-700 dark:text-slate-300 prose prose-slate dark:prose-invert max-w-none line-clamp-3" v-html="note.content"></div>
              </div>
              <div v-if="allNotes.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">No notes recorded</div>
            </div>
          </div>

          <!-- Section 9: Timeline (lazy loaded) -->
          <div ref="timelineRef" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-4 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                  <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <h3 class="text-sm font-bold font-heading text-slate-900 dark:text-white">Timeline</h3>
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
              <div v-if="!timelineHasMore && displayedTimeline.length > 0" class="text-center text-xs text-slate-400 py-2">End of timeline</div>
              <div v-if="displayedTimeline.length === 0" class="text-center text-sm text-slate-400 py-4">No timeline events</div>
            </div>
          </div>

          <!-- Section 10: Archive Button -->
          <div ref="archiveRef" class="text-center pb-4">
            <button @click="handleArchive" class="px-6 py-2.5 text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-xl border border-rose-200 dark:border-rose-800 transition-colors inline-flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
              Archive Patient Record
            </button>
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

    <!-- Modals -->
    <AddPatientModal v-model="showAddPatient" @saved="onPatientSaved" />
    <EditPatientModal v-model="showEditPatient" :patient="currentPatient" @saved="onPatientUpdated" />
    <CategoryManagerModal v-model="showCategoryManager" @saved="onCategoriesUpdated" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
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
  expandedCategories,
  selectPatient,
  refreshPatientList,
} = useWorkspace()

const dialog = useDialog()
const toast = useToast()

const scrollContainer = ref(null)
const summaryRef = ref(null)
const actionsRef = ref(null)
const sharingRef = ref(null)
const historyRef = ref(null)
const recordsRef = ref(null)
const appointmentsRef = ref(null)
const notesRef = ref(null)
const timelineRef = ref(null)
const timelineScrollRef = ref(null)
const archiveRef = ref(null)

const historyCollapsed = ref(false)
const showShareForm = ref(false)
const showShareSelectModal = ref(false)
const shareAccessLevel = ref('read')
const searchDoctorQuery = ref('')
const doctorResults = ref([])
let searchTimeout = null

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
  showShareForm.value = false
  showCategoryManager.value = false
}

function toggleActionMenu() {
  showActionMenu.value = !showActionMenu.value
  if (showActionMenu.value && selectedPatient.value) {
    nextTick(() => {
      const btn = document.querySelector('[title="Actions"]')
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

function scrollToSection(section) {
  const map = {
    summary: summaryRef, actions: actionsRef, sharing: sharingRef,
    history: historyRef, records: recordsRef, appointments: appointmentsRef,
    notes: notesRef, timeline: timelineRef, archive: archiveRef,
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
  window.print()
}

function handleExport() {
  showActionMenu.value = false
  window.open(`/api/v1/workspace/${selectedPatient.value?.uuid}/export`, '_blank')
}

async function handleArchive() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const confirmed = await dialog.confirm({
    title: 'Archive Patient',
    message: 'Archive this patient? They can be restored later.',
    confirmText: 'Archive',
    style: 'warning',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/workspace/patients/${selectedPatient.value.uuid}`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    refreshPatientList()
    toast.success('Patient archived')
  } catch (e) {
    console.error('Archive failed', e)
    toast.error('Failed to archive patient')
  }
}

async function handleDelete() {
  showActionMenu.value = false
  if (!selectedPatient.value) return
  const confirmed = await dialog.confirm({
    title: 'Delete Patient',
    message: 'Permanently delete this patient? This action cannot be undone.',
    confirmText: 'Delete',
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/workspace/patients/${selectedPatient.value.uuid}/force`)
    selectedPatientId.value = null
    workspaceData.value = null
    expandedCategories.value = {}
    showActionMenu.value = false
    refreshPatientList()
    toast.success('Patient permanently deleted')
  } catch (e) {
    console.error('Delete failed', e)
    toast.error('Failed to delete patient')
  }
}

function onPatientSaved(patient) {
  showAddPatient.value = false
  refreshPatientList()
  if (patient?.uuid) {
    selectPatient(patient.uuid)
  }
}

function onPatientUpdated() {
  showEditPatient.value = false
  refreshWorkspaceData()
}

function onCategoriesUpdated() {
  showCategoryManager.value = false
  refreshWorkspaceData()
}

const historyItems = computed(() => {
  const items = []
  for (const f of allFiles.value) {
    items.push({
      id: `file-${f.id}`,
      type: 'file',
      title: f.title || f.file_name || 'File',
      description: f.desc || '',
      date: new Date(f.created_at).toLocaleDateString(),
      category: f.category,
      categoryLabel: f.category?.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'Other',
      file: f,
    })
  }
  for (const n of allNotes.value) {
    items.push({
      id: `note-${n.id}`,
      type: 'note',
      title: `Note by ${n.author?.name || 'Doctor'}`,
      description: n.content?.replace(/<[^>]*>/g, '').substring(0, 100),
      date: new Date(n.created_at).toLocaleDateString(),
      category: n.category,
      categoryLabel: n.category?.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'Other',
      file: null,
    })
  }
  items.sort((a, b) => new Date(b.date) - new Date(a.date))
  return items
})

function buildTimeline() {
  const events = []
  for (const f of allFiles.value) {
    events.push({ id: `file-${f.id}`, type: 'file', title: f.title || f.file_name || 'File uploaded', description: f.desc || '', date: f.created_at })
  }
  for (const n of allNotes.value) {
    events.push({ id: `note-${n.id}`, type: 'note', title: `Note by ${n.author?.name || 'Doctor'}`, description: n.content?.replace(/<[^>]*>/g, '').substring(0, 80) || '', date: n.created_at })
  }
  for (const v of visits.value) {
    events.push({ id: `visit-${v.id}`, type: 'visit', title: `Visit: ${v.visit_type || 'Checkup'}`, description: v.reason || '', date: v.visit_date || v.created_at })
  }
  events.sort((a, b) => new Date(b.date) - new Date(a.date))

  timelineItems.value = events.slice(0, timelinePageSize)
  timelineHasMore.value = events.length > timelinePageSize
  timelinePage.value = 1
}

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
    const events = []
    for (const f of allFiles.value) {
      events.push({ id: `file-${f.id}`, type: 'file', title: f.title || f.file_name || 'File', description: f.desc || '', date: f.created_at })
    }
    for (const n of allNotes.value) {
      events.push({ id: `note-${n.id}`, type: 'note', title: `Note by ${n.author?.name || 'Doctor'}`, description: n.content?.replace(/<[^>]*>/g, '').substring(0, 80) || '', date: n.created_at })
    }
    for (const v of visits.value) {
      events.push({ id: `visit-${v.id}`, type: 'visit', title: `Visit: ${v.visit_type || 'Checkup'}`, description: v.reason || '', date: v.visit_date || v.created_at })
    }
    events.sort((a, b) => new Date(b.date) - new Date(a.date))

    const nextItems = events.slice(timelinePage.value * timelinePageSize, (timelinePage.value + 1) * timelinePageSize)
    timelineItems.value = [...timelineItems.value, ...nextItems]
    timelinePage.value++
    timelineHasMore.value = timelineItems.value.length < events.length
    timelineLoading.value = false
  }, 200)
}

function debouncedDoctorSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (searchDoctorQuery.value.length < 2) {
    doctorResults.value = []
    return
  }
  searchTimeout = setTimeout(async () => {
    try {
      const res = await axios.get('/api/v1/doctors/search', { params: { q: searchDoctorQuery.value } })
      doctorResults.value = res.data
    } catch { doctorResults.value = [] }
  }, 300)
}

async function shareWithDoctor(doc) {
  if (!selectedPatient.value) return
  try {
    await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/shares`, {
      doctor_id: doc.id, access_level: shareAccessLevel.value,
    })
    showShareSelectModal.value = false
    showShareForm.value = false
    searchDoctorQuery.value = ''
    doctorResults.value = []
    refreshWorkspaceData()
  } catch (e) { console.error(e) }
}

async function revokeShare(share) {
  if (!selectedPatient.value) return
  try {
    await axios.delete(`/api/v1/patients/${selectedPatient.value.uuid}/shares/${share.id}`)
    refreshWorkspaceData()
  } catch (e) { console.error(e) }
}

watch(workspaceData, () => {
  buildTimeline()
  timelineLoading.value = false
  timelineHasMore.value = true
})

onMounted(() => {
  setPatients(props.patients)
})
</script>
