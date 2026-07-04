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
            {{ totalItems }} files · {{ notesCount }} notes
          </p>
        </div>
      </button>
      <div class="flex items-center gap-1">
        <button @click.stop="showUploadArea = !showUploadArea" class="p-1.5 rounded-lg text-slate-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors" title="Upload Files">
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
              <button @click="showCategoryMenu = false; showUploadArea = true" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
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
                <span class="ms-1">{{ $t('workspace.change_color') }}</span>
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
            <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">{{ $t('files.uploading') }}</h4>
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
                  <button v-if="job.status === 'uploading'" @click.stop="pauseJob(job)" class="text-[10px] text-amber-600 hover:text-amber-700 font-medium">{{ $t('files.pause') }}</button>
                  <button v-if="job.status === 'paused'" @click.stop="resumeJob(job)" class="text-[10px] text-emerald-600 hover:text-emerald-700 font-medium">{{ $t('files.resume') }}</button>
                  <button v-if="job.status === 'failed'" @click.stop="retryJob(job)" class="text-[10px] text-primary-600 hover:text-primary-700 font-medium">{{ $t('files.retry') }}</button>
                  <button @click.stop="cancelJob(job)" class="text-[10px] text-rose-600 hover:text-rose-700 font-medium">{{ $t('files.cancel') }}</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Search & Filters -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 flex-wrap">
              <div class="relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                  <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="$t('category.search_placeholder')"
                    class="w-full pl-9 pr-3 py-1.5 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-500 text-slate-700 dark:text-slate-300"
                  />

              </div>
              <div class="relative">
                  <select v-model="dateFilter" @change="onDateFilterChange" class="appearance-none bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500 pr-8">
                    <option value="">{{ $t('category.all_dates') }}</option>
                    <option value="today">{{ $t('category.today') }}</option>
                    <option value="yesterday">{{ $t('category.yesterday') }}</option>
                    <option value="last7">{{ $t('category.last_7_days') }}</option>
                    <option value="last30">{{ $t('category.last_30_days') }}</option>
                    <option value="this_month">{{ $t('category.this_month') }}</option>
                    <option value="last_month">{{ $t('category.last_month') }}</option>
                    <option value="this_year">{{ $t('category.this_year') }}</option>
                    <option value="custom">{{ $t('category.custom_range') }}</option>
                  </select>
                <svg class="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
              </div>
              <div class="relative">
                  <select v-model="timeFilter" @change="onTimeFilterChange" class="appearance-none bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500 pr-8">
                    <option value="">{{ $t('category.all_times') }}</option>
                    <option value="morning">{{ $t('category.morning') }}</option>
                    <option value="afternoon">{{ $t('category.afternoon') }}</option>
                    <option value="evening">{{ $t('category.evening') }}</option>
                    <option value="custom">{{ $t('category.custom_time') }}</option>
                  </select>
                <svg class="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
              </div>
              <div class="relative">
                  <select v-model="sortBy" @change="onSortChange" class="appearance-none bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500 pr-8">
                    <option value="newest">{{ $t('category.newest') }}</option>
                    <option value="oldest">{{ $t('category.oldest') }}</option>
                    <option value="name_asc">{{ $t('category.name_az') }}</option>
                    <option value="name_desc">{{ $t('category.name_za') }}</option>
                    <option value="largest">{{ $t('category.largest') }}</option>
                    <option value="smallest">{{ $t('category.smallest') }}</option>
                    <option value="recently_updated">{{ $t('category.recently_updated') }}</option>
                  </select>
                <svg class="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
              </div>
              <button v-if="hasActiveFilters" @click="clearFilters" class="text-[11px] text-rose-600 hover:text-rose-700 font-medium px-2 py-1">{{ $t('category.clear') }}</button>
            </div>
            <div v-if="dateFilter === 'custom'" class="flex items-center gap-2">
              <input v-model="customDateFrom" type="date" @change="fetchFiles(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
              <span class="text-xs text-slate-400">{{ $t('category.to') }}</span>
              <input v-model="customDateTo" type="date" @change="fetchFiles(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>
            <div v-if="timeFilter === 'custom'" class="flex items-center gap-2">
              <input v-model="customTimeFrom" type="time" @change="fetchFiles(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
              <span class="text-xs text-slate-400">{{ $t('category.to') }}</span>
              <input v-model="customTimeTo" type="time" @change="fetchFiles(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>
          </div>

          <!-- Files Grid -->
          <div v-if="files.length > 0">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                {{ $t('category.files') }}
                <span v-if="searchQuery" class="text-slate-400 font-normal lowercase ms-1">({{ totalItems }} {{ $t('category.results') }})</span>
              </h4>
              <button v-if="canEdit && !showUploadArea" @click="showUploadArea = true" class="text-[11px] font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                {{ $t('workspace.upload_files') }}
              </button>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
              <div
                v-for="file in files" :key="file.id"
                class="group relative bg-slate-50 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden hover:shadow-sm transition-shadow cursor-pointer"
                @click="isMobile ? (activeSheetFile = file) : undefined"
              >
                <div @click="!isMobile ? openPreview(file) : undefined" class="aspect-[4/3] flex items-center justify-center overflow-hidden w-full relative">
                  <img v-if="file.thumbnail_url" :src="file.thumbnail_url" class="object-cover w-full h-full absolute inset-0 z-0" @error="e => {
                    const relativeUrl = '/api/v1/files/' + file.uuid + '/thumbnail';
                    if (!e.target.src.endsWith(relativeUrl)) {
                      e.target.src = relativeUrl;
                    } else {
                      e.target.style.display='none';
                      e.target.nextElementSibling?.classList.remove('hidden');
                    }
                  }" @load="e => {
                    console.log(`⏱️ Image Load Time [${file.uuid}]: ${performance.now().toFixed(2)}ms`)
                  }" loading="lazy" />
                  <div :class="{ 'hidden': file.thumbnail_url }" class="w-full h-full flex items-center justify-center z-10 bg-slate-50 dark:bg-slate-800">
                    <div v-if="file.mime_type?.startsWith('image/')" class="text-slate-400 flex items-center justify-center">
                      <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                    </div>
                    <div v-else class="text-slate-400 flex flex-col items-center justify-center p-2">
                      <svg v-if="file.mime_type?.startsWith('video/')" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                      <svg v-else-if="file.mime_type === 'application/pdf'" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                      <svg v-else class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                      <span class="text-[10px] font-medium mt-1 uppercase">{{ file.extension || 'FILE' }}</span>
                    </div>
                  </div>
                </div>
                <div class="p-1.5">
                  <p class="text-[11px] font-medium text-slate-800 dark:text-slate-200 truncate">{{ file.title || file.file_name }}</p>
                  <p class="text-[10px] text-slate-400">{{ formatSize(file.size) }}</p>
                </div>
                <FileActions
                  v-if="!isMobile"
                  :file="file"
                  :canEdit="canEdit"
                  mode="overlay"
                  :categories="allCategories"
                  @preview="openPreview"
                  @file-updated="updateFileLocally"
                  @file-moved="updateFileLocally"
                  @file-deleted="removeFileLocally($event.uuid)"
                />
              </div>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex items-center justify-center gap-1 mt-4">
              <button
                @click="goToPage(currentPage - 1)"
                :disabled="currentPage <= 1"
                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                :class="currentPage <= 1 ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
              >
                {{ $t('category.previous') }}
              </button>
              <template v-for="p in displayedPages" :key="p">
                <span v-if="p === '...'" class="px-2 py-1.5 text-xs text-slate-400">...</span>
                <button
                  v-else
                  @click="goToPage(p)"
                  class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                  :class="p === currentPage ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                >
                  {{ p }}
                </button>
              </template>
              <button
                @click="goToPage(currentPage + 1)"
                :disabled="currentPage >= totalPages"
                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                :class="currentPage >= totalPages ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
              >
                {{ $t('category.next') }}
              </button>
            </div>
          </div>

          <!-- Mobile Bottom Sheet -->
          <FileActions
            v-if="activeSheetFile"
            :file="activeSheetFile"
            :canEdit="canEdit"
            mode="sheet"
            :categories="allCategories"
            @preview="openPreview"
            @file-updated="updateFileLocally($event); activeSheetFile = null"
            @file-moved="updateFileLocally($event); activeSheetFile = null"
            @file-deleted="removeFileLocally($event.uuid); activeSheetFile = null"
            @close="activeSheetFile = null"
          />

          <!-- Search Empty State -->
          <div v-if="searchQuery && files.length === 0 && notes.length === 0 && !showUploadArea" class="text-center py-8 px-4">
            <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center">
              <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            </div>
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $t('category.no_results') }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500">{{ $t('category.no_results_desc') }}</p>
          </div>

          <!-- Upload Area -->
          <div v-if="showUploadArea" class="space-y-2">
            <div class="flex items-center justify-between">
              <span class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">{{ $t('workspace.upload_files') }}</span>
              <button @click="showUploadArea = false" class="text-[11px] font-medium text-slate-500 hover:text-rose-600 transition-colors">Cancel</button>
            </div>
            <!-- Upload Source Selector -->
            <div class="flex items-center gap-2">
              <button @click="captureCamera" class="flex-1 flex flex-col items-center gap-1.5 px-4 py-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:border-primary-400 dark:hover:border-primary-500 transition-colors">
                <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Camera</span>
              </button>
              <button @click="captureGallery" class="flex-1 flex flex-col items-center gap-1.5 px-4 py-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:border-primary-400 dark:hover:border-primary-500 transition-colors">
                <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Gallery</span>
              </button>
              <button @click="triggerFileInput" class="flex-1 flex flex-col items-center gap-1.5 px-4 py-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:border-primary-400 dark:hover:border-primary-500 transition-colors">
                <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Files</span>
              </button>
            </div>
            <div
              class="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-lg p-5 text-center cursor-pointer hover:border-primary-400 dark:hover:border-primary-500 transition-all hover:bg-primary-50/50 dark:hover:bg-primary-900/10"
              @click="triggerFileInput"
              @dragover.prevent="dragging = true"
              @dragleave.prevent="dragging = false"
              @drop.prevent="handleDrop"
              :class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 scale-[1.01]' : ''"
            >
              <input type="file" ref="fileInput" multiple class="sr-only" accept="image/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.7z,audio/*" @change="handleFileSelect" />
              <svg class="w-8 h-8 mx-auto text-slate-300 dark:text-slate-600 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
              <p class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $t('files.click_to_upload') }}</p>
              <p class="text-xs text-slate-400 mt-0.5">{{ $t('files.upload_hint') }}</p>
            </div>
          </div>

          <!-- Empty State (no filters, no files) -->
          <div v-if="!searchQuery && files.length === 0 && notes.length === 0 && !showUploadArea" class="text-center py-8 px-4">
            <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center">
              <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            </div>
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $t('category.empty_category') }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">{{ $t('category.empty_category_desc') }}</p>
            <div class="flex items-center justify-center gap-2">
              <button @click="closeMenuAndShowUpload" class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-xs font-medium transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                {{ $t('workspace.upload_files') }}
              </button>
              <button @click="addNote" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg text-xs font-medium transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                {{ $t('workspace.add_note') }}
              </button>
            </div>
          </div>

          <!-- Notes -->
          <div v-if="notes.length > 0">
            <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">
              Notes
              <span v-if="searchQuery" class="text-slate-400 font-normal lowercase ms-1">({{ notes.length }} results)</span>
            </h4>
            <div class="space-y-2">
              <div v-for="note in notes" :key="note.id" class="bg-slate-50 dark:bg-slate-800/50 rounded-lg p-3 border border-slate-100 dark:border-slate-700">
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
        <button type="button" @click="showNoteModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
        <BaseButton type="submit">{{ $t('workspace.add_note') }}</BaseButton>
      </div>
    </form>
  </WorkspaceModal>

  <!-- Add Visit Modal -->
  <WorkspaceModal :modelValue="showVisitModal" @update:modelValue="showVisitModal = false" :title="$t('workspace.add_visit')" size="sm">
    <form @submit.prevent="submitVisit" class="space-y-4">
      <input v-model="visitType" class="input-field w-full" :placeholder="$t('workspace.visit_type_placeholder')" required />
      <div class="flex justify-end gap-3">
        <button type="button" @click="showVisitModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
        <BaseButton type="submit">{{ $t('workspace.add_visit') }}</BaseButton>
      </div>
    </form>
  </WorkspaceModal>

  <!-- Rename Category Modal -->
  <WorkspaceModal :modelValue="showRenameModal" @update:modelValue="showRenameModal = false" :title="$t('workspace.rename_category')" size="sm">
    <form @submit.prevent="submitRename" class="space-y-4">
      <input v-model="renameValue" class="input-field w-full" :placeholder="$t('settings.category_name')" required />
      <div class="flex justify-end gap-3">
        <button type="button" @click="showRenameModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
        <BaseButton type="submit">{{ $t('workspace.rename_category') }}</BaseButton>
      </div>
    </form>
  </WorkspaceModal>

  <!-- Change Color Modal -->
  <WorkspaceModal :modelValue="showColorModal" @update:modelValue="showColorModal = false" :title="$t('workspace.change_color')" size="sm">
    <form @submit.prevent="submitColor" class="space-y-4">
      <div class="flex items-center gap-3">
        <input v-model="colorValue" type="color" class="w-12 h-12 rounded-lg border border-slate-200 dark:border-slate-700 cursor-pointer" />
        <input v-model="colorValue" class="input-field flex-1" :placeholder="$t('workspace.color_placeholder')" required />
      </div>
      <div class="flex gap-2">
        <button v-for="c in colorOptions" :key="c" type="button" @click="colorValue = c" class="w-7 h-7 rounded-full border-2 transition-all" :class="colorValue === c ? 'border-slate-900 dark:border-white scale-110' : 'border-transparent'" :style="{ backgroundColor: c }"></button>
      </div>
      <div class="flex justify-end gap-3 pt-2">
        <button type="button" @click="showColorModal = false" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $t('common.cancel') }}</button>
        <BaseButton type="submit">{{ $t('workspace.save_color') }}</BaseButton>
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

