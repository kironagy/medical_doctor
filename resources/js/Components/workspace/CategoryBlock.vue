<template>
  <div class="border-2 border-primary-300 dark:border-primary-800 rounded-xl overflow-hidden bg-white dark:bg-slate-900">
    <div class="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
      <button @click="toggleCategory(slug)" class="flex items-center gap-2 flex-1 text-right text-primary-700 dark:text-primary-400">
        <h3 class="text-base font-bold">{{ name }}</h3>
      </button>

      <div class="flex items-center gap-2">
        <button @click.stop="openAddRecord('text')" class="btn-primary !px-3 !py-1.5 flex items-center gap-1 text-xs">
          إضافة (Add)
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
        </button>

        <div class="relative">

          <Teleport to="body">
            <div v-if="showCategoryMenu" class="fixed inset-0 z-[200]" @click="showCategoryMenu = false"></div>
            <div v-if="showCategoryMenu" ref="menuRef" class="fixed z-[200] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl py-1.5 min-w-[150px]" :style="menuStyle">
              <button @click="openRename" class="w-full flex items-center gap-2 px-3 py-2 text-xs text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-right">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                تعديل الاسم
              </button>
              <button @click="deleteCategory" class="w-full flex items-center gap-2 px-3 py-2 text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors text-right">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                حذف التصنيف
              </button>
            </div>
          </Teleport>
        </div>
      </div>
    </div>

    <Transition name="accordion">
      <div v-if="expanded" class="border-t border-slate-100 dark:border-slate-800">
        <div v-if="hasLoaded && !loading" class="p-4 space-y-4">

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
                  {{ job.status === 'uploading' ? formatSpeed(job.speed) : job.status === 'failed' ? (job.error || $t('files.status_failed')) : job.status }}
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
              <input v-model="customDateFrom" type="date" @change="loadCategoryData(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
              <span class="text-xs text-slate-400">{{ $t('category.to') }}</span>
              <input v-model="customDateTo" type="date" @change="loadCategoryData(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>
            <div v-if="timeFilter === 'custom'" class="flex items-center gap-2">
              <input v-model="customTimeFrom" type="time" @change="loadCategoryData(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
              <span class="text-xs text-slate-400">{{ $t('category.to') }}</span>
              <input v-model="customTimeTo" type="time" @change="loadCategoryData(1)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>
          </div>

          <!-- Files/Notes Grid -->
          <div v-if="mergedCategoryItems.length > 0">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                المحفوظات
                <span v-if="searchQuery" class="text-slate-400 font-normal lowercase ms-1">({{ totalItems }} {{ $t('category.results') }})</span>
              </h4>
            </div>
            <div class="flex flex-wrap gap-2.5 md:gap-3.5">
              <div
                v-for="item in paginatedItems" :key="item.id || item.uuid"
                class="bg-[#e6fbf7] dark:bg-slate-900 border-2 border-[#ccfbf1] dark:border-teal-950/40 rounded-[20px] p-3 flex flex-col w-[calc(50%-5px)] sm:w-[170px] md:w-[180px] lg:w-[190px] xl:w-[200px] min-h-[200px] transition-all"
              >
                <!-- Card Header (Date) -->
                <div class="flex items-center justify-between text-xs font-bold text-teal-800 dark:text-teal-400 mb-2">
                  <div class="flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-teal-600 dark:text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                    <span>{{ item.created_at ? new Date(item.created_at).toISOString().split('T')[0] : '—' }}</span>
                    <!-- Phase 7: Offline sync status badge -->
                    <span v-if="item.sync_status && item.sync_status !== 'synced'"
                          class="px-1.5 py-0.5 rounded text-[9px] font-bold whitespace-nowrap"
                          :class="syncStatusBadgeClass(item.sync_status)">
                      {{ offlineStatusIcon(item.sync_status) }} {{ offlineStatusLabel(item.sync_status) }}
                    </span>
                  </div>
                </div>

                <!-- Preview Area -->
                <div class="aspect-[4/3] w-full bg-white dark:bg-slate-950 border border-teal-100 dark:border-slate-800 rounded-2xl flex items-center justify-center relative overflow-hidden mb-2.5 cursor-pointer" @click="handlePreviewClick(item)">
                  <template v-if="item.type === 'note'">
                    <div @click.stop="viewNoteContent(item)" class="w-full h-full flex items-center justify-center bg-teal-50/10 text-teal-600 dark:text-teal-400">
                      <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16m-7 6h7" />
                      </svg>
                    </div>
                  </template>
                  <template v-else>
                    <img
                      v-if="item.thumbnail_url"
                      :src="item.thumbnail_url"
                      class="object-cover w-full h-full absolute inset-0 z-0"
                      @error="onThumbError($event, item)"
                    />

                    <!-- Fallback / Play icon overlay for Video -->
                    <div v-if="item.mime_type?.startsWith('video/')" class="absolute inset-0 z-20 flex items-center justify-center pointer-events-none" :class="{ 'bg-slate-900/40': item.thumbnail_url }">
                      <div class="w-10 h-10 bg-white/90 dark:bg-slate-800/90 rounded-full flex items-center justify-center backdrop-blur-sm shadow-sm">
                        <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400 translate-x-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                      </div>
                    </div>

                    <!-- Generic fallback for image without thumbnail -->
                    <div class="w-full h-full flex items-center justify-center bg-slate-50 dark:bg-slate-950">
                      <div v-if="item.mime_type?.startsWith('image/')" class="text-slate-400 flex items-center justify-center">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                      </div>
                      <div v-else class="text-slate-400 flex flex-col items-center justify-center p-2">
                        <svg v-if="item.mime_type?.startsWith('video/')" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                        <svg v-else-if="item.mime_type === 'application/pdf'" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                        <svg v-else class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        <span class="text-[9px] font-bold mt-1 uppercase">{{ item.extension || 'FILE' }}</span>
                      </div>
                    </div>
                  </template>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-1.5 mt-auto">
                  <template v-if="item.type === 'note'">
                    <button v-if="canDelete" type="button" @click.stop="deleteNoteDirectly(item)" class="flex-1 py-1.5 text-center bg-rose-50 hover:bg-rose-100 text-rose-600 dark:bg-rose-950/30 dark:text-rose-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                      مسح
                    </button>
                    <button type="button" @click.stop="viewNoteContent(item)" class="flex-1 py-1.5 text-center bg-blue-50 hover:bg-blue-100 text-blue-600 dark:bg-blue-950/30 dark:text-blue-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                      عرض
                    </button>
                  </template>
                  <template v-else>
                    <button v-if="canDelete" type="button" @click.stop="deleteFileDirectly(item)" class="flex-1 py-1.5 text-center bg-rose-50 hover:bg-rose-100 text-rose-600 dark:bg-rose-950/30 dark:text-rose-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                      مسح
                    </button>
                    <button v-if="item.mime_type?.startsWith('image/')" type="button" @click.stop="printFile(item)" class="flex-1 py-1.5 text-center bg-blue-50 hover:bg-blue-100 text-blue-600 dark:bg-blue-950/30 dark:text-blue-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                      طباعة
                    </button>
                    <!-- Phase 7: Offline file with retry button -->
                    <button v-else-if="item.sync_status === 'failed'" type="button" @click.stop="retryOfflineUpload(item)" class="flex-1 py-1.5 text-center bg-amber-50 hover:bg-amber-100 text-amber-600 dark:bg-amber-950/30 dark:text-amber-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                      إعادة المحاولة
                    </button>
                    <!-- Phase 7: Offline pending file (no download yet, just retry/delete) -->
                    <button v-else-if="item.sync_status === 'pending_upload' || item.sync_status === 'uploading'" type="button" @click.stop="deleteOfflineUpload(item)" class="flex-1 py-1.5 text-center bg-rose-50 hover:bg-rose-100 text-rose-600 dark:bg-rose-950/30 dark:text-rose-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                      إلغاء
                    </button>
                    <a v-else :href="item.url" download class="flex-1 py-1.5 text-center bg-blue-50 hover:bg-blue-100 text-blue-600 dark:bg-blue-950/30 dark:text-blue-400 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 transition-all" target="_blank" @click.stop>
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                      تحميل
                    </a>
                  </template>
                </div>
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
            :canDelete="canDelete"
            mode="sheet"
            :categories="allCategories"
            @preview="openPreview"
            @file-updated="handleFileUpdated($event); activeSheetFile = null"
            @file-moved="handleFileUpdated($event); activeSheetFile = null"
            @file-deleted="handleFileDeleted($event); activeSheetFile = null"
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

          <!-- Empty State (no filters, no files) -->
          <div v-if="!searchQuery && files.length === 0 && notes.length === 0" class="text-center py-8 px-4">
            <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center">
              <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            </div>
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $t('category.empty_category') }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">{{ $t('category.empty_category_desc') }}</p>
            <div class="flex items-center justify-center gap-2">
              <button @click="openAddRecord('file')" class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-xs font-medium transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                {{ $t('workspace.upload_files') }}
              </button>
              <button @click="openAddRecord('text')" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg text-xs font-medium transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                {{ $t('workspace.add_note') }}
              </button>
            </div>
          </div>
        </div>
        <div v-else-if="loading" class="p-6 text-center">
          <div class="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
        </div>
        <div v-else class="p-6 text-center">
          <div class="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
        </div>
      </div>
    </Transition>
  </div>


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

  <!-- Add Record Modal (Add) -->
  <AddRecordModal
    v-model="showAddRecordModal"
    :patient="selectedPatient"
    :categorySlug="slug"
    :initialTab="addRecordModalTab"
    @noteAdded="onNoteAdded"
  />

  <!-- View Note Content Modal -->
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="showViewNoteModal && activeViewNote" class="fixed inset-0 z-[150] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showViewNoteModal = false"></div>

        <!-- Modal Box -->
        <div class="relative bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-lg p-8 flex flex-col border border-slate-100 dark:border-slate-800 animate-scale-up text-start">
          <!-- Close Button -->
          <button @click="showViewNoteModal = false" class="absolute top-4 left-4 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <!-- Header -->
          <div class="flex items-center justify-between border-b border-teal-100 dark:border-teal-900 pb-3.5 mb-5">
            <h2 class="text-lg font-black text-teal-600 dark:text-teal-400">{{ name }}</h2>
            <span class="text-xs font-bold text-slate-400">{{ activeViewNote.created_at ? new Date(activeViewNote.created_at).toISOString().split('T')[0] : '' }}</span>
          </div>

          <!-- Content -->
          <div class="text-lg md:text-xl font-medium text-slate-700 dark:text-slate-300 leading-relaxed overflow-y-auto max-h-[60vh] py-2" v-html="activeViewNote.content">
          </div>

          <!-- Footer -->
          <div class="flex justify-end mt-6">
            <button @click="showViewNoteModal = false" class="px-8 py-2.5 bg-teal-50 hover:bg-teal-100 dark:bg-slate-800 dark:hover:bg-slate-700 text-teal-600 dark:text-teal-400 rounded-xl text-sm font-bold transition-all">
              إغلاق
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
// FIX-PERF-8: trace() now checks upload_debug localStorage flag before POSTing.
// On device each trace POST is a full PHP boot contending for g_php_request_mutex
// against the actual chunk uploads. Previously unconditional.
const _uploadDebug = typeof localStorage !== 'undefined' && localStorage.getItem('upload_debug') === '1';
const trace = (msg) => {
  if (!_uploadDebug) return;
  try { fetch('/_native/api/debug/trace', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({message:msg})}).catch(()=>{}); } catch(e) {}
}
import { ref, computed, watch, nextTick } from 'vue'
import { useWorkspace } from '@/Composables/useWorkspace'
import { useUploads } from '@/Composables/useUploads'
import { useDialog } from '@/Composables/useDialog'
import { useToast } from '@/Composables/useToast'
import WorkspaceModal from './WorkspaceModal.vue'
import AddRecordModal from './AddRecordModal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import axios from 'axios'

