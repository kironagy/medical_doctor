<template>
  <div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden bg-white dark:bg-slate-900">
    <div class="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
      <button @click="toggleCategory(slug)" class="flex items-center gap-3 flex-1 text-left">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :style="{ backgroundColor: color + '20', color: color }">
          <span class="text-sm font-bold">{{ icon }}</span>
        </div>
        <div>
          <h3 class="text-sm font-bold text-slate-900 dark:text-white">{{ name }}</h3>
          <p class="text-[11px] text-slate-500 dark:text-slate-400">
            {{ categoryFiles.length }} files · {{ categoryNotes.length }} notes
          </p>
        </div>
      </button>
      <div class="flex items-center gap-1">
        <button v-if="canEdit" @click.stop="showUploadArea = !showUploadArea" class="p-1.5 rounded-lg text-slate-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors" title="Upload Files">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
        </button>
        <div class="relative">
          <button @click.stop="toggleCategoryMenu" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" /></svg>
          </button>
          <Teleport to="body">
            <div v-if="showCategoryMenu" class="fixed inset-0 z-[200]" @click="showCategoryMenu = false"></div>
            <div v-if="showCategoryMenu" ref="menuRef" class="fixed z-[200] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl py-1.5 min-w-[180px]" :style="menuStyle">
              <button @click="addNote" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                Add Note
              </button>
              <button @click="showUploadArea = !showUploadArea" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                Upload Files
              </button>
              <hr class="my-1 border-slate-100 dark:border-slate-700" />
              <button @click="openAddVisit" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                Add Visit
              </button>
              <button @click="addTimelineEntry" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Add Timeline Entry
              </button>
              <hr class="my-1 border-slate-100 dark:border-slate-700" />
              <button @click="openRename" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                Rename
              </button>
              <button @click="openChangeColor" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
                <div class="flex gap-1">
                  <div v-for="c in colorOptions.slice(0,5)" :key="c" class="w-3 h-3 rounded-full" :style="{ backgroundColor: c }"></div>
                </div>
                <span class="ms-1">Change Color</span>
              </button>
              <hr class="my-1 border-slate-100 dark:border-slate-700" />
              <button @click="deleteCategory" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-left">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                Delete
              </button>
            </div>
          </Teleport>
        </div>
        <svg class="w-4 h-4 text-slate-400 transition-transform duration-200 cursor-pointer" :class="expanded ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" @click.stop="toggleCategory(slug)"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
      </div>
    </div>

    <Transition name="accordion">
      <div v-if="expanded" class="border-t border-slate-100 dark:border-slate-800">
        <div v-if="hasLoaded" class="p-4 space-y-4">

          <!-- Upload Progress -->
          <div v-if="activeUploads.length > 0" class="space-y-2">
            <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Uploading</h4>
            <div v-for="job in activeUploads" :key="job.id" class="bg-slate-50 dark:bg-slate-800/50 rounded-lg p-3 border border-slate-100 dark:border-slate-700">
              <div class="flex items-center justify-between mb-1.5">
                <span class="text-xs font-medium text-slate-700 dark:text-slate-300 truncate max-w-[70%]">{{ job.file.name }}</span>
                <span class="text-[10px] text-slate-400">{{ job.progress }}%</span>
              </div>
              <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all duration-300" :class="job.status === 'failed' ? 'bg-rose-500' : 'bg-primary-500'" :style="{ width: job.progress + '%' }"></div>
              </div>
              <div class="flex items-center justify-between mt-1">
                <span class="text-[10px]" :class="job.status === 'failed' ? 'text-rose-500' : 'text-slate-400'">
                  {{ job.status === 'uploading' ? formatSpeed(job.speed) : job.status === 'failed' ? (job.error || 'Failed') : job.status }}
                </span>
                <div class="flex gap-1">
                  <button v-if="job.status === 'uploading'" @click.stop="pauseJob(job)" class="text-[10px] text-amber-600 hover:text-amber-700 font-medium">Pause</button>
                  <button v-if="job.status === 'paused'" @click.stop="resumeJob(job)" class="text-[10px] text-emerald-600 hover:text-emerald-700 font-medium">Resume</button>
                  <button v-if="job.status === 'failed'" @click.stop="retryJob(job)" class="text-[10px] text-primary-600 hover:text-primary-700 font-medium">Retry</button>
                  <button @click.stop="cancelJob(job)" class="text-[10px] text-rose-600 hover:text-rose-700 font-medium">Cancel</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Files Grid -->
          <div v-if="categoryFiles.length > 0">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Files ({{ categoryFiles.length }})</h4>
              <button v-if="canEdit && !showUploadArea" @click="showUploadArea = true" class="text-[11px] font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                Upload
              </button>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
              <div
                v-for="file in categoryFiles" :key="file.id"
                class="group relative bg-slate-50 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden hover:shadow-sm transition-shadow cursor-pointer"
              >
                <div @click="openPreview(file)" class="aspect-[4/3] flex items-center justify-center overflow-hidden">
                  <img v-if="file.thumbnail_url" :src="file.thumbnail_url" class="object-cover w-full h-full" @error="e => e.target.style.display='none'" loading="lazy" />
                  <div v-else-if="file.mime_type?.startsWith('image/')" class="text-slate-400 flex items-center justify-center">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                  </div>
                  <div v-else class="text-slate-400 flex flex-col items-center justify-center p-2">
                    <svg v-if="file.mime_type?.startsWith('video/')" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                    <svg v-else-if="file.mime_type === 'application/pdf'" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                    <svg v-else class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    <span class="text-[10px] font-medium mt-1 uppercase">{{ file.extension || 'FILE' }}</span>
                  </div>
                </div>
                <div class="p-1.5">
                  <div class="flex items-center justify-between gap-1">
                    <p class="text-[11px] font-medium text-slate-800 dark:text-slate-200 truncate flex-1">{{ file.title || file.file_name }}</p>
                    <div class="relative" @click.stop>
                      <button @click="toggleFileMenu(file)" class="p-0.5 text-slate-300 hover:text-slate-500 transition-colors opacity-0 group-hover:opacity-100">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" /></svg>
                      </button>
                    </div>
                  </div>
                  <p class="text-[10px] text-slate-400">{{ formatSize(file.size) }}</p>
                </div>
                <div class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5 p-2 backdrop-blur-sm">
                  <button @click.stop="openPreview(file)" class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 hover:text-primary-600 transition-colors" title="Preview">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                  </button>
                  <a v-if="file.url" :href="file.url" target="_blank" @click.stop class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 hover:text-primary-600 transition-colors" title="Download">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                  </a>
                  <button @click.stop="deleteFile(file)" class="p-1.5 bg-white/90 dark:bg-slate-800/90 rounded-full text-slate-700 hover:text-rose-600 transition-colors" title="Delete">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Upload Area (always visible when toggled, also shown in empty state) -->
          <div v-if="showUploadArea && canEdit">
            <div
              class="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-lg p-5 text-center cursor-pointer hover:border-primary-400 dark:hover:border-primary-500 transition-all hover:bg-primary-50/50 dark:hover:bg-primary-900/10"
              @click="triggerFileInput"
              @dragover.prevent="dragging = true"
              @dragleave.prevent="dragging = false"
              @drop.prevent="handleDrop"
              :class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 scale-[1.01]' : ''"
            >
              <input type="file" ref="fileInput" multiple class="hidden" accept="image/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.7z,audio/*" @change="handleFileSelect" />
              <svg class="w-8 h-8 mx-auto text-slate-300 dark:text-slate-600 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
              <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Drop files here or click to upload</p>
              <p class="text-xs text-slate-400 mt-0.5">Images, Videos, PDF, Documents, Audio, ZIP</p>
            </div>
          </div>

          <!-- Empty State -->
          <div v-if="categoryFiles.length === 0 && categoryNotes.length === 0 && !showUploadArea" class="text-center py-8 px-4">
            <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center">
              <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            </div>
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">This category is empty</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">Upload files or add notes to get started</p>
            <div class="flex items-center justify-center gap-2">
              <button v-if="canEdit" @click="showUploadArea = true" class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-xs font-medium transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                Upload Files
              </button>
              <button @click="addNote" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg text-xs font-medium transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                Add Note
              </button>
            </div>
          </div>

          <!-- Notes -->
          <div v-if="categoryNotes.length > 0">
            <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">Notes</h4>
            <div class="space-y-2">
              <div v-for="note in categoryNotes" :key="note.id" class="bg-slate-50 dark:bg-slate-800/50 rounded-lg p-3 border border-slate-100 dark:border-slate-700">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-[11px] font-medium text-slate-600 dark:text-slate-300">{{ note.author?.name || 'Doctor' }}</span>
                  <span class="text-[10px] text-slate-400">{{ new Date(note.created_at).toLocaleDateString() }}</span>
                </div>
                <div class="text-xs text-slate-700 dark:text-slate-300 prose prose-slate dark:prose-invert max-w-none line-clamp-3" v-html="note.content"></div>
              </div>
            </div>
          </div>

        </div>
        <div v-else class="p-6 text-center">
          <div class="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
        </div>
      </div>
    </Transition>
  </div>

  <!-- Add Note Modal -->
  <WorkspaceModal :modelValue="showNoteModal" @update:modelValue="showNoteModal = false" title="Add Note" size="sm">
    <form @submit.prevent="submitNote" class="space-y-4">
      <textarea v-model="noteContent" class="input-field w-full" rows="4" placeholder="Enter note content..." required></textarea>
      <div class="flex justify-end gap-3">
        <button type="button" @click="showNoteModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
        <BaseButton type="submit">Add Note</BaseButton>
      </div>
    </form>
  </WorkspaceModal>

  <!-- Add Visit Modal -->
  <WorkspaceModal :modelValue="showVisitModal" @update:modelValue="showVisitModal = false" title="Add Visit" size="sm">
    <form @submit.prevent="submitVisit" class="space-y-4">
      <input v-model="visitType" class="input-field w-full" placeholder="Visit type (e.g. Checkup, Follow-up)" required />
      <div class="flex justify-end gap-3">
        <button type="button" @click="showVisitModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
        <BaseButton type="submit">Add Visit</BaseButton>
      </div>
    </form>
  </WorkspaceModal>

  <!-- Rename Category Modal -->
  <WorkspaceModal :modelValue="showRenameModal" @update:modelValue="showRenameModal = false" title="Rename Category" size="sm">
    <form @submit.prevent="submitRename" class="space-y-4">
      <input v-model="renameValue" class="input-field w-full" placeholder="Category name" required />
      <div class="flex justify-end gap-3">
        <button type="button" @click="showRenameModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
        <BaseButton type="submit">Rename</BaseButton>
      </div>
    </form>
  </WorkspaceModal>

  <!-- Change Color Modal -->
  <WorkspaceModal :modelValue="showColorModal" @update:modelValue="showColorModal = false" title="Change Color" size="sm">
    <form @submit.prevent="submitColor" class="space-y-4">
      <div class="flex items-center gap-3">
        <input v-model="colorValue" type="color" class="w-12 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
        <input v-model="colorValue" class="input-field flex-1" placeholder="#3b82f6" required />
      </div>
      <div class="flex gap-2">
        <button v-for="c in colorOptions" :key="c" type="button" @click="colorValue = c" class="w-7 h-7 rounded-full border-2 transition-all" :class="colorValue === c ? 'border-slate-900 dark:border-white scale-110' : 'border-transparent'" :style="{ backgroundColor: c }"></button>
      </div>
      <div class="flex justify-end gap-3 pt-2">
        <button type="button" @click="showColorModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
        <BaseButton type="submit">Save Color</BaseButton>
      </div>
    </form>
  </WorkspaceModal>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useUploads } from '@/Composables/useUploads'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import WorkspaceModal from './WorkspaceModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import axios from 'axios'