import FileActions from './FileActions.vue'
import { useNativeBridge } from '@/Composables/useNativeBridge'

const { isCameraAvailable, isFilePickerAvailable, takePhoto, pickFiles } = useNativeBridge()

const props = defineProps({
  slug: String,
  name: String,
  icon: { type: String, default: '📁' },
  color: { type: String, default: '#6b7280' },
  allCategories: { type: Array, default: () => [] },
})

const { toggleCategory, isCategoryExpanded, canEdit, selectedPatient, openPreview, refreshWorkspaceData, markCategoryLoaded, isCategoryLoaded, isMobile, allFiles, allNotes, updateFileLocally, removeFileLocally } = useWorkspace()
const { uploadFile, cancelUpload, pauseUpload, resumeUpload, retryUpload, uploads } = useUploads()
const dialog = useDialog()
const toast = useToast()

const expanded = computed(() => isCategoryExpanded(props.slug))
const hasLoaded = computed(() => isCategoryLoaded(props.slug))

// Pagination & filters (client-side — workspace already has all data)
const currentPage = ref(1)
const perPage = 6
const searchQuery = ref('')
const dateFilter = ref('')
const timeFilter = ref('')
const sortBy = ref('newest')
const customDateFrom = ref('')
const customDateTo = ref('')
const customTimeFrom = ref('')
const customTimeTo = ref('')
const initialLoadDone = ref(false)