import FileActions from './FileActions.vue'
import { useNativeBridge } from '@/Composables/useNativeBridge'
import { useOfflineUploads } from '@/Composables/useOfflineUploads'
import { useSyncEngine } from '@/Composables/useSyncEngine'
import { isNativeApp, apiUrl, getApiConfig } from '@/Utils/api'

const { isCameraAvailable, isFilePickerAvailable, takePhoto, pickFiles, detectNative } = useNativeBridge()

// Raw binary streamed through the NativePHP WebView bridge does not
// reliably reach <img> elements (confirmed on-device: identical 200 OK +
// correct content-length on every retry, thumbnail never renders).
// Base64/JSON survives the same bridge intact, so retry image thumbnails
// that way before giving up.
async function onThumbError(e, item) {
  if (e.target.dataset.b64Tried) {
    e.target.style.display = 'none'
    return
  }
  e.target.dataset.b64Tried = '1'
  if (detectNative() && item.mime_type?.startsWith('image/') && item.uuid) {
    try {
      const res = await axios.get(`/_native/cache/files/${item.uuid}/base64`)
      e.target.src = `data:${res.data.mime};base64,${res.data.data}`
      return
    } catch (err) {
      // fall through to the URL fallback below
    }
  }
  const fallback = item.url || null
  if (fallback) {
    e.target.src = fallback
  } else {
    e.target.style.display = 'none'
  }
}
const { statusIcon: offlineStatusIcon, statusLabel: offlineStatusLabel, uploadFile: offlineUploadFile } = useOfflineUploads()
const { isOnline: syncOnline } = useSyncEngine()