const props = defineProps({
  slug: String,
  name: String,
  icon: { type: String, default: '📁' },
  color: { type: String, default: '#6b7280' },
})

const { toggleCategory, isCategoryExpanded, allFiles, allNotes, canEdit, selectedPatient, openPreview, refreshWorkspaceData, markCategoryLoaded, isCategoryLoaded } = useWorkspace()
const { uploadFile, cancelUpload, pauseUpload, resumeUpload, retryUpload, uploads } = useUploads()
const dialog = useDialog()
const toast = useToast()

const expanded = computed(() => isCategoryExpanded(props.slug))
const hasLoaded = computed(() => isCategoryLoaded(props.slug))
const showUploadArea = ref(false)
const showCategoryMenu = ref(false)
const dragging = ref(false)
const fileInput = ref(null)
const menuRef = ref(null)
const menuStyle = ref({})
const activeFileMenu = ref(null)
const colorOptions = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#14b8a6', '#6b7280']

const categoryFiles = computed(() => {
  if (!hasLoaded.value) return []
  return allFiles.value.filter(f => (f.category || 'notes') === props.slug)
})

const categoryNotes = computed(() => {
  if (!hasLoaded.value) return []
  return allNotes.value.filter(n => (n.category || 'notes') === props.slug)
})

const activeUploads = computed(() => {
  return uploads.value.filter(j => j.metadata?.category === props.slug && j.status !== 'completed' && j.status !== 'cancelled')
})