const categoryFiles = computed(() => {
  return allFiles.value.filter(f => f.category === props.slug)
})
const categoryNotes = computed(() => {
  return allNotes.value.filter(n => n.category === props.slug)
})

const filteredFilesRaw = computed(() => {
  let result = [...categoryFiles.value]

  // Search
  const q = searchQuery.value.trim().toLowerCase()
  if (q) {
    result = result.filter(f =>
      (f.title && f.title.toLowerCase().includes(q)) ||
      (f.file_name && f.file_name.toLowerCase().includes(q)) ||
      (f.desc && f.desc.toLowerCase().includes(q)) ||
      (f.description && f.description.toLowerCase().includes(q)) ||
      (f.mime_type && f.mime_type.toLowerCase().includes(q)) ||
      (f.type && f.type.toLowerCase().includes(q))
    )
  }

  // Date filter
  const dr = getDateRange()
  if (dr.date_from) {
    const from = new Date(dr.date_from + 'T00:00:00')
    result = result.filter(f => f.created_at && new Date(f.created_at) >= from)
  }
  if (dr.date_to) {
    const to = new Date(dr.date_to + 'T23:59:59')
    result = result.filter(f => f.created_at && new Date(f.created_at) <= to)
  }

  // Time filter
  const tr = getTimeRange()
  if (tr.time_from) {
    const [h1, m1] = tr.time_from.split(':').map(Number)
    result = result.filter(f => {
      if (!f.created_at) return false
      const d = new Date(f.created_at)
      return d.getHours() > h1 || (d.getHours() === h1 && d.getMinutes() >= m1)
    })
  }
  if (tr.time_to) {
    const [h2, m2] = tr.time_to.split(':').map(Number)
    result = result.filter(f => {
      if (!f.created_at) return false
      const d = new Date(f.created_at)
      return d.getHours() < h2 || (d.getHours() === h2 && d.getMinutes() <= m2)
    })
  }

  // Sort
  switch (sortBy.value) {
    case 'oldest':
      result.sort((a, b) => new Date(a.created_at || 0) - new Date(b.created_at || 0))
      break
    case 'name_asc':
      result.sort((a, b) => (a.file_name || '').localeCompare(b.file_name || ''))
      break
    case 'name_desc':
      result.sort((a, b) => (b.file_name || '').localeCompare(a.file_name || ''))
      break
    case 'largest':
      result.sort((a, b) => (b.size || 0) - (a.size || 0))
      break
    case 'smallest':
      result.sort((a, b) => (a.size || 0) - (b.size || 0))
      break
    case 'recently_updated':
      result.sort((a, b) => new Date(b.updated_at || 0) - new Date(a.updated_at || 0))
      break
    case 'newest':
    default:
      result.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))
      break
  }

  return result
})