const props = defineProps({
  slug: String,
  name: String,
  icon: { type: String, default: '📁' },
  color: { type: String, default: '#6b7280' },
  allCategories: { type: Array, default: () => [] },
})

const { toggleCategory, isCategoryExpanded, canEdit, canDelete, selectedPatient, openPreview, refreshWorkspaceData, markCategoryLoaded, isCategoryLoaded, isMobile, allFiles, allNotes, updateFileLocally, removeFileLocally, workspaceData } = useWorkspace()
const { uploadFile: onlineUploadFile, cancelUpload, pauseUpload, resumeUpload, retryUpload, uploads } = useUploads()

// ── Phase 7: Route file uploads to local embedded uploader when running on Mobile App ──
// On the mobile app (NativePHP Android), ALL uploads write locally to embedded SQLite + storage
// so the file appears in the app immediately without network delay or server auth blocking.
// The SyncEngine background service pushes local files to the remote server automatically.
const uploadFile = (file, patientId, options) => {
  const isOnline = syncOnline.value;
  const isNative = isNativeApp() || detectNative();
  trace('[TRACE_F1] CategoryBlock.uploadFile() ENTERED - fileName: ' + file?.name + ' patientId: ' + patientId + ' isOnline: ' + isOnline + ' isNative: ' + isNative)
  if ((isNative || !isOnline) && typeof offlineUploadFile === 'function') {
    trace('[TRACE_F2] MOBILE/OFFLINE - calling offlineUploadFile() from useOfflineUploads')
    const patientUuid = selectedPatient.value?.uuid || patientId
    return offlineUploadFile(file, patientUuid, options);
  }
  if (typeof onlineUploadFile === 'function') {
    trace('[TRACE_F2b] ONLINE WEBSITE - calling onlineUploadFile() from useUploads')
    return onlineUploadFile(file, patientId, options);
  }
  trace('[TRACE_F2c] NEITHER available - returning null!')
  return null;
}
function handlePreviewClick(item) {
  if (item.type === 'note') return
  openPreview(item, serverFiles.value, (page) => loadMoreForPreview(page))
}