const showNoteModal = ref(false)
const showVisitModal = ref(false)
const showRenameModal = ref(false)
const showColorModal = ref(false)
const noteContent = ref('')
const visitType = ref('')
const renameValue = ref('')
const colorValue = ref('')

watch(expanded, (val) => {
  if (val && !hasLoaded.value) {
    markCategoryLoaded(props.slug)
  }
}, { immediate: true })

const completedUploadCount = ref(0)
watch(uploads, (list) => {
  const catUploads = list.filter(j => j.metadata?.category === props.slug)
  const completed = catUploads.filter(j => j.status === 'completed').length
  if (completed > completedUploadCount.value) {
    completedUploadCount.value = completed
    refreshWorkspaceData()
    toast.success('Upload complete')
  }
}, { deep: true })

function toggleCategoryMenu(e) {
  showCategoryMenu.value = !showCategoryMenu.value
  if (showCategoryMenu.value) {
    const btn = e.currentTarget
    nextTick(() => {
      const rect = btn.getBoundingClientRect()
      const menuW = 180
      const menuH = 320
      let top = rect.bottom + 4
      let left = Math.min(rect.left, window.innerWidth - menuW - 8)
      if (top + menuH > window.innerHeight - 8) {
        top = Math.max(8, rect.top - menuH - 4)
      }
      if (left < 8) left = 8
      menuStyle.value = { top: `${top}px`, left: `${left}px` }
    })
  }
}