const totalItems = computed(() => filteredFilesRaw.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalItems.value / perPage)))
const notesCount = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return categoryNotes.value.length
  return categoryNotes.value.filter(n =>
    (n.content && n.content.toLowerCase().includes(q))
  ).length
})

// Paginated file slice
const files = computed(() => {
  const start = (currentPage.value - 1) * perPage
  return filteredFilesRaw.value.slice(start, start + perPage)
})

// Filtered notes (only search — no pagination)
const notes = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return categoryNotes.value
  return categoryNotes.value.filter(n =>
    (n.content && n.content.toLowerCase().includes(q))
  )
})

const hasActiveFilters = computed(() => {
  return searchQuery.value || dateFilter.value || timeFilter.value || sortBy.value !== 'newest'
})

const displayedPages = computed(() => {
  const pages = []
  const total = totalPages.value
  const current = currentPage.value
  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i)
    return pages
  }
  pages.push(1)
  if (current > 3) pages.push('...')
  const start = Math.max(2, current - 1)
  const end = Math.min(total - 1, current + 1)
  for (let i = start; i <= end; i++) pages.push(i)
  if (current < total - 2) pages.push('...')
  pages.push(total)
  return pages
})

const activeUploads = computed(() => {
  return uploads.value.filter(j => j.metadata?.category === props.slug && j.status !== 'completed' && j.status !== 'cancelled')
})