const dialog = useDialog()
const toast = useToast()

const expanded = computed(() => isCategoryExpanded(props.slug))
const hasLoaded = computed(() => isCategoryLoaded(props.slug))

// Pagination & filters (server-side now)
const currentPage = ref(1)
const perPage = 12
const searchQuery = ref('')
const dateFilter = ref('')
const timeFilter = ref('')
const sortBy = ref('newest')
const customDateFrom = ref('')
const customDateTo = ref('')
const customTimeFrom = ref('')
const customTimeTo = ref('')
const initialLoadDone = ref(false)
const loading = ref(false)
const serverFiles = ref([])
const serverNotes = ref([])
const serverMeta = ref({ total: 0, current_page: 1, last_page: 1 })

// Lazy load category data when expanded
async function loadCategoryData(page = 1) {
  if (!selectedPatient.value?.uuid) return

  loading.value = true
  try {
    const params = {
      page,
      per_page: perPage,
      sort: sortBy.value
    }

    if (searchQuery.value) params.search = searchQuery.value
    if (dateFilter.value && customDateFrom.value) params.date_from = customDateFrom.value
    if (dateFilter.value && customDateTo.value) params.date_to = customDateTo.value
    if (timeFilter.value && customTimeFrom.value) params.time_from = customTimeFrom.value
    if (timeFilter.value && customTimeTo.value) params.time_to = customTimeTo.value

    // Fetch category files from API
    let response = null;
    let serverRequestFailed = false;
    let serverStatus = 200;
    try {
      response = await axios.get(`/api/v1/patients/${selectedPatient.value.uuid}/categories/${props.slug}/files`, { params });
    } catch (err) {
      serverRequestFailed = true;
      serverStatus = err.response?.status || 0;
    }

    const patientStatus = selectedPatient.value?.sync_status;
    const isPendingPatient = !patientStatus || patientStatus !== 'synced';
    const benignFailure = serverRequestFailed && (serverStatus === 404 || isPendingPatient);

    const freshServerFiles = !serverRequestFailed ? (response.data.data || []) : [];
    const freshServerNotes = !serverRequestFailed ? (response.data.notes || []) : [];
    const freshFileUuids = new Set(freshServerFiles.map(f => f.uuid));

    // allFiles is the patient's full unpaginated file list — a file missing from
    // freshFileUuids (this page's server response) is usually just on a different
    // page, not a new unsynced upload. Only merge in genuinely pending ones, or
    // every page ends up showing the patient's entire file list (see the same
    // fix applied to categoryFiles/totalItems below).
    const workspaceLocalFiles = (allFiles.value || []).filter(
      f => f.category === props.slug && !freshFileUuids.has(f.uuid) && f.sync_status !== 'synced'
    );

    // allFiles comes from the workspace payload, which for a SYNCED patient is
    // fetched from production — so it contains no device-only files and the
    // merge above had nothing to add. That is why every not-yet-uploaded file
    // vanished from the patient the moment the patient itself synced, while
    // still showing as pending in the sync centre. Ask the embedded server
    // directly for the files whose bytes are still only on this device.
    let deviceOnlyFiles = [];
    if (detectNative() && selectedPatient.value?.uuid) {
      try {
        const localRes = await axios.get(
          `/_native/api/patients/${selectedPatient.value.uuid}/categories/${props.slug}/local-files`
        );
        const seen = new Set([
          ...freshFileUuids,
          ...workspaceLocalFiles.map(f => f.uuid),
        ]);
        deviceOnlyFiles = (localRes.data?.data || []).filter(f => !seen.has(f.uuid));
      } catch (err) {
        console.warn('[CategoryBlock] local-files unavailable', err);
      }
    }

    const mergedLocalFiles = [...deviceOnlyFiles, ...workspaceLocalFiles];

    serverFiles.value = mergedLocalFiles.length > 0
      ? [...mergedLocalFiles, ...freshServerFiles]
      : freshServerFiles;

    initialLoadDone.value = true;

    const freshNoteUuids = new Set(freshServerNotes.map(n => n.uuid));
    const workspaceLocalNotes = (allNotes.value || []).filter(
      n => n.category === props.slug && !freshNoteUuids.has(n.uuid)
    );

    serverNotes.value = workspaceLocalNotes.length > 0
      ? [...workspaceLocalNotes, ...freshServerNotes]
      : freshServerNotes;

    serverMeta.value = !serverRequestFailed
      ? (response.data.meta || { total: freshServerFiles.length, current_page: 1, last_page: 1 })
      : { total: mergedLocalFiles.length, current_page: 1, last_page: Math.max(1, Math.ceil(mergedLocalFiles.length / perPage)) };
    currentPage.value = serverMeta.value.current_page;

    if (serverRequestFailed) {
      console.warn('Category server data unavailable (using local SQLite fallback)', response?.reason?.message)
    }
  } catch (e) {
    console.warn('Category data fetch exception (using local SQLite fallback)', e)
  } finally {
    loading.value = false
  }
}