function toggleFileMenu(file) {
  activeFileMenu.value = activeFileMenu.value?.id === file.id ? null : file
}

function triggerFileInput() { fileInput.value?.click() }

function handleFileSelect(e) {
  handleFiles(Array.from(e.target.files))
  e.target.value = null
}

function handleDrop(e) {
  dragging.value = false
  handleFiles(Array.from(e.dataTransfer.files))
}

function handleFiles(selectedFiles) {
  const patientId = selectedPatient.value?.id
  if (!patientId) return
  for (const file of selectedFiles) {
    uploadFile(file, patientId, { category: props.slug })
  }
}

function pauseJob(job) { pauseUpload(job.id) }
function resumeJob(job) { resumeUpload(job.id) }
function retryJob(job) { retryUpload(job.id) }
function cancelJob(job) { cancelUpload(job.id) }

async function deleteFile(file) {
  try {
    await axios.delete(`/api/v1/files/${file.uuid}`)
    refreshWorkspaceData()
    toast.success('File deleted')
  } catch (e) { console.error('Delete failed', e) }
}

async function addNote() {
  showNoteModal.value = true
}

async function submitNote() {
  if (!noteContent.value || !selectedPatient.value?.id) return
  try {
    await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/notes`, {
      content: noteContent.value,
      category: props.slug,
    })
    showCategoryMenu.value = false
    showNoteModal.value = false
    noteContent.value = ''
    refreshWorkspaceData()
    toast.success('Note added')
  } catch (e) { console.error('Add note failed', e) }
}

async function openAddVisit() {
  showVisitModal.value = true
}

async function submitVisit() {
  if (!visitType.value || !selectedPatient.value?.uuid) return
  try {
    await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/visits`, {
      visit_type: visitType.value,
      category: props.slug,
    })
    showCategoryMenu.value = false
    showVisitModal.value = false
    visitType.value = ''
    refreshWorkspaceData()
    toast.success('Visit added')
  } catch (e) { console.error('Add visit failed', e) }
}

function addTimelineEntry() {
  addNote()
}

function openRename() {
  renameValue.value = props.name
  showRenameModal.value = true
}

async function submitRename() {
  if (!renameValue.value || renameValue.value === props.name) { showRenameModal.value = false; return }
  showCategoryMenu.value = false
  showRenameModal.value = false
  try {
    await axios.put('/api/v1/categories', { categories: [{ slug: props.slug, name: renameValue.value, icon: props.icon, color: props.color }] })
    refreshWorkspaceData()
    toast.success('Category renamed')
  } catch (e) { console.error('Rename failed', e) }
}

function openChangeColor() {
  colorValue.value = props.color
  showColorModal.value = true
}

async function submitColor() {
  if (!colorValue.value) { showColorModal.value = false; return }
  showCategoryMenu.value = false
  showColorModal.value = false
  try {
    await axios.put('/api/v1/categories', { categories: [{ slug: props.slug, name: props.name, icon: props.icon, color: colorValue.value }] })
    refreshWorkspaceData()
    toast.success('Color changed')
  } catch (e) { console.error('Color change failed', e) }
}

async function deleteCategory() {
  showCategoryMenu.value = false
  const confirmed = await dialog.confirm({
    title: 'Delete Category',
    message: `Delete the "${props.name}" category?`,
    confirmText: 'Delete',
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/categories/${props.slug}`)
    refreshWorkspaceData()
    toast.success('Category deleted')
  } catch (e) { console.error('Delete category failed', e) }
}

function formatSize(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function formatSpeed(bps) {
  if (!bps || bps <= 0) return ''
  return formatSize(bps) + '/s'
}
</script>

<style scoped>
.accordion-enter-active, .accordion-leave-active { transition: all 0.2s ease; }
.accordion-enter-from, .accordion-leave-to { opacity: 0; max-height: 0; }
.accordion-enter-to, .accordion-leave-from { opacity: 1; max-height: 2000px; }
</style>