function getDateRange() {
  const now = new Date()
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const d = String(now.getDate()).padStart(2, '0')
  const today = `${y}-${m}-${d}`
  switch (dateFilter.value) {
    case 'today':
      return { date_from: today, date_to: today }
    case 'yesterday': {
      const yest = new Date(now)
      yest.setDate(yest.getDate() - 1)
      const yStr = `${yest.getFullYear()}-${String(yest.getMonth() + 1).padStart(2, '0')}-${String(yest.getDate()).padStart(2, '0')}`
      return { date_from: yStr, date_to: yStr }
    }
    case 'last7': {
      const d7 = new Date(now)
      d7.setDate(d7.getDate() - 6)
      return { date_from: `${d7.getFullYear()}-${String(d7.getMonth() + 1).padStart(2, '0')}-${String(d7.getDate()).padStart(2, '0')}`, date_to: today }
    }
    case 'last30': {
      const d30 = new Date(now)
      d30.setDate(d30.getDate() - 29)
      return { date_from: `${d30.getFullYear()}-${String(d30.getMonth() + 1).padStart(2, '0')}-${String(d30.getDate()).padStart(2, '0')}`, date_to: today }
    }
    case 'this_month':
      return { date_from: `${y}-${m}-01`, date_to: today }
    case 'last_month': {
      const lm = new Date(y, now.getMonth(), 0)
      const lmStr = `${lm.getFullYear()}-${String(lm.getMonth() + 1).padStart(2, '0')}`
      return { date_from: `${lmStr}-01`, date_to: `${lmStr}-${String(lm.getDate()).padStart(2, '0')}` }
    }
    case 'this_year':
      return { date_from: `${y}-01-01`, date_to: today }
    default:
      return {}
  }
}