async function loadMoreForPreview(currentPageForPreview) {
  if (!selectedPatient.value?.uuid) return []
  if (currentPageForPreview >= serverMeta.value.last_page) return []

  try {
    const params = {
      page: currentPageForPreview + 1,
      per_page: perPage,
      sort: sortBy.value
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (dateFilter.value && customDateFrom.value) params.date_from = customDateFrom.value
    if (dateFilter.value && customDateTo.value) params.date_to = customDateTo.value
    if (timeFilter.value && customTimeFrom.value) params.time_from = customTimeFrom.value
    if (timeFilter.value && customTimeTo.value) params.time_to = customTimeTo.value

    const response = await axios.get(`/api/v1/patients/${selectedPatient.value.uuid}/categories/${props.slug}/files`, { params })
    return response.data.data || []
  } catch (e) {
    console.error('Failed to load more for preview', e)
    return []
  }
}

const categoryFiles = computed(() => {
  const localFiles = (allFiles.value || []).filter(f => f.category === props.slug)
  if (initialLoadDone.value) {
    const serverUuids = new Set((serverFiles.value || []).map(f => f.uuid))
    // allFiles is the patient's FULL unpaginated file list (see comment above on
    // its origin), so "not in this page's serverFiles" alone doesn't mean "new,
    // not yet synced" — most files simply live on a different page. Only merge
    // in ones that are genuinely still pending upload; anything already synced
    // just isn't on this page and must not be force-added, or every page ends up
    // showing the same full set.
    const newLocalFiles = localFiles.filter(f => f && f.uuid && !serverUuids.has(f.uuid) && f.sync_status !== 'synced')
    return newLocalFiles.length > 0 ? [...newLocalFiles, ...serverFiles.value] : serverFiles.value
  }
  return localFiles
})
const categoryNotes = computed(() => {
	const allNotesList = allNotes.value || []
	const localNotes = allNotesList.filter(n => n.category === props.slug)
	if (initialLoadDone.value && serverNotes.value.length > 0) {
    // ── Merge: include new local notes not yet in serverNotes ──────
    // This is the same pattern used by the `files` computed below.
    // Without this merge, newly created notes are invisible because
    // serverNotes is only refreshed on explicit loadCategoryData().
    const serverUuids = new Set(serverNotes.value.map(n => n.uuid))
    const newLocalNotes = localNotes.filter(n => !serverUuids.has(n.uuid))
    const result = newLocalNotes.length > 0 ? [...newLocalNotes, ...serverNotes.value] : serverNotes.value
    return result
  }
  return localNotes
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

const totalItems = computed(() => {
  if (initialLoadDone.value && serverMeta.value.total !== undefined) {
    const serverUuids = new Set(serverFiles.value.map(f => f.uuid))
    const newCount = allFiles.value.filter(f => f.category === props.slug && !serverUuids.has(f.uuid) && f.sync_status !== 'synced').length
    return serverMeta.value.total + newCount
  }
  return filteredFilesRaw.value.length
})

const totalPages = computed(() => {
  if (initialLoadDone.value && serverMeta.value.last_page !== undefined) {
    return serverMeta.value.last_page
  }
  return Math.max(1, Math.ceil(totalItems.value / perPage))
})

const notesCount = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return categoryNotes.value.length
  return categoryNotes.value.filter(n =>
    (n.content && n.content.toLowerCase().includes(q))
  ).length
})

// Sync deletions globally
watch(() => allFiles.value, (newAllFiles) => {
  if (initialLoadDone.value && serverFiles.value.length > 0) {
    const allUuids = new Set(newAllFiles.map(f => f.uuid));
    const originalLen = serverFiles.value.length;
    serverFiles.value = serverFiles.value.filter(f => allUuids.has(f.uuid));
    if (serverFiles.value.length < originalLen) {
      serverMeta.value = { ...serverMeta.value, total: Math.max(0, (serverMeta.value.total || 0) - (originalLen - serverFiles.value.length)) };
      serverFiles.value = [...serverFiles.value];
    }
  }
}, { deep: false });



// filteredFilesRaw is already windowed to the current page: loadCategoryData()
// fetches with page/per_page and serverFiles holds only that page's rows. Re-slicing
// here by currentPage was double-paginating an already-paginated array — page 2
// sliced indices [6:12] out of a 4-item page-2 result, always empty past page 1.
const files = computed(() => filteredFilesRaw.value)

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
  const puuid = selectedPatient.value?.uuid
  const pid = selectedPatient.value?.id
  if (!puuid && !pid) return []
  return uploads.value.filter(j =>
    (j.patientId === puuid || j.patientId === pid || String(j.patientId) === String(pid) || String(j.patientId) === String(puuid)) &&
    j.metadata?.category === props.slug &&
    j.status !== 'completed' &&
    j.status !== 'cancelled'
  )
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

function handleFileUpdated(updatedFile) {
  if (!updatedFile?.uuid) return
  updateFileLocally(updatedFile)
  if (initialLoadDone.value && serverFiles.value.length > 0) {
    const idx = serverFiles.value.findIndex(f => f.uuid === updatedFile.uuid)
    if (idx !== -1) {
      serverFiles.value[idx] = { ...serverFiles.value[idx], ...updatedFile }
      serverFiles.value = [...serverFiles.value]
    }
  }
}

function handleFileDeleted(file) {
  if (!file?.uuid) return
  removeFileLocally(file.uuid)
  if (initialLoadDone.value && serverFiles.value.length > 0) {
    serverFiles.value = serverFiles.value.filter(f => f.uuid !== file.uuid)
    serverMeta.value = { ...serverMeta.value, total: Math.max(0, (serverMeta.value.total || 0) - 1) }
    serverFiles.value = [...serverFiles.value]
  }
}

async function deleteFileDirectly(file) {
  if (!file?.uuid) return
  const confirmed = await dialog.confirm({
    title: 'حذف الملف',
    message: `هل أنت متأكد من حذف الملف "${file.title || file.file_name}"؟ لا يمكن التراجع عن هذا الإجراء.`,
    confirmText: 'حذف',
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/api/v1/files/${file.uuid}`)
    handleFileDeleted(file)
    toast.success('تم حذف الملف بنجاح')
  } catch (e) {
    console.error('Delete failed:', e)
    toast.error('حدث خطأ أثناء حذف الملف')
  }
}

function goToPage(page) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  if (initialLoadDone.value) {
    loadCategoryData(page)
  }
}

function onDateFilterChange() {
  if (dateFilter.value !== 'custom') {
    customDateFrom.value = ''
    customDateTo.value = ''
  }
  currentPage.value = 1
  if (initialLoadDone.value) {
    loadCategoryData(1)
  }
}

function onTimeFilterChange() {
  if (timeFilter.value !== 'custom') {
    customTimeFrom.value = ''
    customTimeTo.value = ''
  }
  currentPage.value = 1
  if (initialLoadDone.value) {
    loadCategoryData(1)
  }
}

function onSortChange() {
  currentPage.value = 1
  if (initialLoadDone.value) {
    loadCategoryData(1)
  }
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
  if (initialLoadDone.value) {
    loadCategoryData(1)
  }
}

// Debounced search — resets to page 1
let searchTimer
watch(searchQuery, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    currentPage.value = 1
    if (initialLoadDone.value) {
      loadCategoryData(1)
    }
  }, 350)
})

// Mark category loaded when expanded for the first time, and load data
//
// All categories default to expanded, so { immediate: true } used to fire
// loadCategoryData() for every CategoryBlock instance in the same tick —
// 6 categories x 2 requests each (files + local-files) hitting the embedded
// engine's single-threaded PHP executor simultaneously. On classic-mode
// builds (~500ms boot overhead per request, one request at a time — see
// AppServiceProvider::boot()) that flood alone can take 10-20+ seconds to
// drain, and anything else the user does meanwhile (like starting an
// upload) queues up behind all of it and can hit its own client-side
// timeout. Stagger each category's first load by its position in
// allCategories so they queue up gently instead of all landing at once.
watch(expanded, (val) => {
  if (val) {
    markCategoryLoaded(props.slug)
    initialLoadDone.value = true
    const index = props.allCategories.findIndex(c => c.slug === props.slug)
    const staggerMs = index > 0 ? index * 250 : 0
    if (staggerMs > 0) {
      setTimeout(() => loadCategoryData(1), staggerMs)
    } else {
      loadCategoryData(1)
    }
  }
}, { immediate: true })

// FIX-05: Handle new note added from AddRecordModal.
// Problem: @noteAdded="loadCategoryData(1)" fires immediately but production
// DB hasn't received the note yet (still pending_create in SQLite).
// Solution: prepend note to serverNotes immediately (instant UI feedback),
// then reload from server after 4s to get the production-confirmed version.
let noteReloadTimer = null
function onNoteAdded(note) {
  // Show note immediately in the category list
  if (note?.uuid && initialLoadDone.value) {
    const alreadyExists = serverNotes.value.some(n => n.uuid === note.uuid)
    if (!alreadyExists) {
      serverNotes.value = [{ ...note, category: props.slug }, ...serverNotes.value]
    }
  }
  // Reload from server after sync has had time to push to production
  clearTimeout(noteReloadTimer)
  noteReloadTimer = setTimeout(() => {
    if (initialLoadDone.value) loadCategoryData(1)
  }, 4000)
}

// After upload completes, show the file immediately in this category's list
// (categoryFiles' allFiles-merge can't do this itself: it deliberately skips
// sync_status==='synced' local files to avoid re-showing older files that
// simply live on another page — but addFileLocally() now marks a brand-new
// upload 'synced' too, since online uploads land on production directly. So
// a just-finished upload matched that same "already synced, must be an older
// page" exclusion and never appeared here until the background reload below
// happened to land it — hence "uploads don't show up in the same place".
// Splicing the exact file this job produced (via job.resultFileUuid, set in
// useUploads.js) sidesteps the ambiguity entirely.
const localCompleteCount = ref(0)
const mergedUploadJobIds = new Set()
watch(uploads, (list) => {
  let c = 0
  for (let i = 0; i < list.length; i++) {
    const j = list[i]
    if (j.metadata?.category !== props.slug || j.status !== 'completed') continue
    c++
    if (j.resultFileUuid && !mergedUploadJobIds.has(j.id)) {
      mergedUploadJobIds.add(j.id)
      if (initialLoadDone.value) {
        const file = (allFiles.value || []).find(f => f.uuid === j.resultFileUuid)
        if (file && !serverFiles.value.some(f => f.uuid === file.uuid)) {
          serverFiles.value = [file, ...serverFiles.value]
        }
      }
    }
  }
  if (c > localCompleteCount.value) {
    localCompleteCount.value = c
    setTimeout(() => {
      if (initialLoadDone.value) loadCategoryData(currentPage.value)
    }, 5000)
    toast.success('Upload complete')
  }
}, { flush: 'post' })

// UI state
const showUploadArea = ref(false)
const showAddRecordModal = ref(false)
const showViewNoteModal = ref(false)
const activeViewNote = ref(null)
const showCategoryMenu = ref(false)
const dragging = ref(false)
const fileInput = ref(null)
const menuRef = ref(null)
const menuStyle = ref({})
const activeSheetFile = ref(null)
const colorOptions = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#14b8a6', '#6b7280']

const showVisitModal = ref(false)
const showRenameModal = ref(false)
const showColorModal = ref(false)
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
  if (!(fileData instanceof File)) {
    // The NativePHP camera/gallery plugins (CameraCoordinator.kt) resolve
    // with { path, mimeType, ... } — an absolute on-device filesystem path,
    // NOT a `uri` field. Reading `fileData.uri` here always missed (it's
    // undefined), so the raw plugin object silently fell through as `file`
    // and got appended to FormData as-is — Laravel then saw no real upload
    // part for the `file` field and rejected it with a 422.
    const rawPath = fileData.uri || fileData.path
    if (rawPath) {
      try {
        const readUri = /^[a-z][a-z0-9+.-]*:\/\//i.test(rawPath) ? rawPath : `file://${rawPath}`
        const blob = await new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest()
          xhr.open('GET', readUri, true)
          xhr.responseType = 'blob'
          xhr.onload = () => {
            if (xhr.status === 200 || xhr.status === 0) resolve(xhr.response)
            else reject(new Error(`HTTP ${xhr.status}`))
          }
          xhr.onerror = () => reject(new Error('Network error'))
          xhr.send()
        })
        const mime = fileData.mimeType || fileData.type || blob.type
        const name = fileData.name || rawPath.split('/').pop() || 'file'
        file = new File([blob], name, { type: mime })
      } catch (e) {
        console.warn('[CategoryBlock] Failed to read native file:', e)
        return
      }
    } else {
      console.warn('[CategoryBlock] Native file result had no uri/path, skipping:', fileData)
      return
    }
  }

  const uploadJob = uploadFile(file, patientId, { category: props.slug })
  if (uploadJob) {
    const unwatch = watch(() => uploadJob.status, (status) => {
      if (status === 'completed') {
        unwatch()
        setTimeout(() => {
          if (initialLoadDone.value) loadCategoryData(currentPage.value)
        }, 5000)
      } else if (status === 'failed' || status === 'cancelled') {
        unwatch()
      }
    })
  }
}

async function captureCamera() {
  if (isCameraAvailable()) {
    const photo = await takePhoto()
    if (photo) {
      handleNativeFileResult(photo)
    }
    return
  }
  if (fileInput.value) {
    fileInput.value.accept = 'image/*'
    fileInput.value.setAttribute('capture', 'environment')
    fileInput.value.click()
  }
}

async function captureGallery() {
  if (isFilePickerAvailable()) {
    const files = await pickFiles({ multiple: true, accept: 'image/*' })
    if (files && files.length > 0) {
      for (const f of files) {
        handleNativeFileResult(f)
      }
    }
    return
  }
  if (fileInput.value) {
    fileInput.value.accept = 'image/*'
    fileInput.value.removeAttribute('capture')
    fileInput.value.click()
  }
}

async function triggerFileInput() {
  if (isFilePickerAvailable()) {
    const files = await pickFiles({ multiple: true, accept: defaultAccept })
    if (files && files.length > 0) {
      for (const f of files) {
        handleNativeFileResult(f)
      }
    }
    return
  }
  if (fileInput.value) {
    fileInput.value.accept = defaultAccept
    fileInput.value.removeAttribute('capture')
    fileInput.value.click()
  }
}

function handleFileSelect(e) {
  handleFiles(Array.from(e.target.files))
  e.target.value = null
}