function getTimeRange() {
  switch (timeFilter.value) {
    case 'morning':
      return { time_from: '08:00', time_to: '12:00' }
    case 'afternoon':
      return { time_from: '13:00', time_to: '16:00' }
    case 'evening':
      return { time_from: '18:00', time_to: '23:00' }
    default:
      return {}
  }
}

function goToPage(page) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
}

function onDateFilterChange() {
  if (dateFilter.value !== 'custom') {
    customDateFrom.value = ''
    customDateTo.value = ''
  }
  currentPage.value = 1
}

function onTimeFilterChange() {
  if (timeFilter.value !== 'custom') {
    customTimeFrom.value = ''
    customTimeTo.value = ''
  }
  currentPage.value = 1
}

function onSortChange() {
  currentPage.value = 1
}

function clearFilters() {
  searchQuery.value = ''
  dateFilter.value = ''
  timeFilter.value = ''
  sortBy.value = 'newest'
  customDateFrom.value = ''
  customDateTo.value = ''
  customTimeFrom.value = ''
  customTimeTo.value = ''
  currentPage.value = 1
}

// Debounced search — resets to page 1
let searchTimer
watch(searchQuery, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { currentPage.value = 1 }, 350)
})

// Mark category loaded when expanded for the first time
watch(expanded, (val) => {
  if (val) {
    markCategoryLoaded(props.slug)
    initialLoadDone.value = true
  }
}, { immediate: true })

// After upload completes, refresh entire workspace
const localCompleteCount = ref(0)
watch(uploads, (list) => {
  let c = 0
  for (let i = 0; i < list.length; i++) {
    const j = list[i]
    if (j.metadata?.category === props.slug && j.status === 'completed') c++
  }
  if (c > localCompleteCount.value) {
    localCompleteCount.value = c
    refreshWorkspaceData()
    toast.success('Upload complete')
  }
}, { flush: 'post' })

// UI state
const showUploadArea = ref(false)
const showCategoryMenu = ref(false)
const dragging = ref(false)
const fileInput = ref(null)
const menuRef = ref(null)
const menuStyle = ref({})
const activeSheetFile = ref(null)
const colorOptions = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#14b8a6', '#6b7280']

const showNoteModal = ref(false)
const showVisitModal = ref(false)
const showRenameModal = ref(false)
const showColorModal = ref(false)
const noteContent = ref('')
const visitType = ref('')
const renameValue = ref('')
const colorValue = ref('')

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

function closeMenuAndShowUpload() {
  showCategoryMenu.value = false
  showUploadArea.value = true
}

const defaultAccept = 'image/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.7z,audio/*'