function handleDrop(e) {
  dragging.value = false
  handleFiles(Array.from(e.dataTransfer.files))
}

function handleFiles(selectedFiles) {
  const patientId = selectedPatient.value?.uuid || selectedPatient.value?.id
  if (!patientId) return
  for (const file of selectedFiles) {
    const uploadJob = uploadFile(file, patientId, { category: props.slug })
    if (uploadJob) {
      const unwatch = watch(() => uploadJob.status, (status) => {
        if (status === 'completed') {
          unwatch()
          setTimeout(() => {
            if (initialLoadDone.value) loadCategoryData(currentPage.value)
          }, 5000)
        } else if (status === 'failed' || status === 'cancelled') {
          unwatch()
        }
      })
    }
  }
}

function pauseJob(job) { pauseUpload(job.id) }
function resumeJob(job) { resumeUpload(job.id) }
function retryJob(job) { retryUpload(job.id) }
function cancelJob(job) { cancelUpload(job.id) }

async function openAddVisit() {
  showVisitModal.value = true
}

async function submitVisit() {
  if (!visitType.value || !selectedPatient.value?.uuid) return
  try {      await axios.post(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/visits`), {
        visit_type: visitType.value,
        category: props.slug,
      }, getApiConfig())
    showCategoryMenu.value = false
    showVisitModal.value = false
    visitType.value = ''
    refreshWorkspaceData()
    toast.success('Visit added')
  } catch (e) { console.error('Add visit failed', e) }
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

const mergedCategoryItems = computed(() => {
  const fileItems = (files.value || []).map(f => ({
    ...f,
    type: 'file',
  }))
  const noteItems = (notes.value || []).map(n => ({
    ...n,
    type: 'note',
    title: n.content?.replace(/<[^>]*>/g, '').slice(0, 30) || 'ملاحظة نصية',
  }))
  return [...fileItems, ...noteItems].sort((a, b) => new Date(b.created_at || Date.now()) - new Date(a.created_at || Date.now()))
})

// files.value is already the current server page; re-slicing mergedCategoryItems
// by currentPage here double-paginated it the same way `files` did above.
const paginatedItems = computed(() => mergedCategoryItems.value)

async function deleteNoteDirectly(note) {
  const confirmed = await dialog.confirm({
    title: 'حذف الملاحظة',
    message: 'هل أنت متأكد من حذف هذه الملاحظة؟ لا يمكن التراجع عن هذا الإجراء.',
    confirmText: 'حذف',
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes/${note.uuid}`), getApiConfig())
    refreshWorkspaceData()
    toast.success('تم حذف الملاحظة بنجاح')
  } catch (e) {
    console.error('Delete note failed', e)
    toast.error('حدث خطأ أثناء حذف الملاحظة')
  }
}

function viewNoteContent(note) {
  activeViewNote.value = note
  showViewNoteModal.value = true
}

function syncStatusBadgeClass(status) {
  switch (status) {
    case 'pending_upload': return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300'
    case 'uploading':      return 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300'
    case 'failed':         return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'
    case 'synced':         return 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'
    default:               return 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'
  }
}

async function retryOfflineUpload(item) {
  if (!item?.uuid) return
  try {
    await axios.post(`/_native/api/offline/uploads/${item.uuid}/retry`)
    updateFileLocally({ uuid: item.uuid, sync_status: 'pending_upload' })
    toast.success('تمت إعادة جدولة الرفع')
  } catch (e) {
    toast.error('فشلت إعادة المحاولة')
  }
}

async function deleteOfflineUpload(item) {
  if (!item?.uuid) return
  const confirmed = await dialog.confirm({
    title: 'إلغاء الرفع',
    message: 'هل أنت متأكد من إلغاء رفع هذا الملف؟ سيتم حذف الملف المحلي.',
    confirmText: 'إلغاء',
    style: 'danger',
  })
  if (!confirmed) return
  try {
    await axios.delete(`/_native/api/offline/uploads/${item.uuid}`)
    removeFileLocally(item.uuid)
    toast.success('تم إلغاء الرفع')
  } catch (e) {
    toast.error('فشل الإلغاء')
  }
}

function printFile(file) {
  if (!file.url) return
  const win = window.open(file.url, '_blank')
  win.onload = () => {
    win.print()
  }
}

const addRecordModalTab = ref('text')

function openAddRecord(tab = 'text') {
  addRecordModalTab.value = tab
  showAddRecordModal.value = true
}
</script>

<style scoped>
.accordion-enter-active, .accordion-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; overflow: hidden; }
.accordion-enter-from, .accordion-leave-to { opacity: 0; transform: translateY(-8px); }
.accordion-enter-to, .accordion-leave-from { opacity: 1; transform: translateY(0); }
</style>