async function handleNativeFileResult(fileData) {
  if (!fileData) return
  const patientId = selectedPatient.value?.id
  if (!patientId) return

  let file = fileData
  if (!(fileData instanceof File) && fileData.uri) {
    try {
      const blob = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest()
        xhr.open('GET', fileData.uri, true)
        xhr.responseType = 'blob'
        xhr.onload = () => {
          if (xhr.status === 200 || xhr.status === 0) resolve(xhr.response)
          else reject(new Error(`HTTP ${xhr.status}`))
        }
        xhr.onerror = () => reject(new Error('Network error'))
        xhr.send()
      })
      file = new File([blob], fileData.name || 'file', { type: fileData.type || blob.type })
    } catch (e) {
      console.warn('[CategoryBlock] Failed to read native file:', e)
      return
    }
  }

  console.log('UPLOAD_STARTED', { name: file.name, size: file.size })
  const uploadJob = uploadFile(file, patientId, { category: props.slug })
  const unwatch = watch(() => uploadJob.status, (status) => {
    if (status === 'completed') {
      unwatch()
      console.log('UPLOAD_FINISHED', { name: file.name })
    } else if (status === 'failed' || status === 'cancelled') {
      unwatch()
      console.warn('UPLOAD_FAILED', { name: file.name, error: uploadJob.error })
    }
  })
}

async function captureCamera() {
  console.log('USER_CLICK', { source: 'camera' })
  if (isCameraAvailable()) {
    console.log('OPEN_PICKER', { source: 'camera', method: 'native' })
    const photo = await takePhoto()
    console.log('PICKER_RETURNED', { source: 'camera', hasPhoto: !!photo })
    if (photo) {
      handleNativeFileResult(photo)
    }
    return
  }
  console.log('OPEN_PICKER', { source: 'camera', method: 'html-input' })
  if (fileInput.value) {
    fileInput.value.accept = 'image/*'
    fileInput.value.setAttribute('capture', 'environment')
    fileInput.value.click()
  }
}

async function captureGallery() {
  console.log('USER_CLICK', { source: 'gallery' })
  if (isFilePickerAvailable()) {
    console.log('OPEN_PICKER', { source: 'gallery', method: 'native' })
    const files = await pickFiles({ multiple: true, accept: 'image/*' })
    console.log('PICKER_RETURNED', { source: 'gallery', count: files?.length ?? 0 })
    if (files && files.length > 0) {
      for (const f of files) {
        handleNativeFileResult(f)
      }
    }
    return
  }
  console.log('OPEN_PICKER', { source: 'gallery', method: 'html-input' })
  if (fileInput.value) {
    fileInput.value.accept = 'image/*'
    fileInput.value.removeAttribute('capture')
    fileInput.value.click()
  }
}

async function triggerFileInput() {
  console.log('USER_CLICK', { source: 'files' })
  if (isFilePickerAvailable()) {
    console.log('OPEN_PICKER', { source: 'files', method: 'native' })
    const files = await pickFiles({ multiple: true, accept: defaultAccept })
    console.log('PICKER_RETURNED', { source: 'files', count: files?.length ?? 0 })
    if (files && files.length > 0) {
      for (const f of files) {
        handleNativeFileResult(f)
      }
    }
    return
  }
  console.log('OPEN_PICKER', { source: 'files', method: 'html-input' })
  if (fileInput.value) {
    fileInput.value.accept = defaultAccept
    fileInput.value.removeAttribute('capture')
    fileInput.value.click()
  }
}

function handleFileSelect(e) {
  console.log('[CategoryBlock] File input change event, files:', e.target.files?.length)
  handleFiles(Array.from(e.target.files))
  e.target.value = null
}

function handleDrop(e) {
  console.log('[CategoryBlock] Drop event, files:', e.dataTransfer.files?.length)
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
.accordion-enter-active, .accordion-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; overflow: hidden; }
.accordion-enter-from, .accordion-leave-to { opacity: 0; transform: translateY(-8px); }
.accordion-enter-to, .accordion-leave-from { opacity: 1; transform: translateY(0); }
</style>
