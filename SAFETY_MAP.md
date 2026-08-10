# Phase 1 — Safety Map (Sync / Offline-CRUD Removal)

مرجع تمهيدي فقط — **بدون أي تعديل على الكود**. الهدف: تحديد كل حاجة مرتبطة بالـ Sync/Offline قبل ما نبدأ الحذف في Phase 2، ومعرفة أي KEEP-files فيها منطق Sync متضمّن جواها (مش ملفات منفصلة بالكامل).

مبني على `ARCHITECTURE_ANALYSIS.md` + grep شامل على الكود الحالي (لا استنتاج، كل سطر هنا موجود فعليًا في الكود).

---

## 1. ملفات الـ Sync (يمكن حذفها بالكامل)

هذه ملفات **مخصصة بالكامل** للـ Sync — مفيش فيها منطق تاني تحت غيرها:

| الملف | الدور |
|---|---|
| `app/Services/SyncEngineService.php` | المحرك النشط الحالي (`syncAll()` وكل الـ `syncPending*`) |
| `app/Services/ManualSyncService.php` | يشغّل الـ engine يدويًا |
| `app/Jobs/RunManualSyncJob.php` | Job يشغّل `ManualSyncService`/`SyncEngineService` في الخلفية |
| `app/Domains/Sync/Models/SyncQueue.php` | موديل جدول `sync_queue` |
| `app/Domains/Sync/Services/SyncQueueService.php` | إدارة حالة الـ queue (`push/markProcessing/markSynced/markFailed`) |
| `app/Services/Sync/PatientSyncService.php` | Worker مريض (legacy، لسه بيتستخدم) |
| `app/Services/Sync/FileSyncService.php` | Worker ملفات (فيه sha256 dedup + resumable upload) |
| `app/Services/Sync/NoteSyncService.php` | Worker ملاحظات |
| `app/Services/Sync/VisitSyncService.php` | Worker زيارات |
| `app/Services/Sync/CategorySyncService.php` | Worker تصنيفات |
| `app/Services/Sync/DownloadSyncService.php` | سحب بيانات من السيرفر للجهاز |
| `app/Services/Sync/ConflictResolverService.php` | حل التعارضات (بدون consumer معروف — راجع `ARCHITECTURE_ANALYSIS.md §8`) |
| `app/Services/Sync/CacheCleanupService.php` | تنظيف الكاش الثنائي القديم |
| `app/Services/OfflineUploadService.php` | رفع الملفات أوفلاين (resumable) |
| `app/Repositories/OfflineFileRepository.php` + `app/Contracts/Repositories/OfflineFileRepositoryInterface.php` | مستودع `offline_files` |
| `app/Repositories/FileCacheRepository.php` + interface | مستودع `file_cache` |

**Vue:**

| الملف | الدور |
|---|---|
| `resources/js/Composables/useSyncEngine.js` | حالة `isOnline` + `triggerSync()` + polling |
| `resources/js/Composables/useOfflineUploads.js` | طابور رفع أوفلاين (chunked + resume) |
| `resources/js/Components/workspace/SyncCenterModal.vue` | UI لعرض حالة الـ sync |
| `resources/js/Pages/Settings/Partials/SyncDataCenter.vue` | UI إعدادات الـ sync |

---

## 2. كل الاستدعاءات (Callers) — مهم جدًا قبل الحذف

### `SyncEngineService` بيتستدعى من:
`app/Providers/AppServiceProvider.php`, `app/Repositories/Eloquent/EloquentPatientRepository.php`, `app/Repositories/PatientRepository.php`, `app/Http/Controllers/Api/NoteController.php`, `app/Http/Controllers/Api/ChunkUploadController.php`, `app/Http/Controllers/Api/Mobile/VisitController.php`, `app/Http/Controllers/Api/Mobile/PatientController.php`, `app/Jobs/RunManualSyncJob.php`, `app/Services/Sync/DownloadSyncService.php`, `routes/web.php`

### `SyncQueueService`/`SyncQueue` بيتستدعى من:
`app/Repositories/PatientRepository.php`, `app/Services/SyncEngineService.php`, `app/Services/ManualSyncService.php`, `app/Services/Sync/{VisitSyncService,NoteSyncService,CategorySyncService,FileSyncService,PatientSyncService}.php`, `routes/web.php`

### `app/Services/Sync/*` (الـ workers) بيتستدعوا من:
`app/Http/Controllers/WorkspaceController.php`, `app/Http/Controllers/Api/Mobile/BootstrapController.php`, `app/Services/ManualSyncService.php`, `app/Services/SyncEngineService.php`

### `OfflineUploadService`/`OfflineFileRepository` بيتستدعوا من:
`app/Http/Controllers/WorkspaceController.php`, `app/Http/Controllers/Api/Mobile/FileController.php`, `app/Http/Controllers/Api/FileAccessController.php`, `app/Services/SyncEngineService.php`

### `useSyncEngine` (Vue) بيتستدعى من:
`useOfflineUploads.js`, `useWorkspace.js`, `SyncCenterModal.vue`, `AddRecordModal.vue`, `PatientListSidebar.vue`, `CategoryBlock.vue`, `DoctorWorkspace.vue`, `AppLayout.vue`, `SyncDataCenter.vue`

### `useOfflineUploads` (Vue) بيتستدعى من:
`useUploads.js`, `UploadManager.vue`, `CategoryBlock.vue`, `AddRecordModal.vue`

**النتيجة**: `SyncEngineService` و`sync_status`-branching مش معزولين في طبقة واحدة — هما متغلغلين جوه نفس الـ Controllers اللي إحنا عايزين نـ **KEEP**-ها (`PatientController`, `FileController` — عبر `FileAccessController`/`WorkspaceController`, `NoteController`, `VisitController`). يعني Phase 2 مش "حذف ملفات" بس، هو **تعديل داخل ملفات محتفظ بيها** كمان.

---

## 3. جداول قاعدة البيانات المرتبطة بالـ Sync/Offline

| الجدول | الحالة | ملاحظة |
|---|---|---|
| `sync_queue` | نشط | يُدار بواسطة `SyncQueueService` |
| `offline_files` | نشط | ملفات أُنشئت أوفلاين، بانتظار الرفع |
| `file_cache` | نشط | كاش ملفات منزّلة من السيرفر |
| `cached_categories` | نشط | كاش تصنيفات (reference data) |
| `pending_operations` | **ميت** | صفر مراجع في `app/` |
| `sync_meta` | **ميت** | صفر مراجع في `app/` |
| `sync_states` | **ميت** | صفر مراجع في `app/` |
| `sync_jobs` | **ميت** | صفر مراجع في `app/` |
| `patients.sync_status`, `patient_notes.sync_status`, `patient_files.sync_status`, `patient_visits.sync_status/version/server_updated_at` | نشط | أعمدة إضافية على جداول **محتفظ بيها** |
| `patient_files.remote_uuid`, `patient_files.sha256` | نشط | يُستخدم في dedup + مطابقة uuid محلي/سيرفر |

Migrations المرتبطة (13 ملف): `2026_06_29_144926_create_offline_sync_tables.php`, `2026_07_03_222612_create_pending_operations_table.php`, `2026_07_23_000001_create_sync_meta_table.php`, `2026_07_23_000002_add_sync_status_to_patients_table.php`, `2026_07_23_000003_create_file_cache_table.php`, `2026_07_23_000004_create_offline_files_table.php`, `2026_07_24_000001_create_cached_categories_table.php`, `2026_07_25_000001_add_sync_status_to_patient_notes_table.php`, `2026_07_28_000001_add_sync_status_to_patient_files_table.php`, `2026_08_01_000001_add_metadata_to_offline_files_table.php`, `2026_08_02_000001_enhance_sync_queue_and_versioning.php`, `2026_08_02_000002_add_sync_columns_to_patient_visits_table.php`.

---

## 4. Offline Repositories

| الـ Repository | الجدول | القرار المقترح |
|---|---|---|
| `app/Repositories/OfflineFileRepository.php` | `offline_files` | حذف — هيتلغي مع إلغاء الـ offline CRUD |
| `app/Repositories/FileCacheRepository.php` | `file_cache` | **يتبقى ويتحول** — ده أساس الـ `OfflinePatientCache` الجديد (Phase 3) |
| `app/Repositories/PatientRepository.php` | `patients` | **يتعدّل** — فيه `isOfflineDevice()` وqueueing؛ لازم يتنضف من منطق الـ sync ويفضل يكلم الـ API بس |
| `app/Models/CachedCategory.php` + جدول `cached_categories` | تصنيفات | يتبقى (read cache بسيط، مش جزء من مشكلة الـ CRUD sync) |

---

## 5. كل الـ Code Paths اللي بتعتمد على `sync_status` (28 ملف PHP)

```
app/Domains/Media/Models/PatientFile.php
app/Domains/Media/Services/UploadService.php
app/Domains/Patients/Models/Patient.php
app/Domains/Patients/Models/PatientVisit.php
app/Http/Controllers/Api/ChunkUploadController.php
app/Http/Controllers/Api/CreatePatientDiagnosticController.php
app/Http/Controllers/Api/FileAccessController.php
app/Http/Controllers/Api/Mobile/FileController.php
app/Http/Controllers/Api/Mobile/NoteController.php
app/Http/Controllers/Api/Mobile/PatientController.php
app/Http/Controllers/Api/Mobile/VisitController.php
app/Http/Controllers/Api/NoteController.php
app/Http/Controllers/WorkspaceController.php
app/Jobs/RunManualSyncJob.php
app/Providers/AppServiceProvider.php
app/Repositories/Eloquent/EloquentPatientFileRepository.php
app/Repositories/Eloquent/EloquentPatientRepository.php
app/Repositories/FileCacheRepository.php
app/Repositories/OfflineFileRepository.php
app/Repositories/PatientRepository.php
app/Services/Sync/* (كل الملفات)
app/Services/SyncEngineService.php
app/Services/Upload/ChunkMergeService.php
```

و6 ملفات Vue: `useOfflineUploads.js`, `useWorkspace.js`, `useUploads.js`, `PatientListSidebar.vue`, `CategoryBlock.vue`, `DoctorWorkspace.vue`.

## 6. كل الـ Code Paths اللي بتعتمد على SQLite-branching (`config('database.default') === 'sqlite'` / `isOfflineDevice()`) — 21 ملف

```
app/Domains/Media/Jobs/GenerateThumbnailJob.php
app/Domains/Media/Jobs/OptimizeVideoForStreaming.php
app/Domains/Media/Models/PatientFile.php
app/Domains/Media/Services/UploadService.php
app/Http/Controllers/Api/CategoryController.php
app/Http/Controllers/Api/CategoryFileController.php
app/Http/Controllers/Api/ChunkUploadController.php
app/Http/Controllers/Api/FileAccessController.php
app/Http/Controllers/Api/Mobile/BootstrapController.php
app/Http/Controllers/Api/Mobile/FileController.php
app/Http/Controllers/Api/Mobile/NoteController.php
app/Http/Controllers/Api/Mobile/PatientController.php
app/Http/Controllers/Api/Mobile/VisitController.php
app/Http/Controllers/Api/NoteController.php
app/Http/Controllers/AuthController.php
app/Http/Controllers/WorkspaceController.php
app/Http/Middleware/ParseMobileMultipartMiddleware.php
app/Providers/AppServiceProvider.php
app/Repositories/Eloquent/EloquentPatientRepository.php
app/Repositories/FileCacheRepository.php
app/Repositories/PatientRepository.php
app/Services/Upload/ChunkMergeService.php
```

**ملاحظة مهمة**: هذا الـ branching مش دايمًا "sync" — بعضه بيتحكم في اختيار الـ storage path (local disk vs cache) أو في auth-bypass للجهاز. لازم مراجعة كل حالة لوحدها في Phase 2 مش حذف السطر كله بشكل آلي.

### Routes مرتبطة (`routes/web.php`)
- `Route::prefix('_native/api/sync')->...` (سطر ~554) — dashboard/pause/resume/cancel/state/manual/engine/pending-summary
- `Route::prefix('_native/cache')->...` (سطر ~751) — stream/cache/status/remove للملفات المحلية (**ده مش sync، ده جزء من الـ Offline Cache الجديد — يتبقى**)

---

## 7. التصنيف المقترح (KEEP / REMOVE / REBUILD)

### ✅ KEEP (بدون تغيير جوهري)
- Laravel API الأساسي (routes, Controllers الخاصة بالـ CRUD)
- MySQL / Eloquent models
- Vue UI عمومًا
- Authentication (Sanctum) — مع إصلاح باگ الـ logout (`ARCHITECTURE_ANALYSIS.md §13.3`)
- `_native/cache/*` routes + `FileCacheRepository` + `file_cache` table — دول الأساس بتاع Offline Cache الجديد
- NativePHP shell

### ❌ REMOVE (حذف كامل)
- `SyncEngineService.php`, `ManualSyncService.php`, `RunManualSyncJob.php`
- `app/Domains/Sync/*` بالكامل (`SyncQueue` model + `SyncQueueService`)
- `app/Services/Sync/*` بالكامل (7 ملفات)
- `OfflineUploadService.php`, `OfflineFileRepository.php` + الـ interface
- جدول `sync_queue`, `offline_files`, والجداول الميتة الأربعة (`pending_operations`, `sync_meta`, `sync_states`, `sync_jobs`)
- أعمدة `sync_status`/`version`/`server_updated_at`/`client_updated_at`/`remote_uuid` من `patients`/`patient_notes`/`patient_files`/`patient_visits` (بعد التأكد إن مفيش حاجة تانية بتستخدمها)
- `useSyncEngine.js`, `useOfflineUploads.js`, `SyncCenterModal.vue`, `SyncDataCenter.vue`
- منطق `resolvePatient()` اللي بيعمل stub patient في `ChunkUploadController.php` (مصدر باگ "Patient XXXXX") — هيتلغي تلقائيًا لما الرفع يبقى API-only ومحتاج إنترنت
- `_native/api/sync/*` routes بالكامل

### 🔨 REBUILD (حاجة واحدة بس)
- `OfflinePatientCache` — abstraction واحدة (Phase 3) فوق `file_cache` table الموجود أصلاً، بدل ما يتوزع المنطق على 5+ سيرفيسات

---

## 8. تحذير قبل Phase 2

`sync_status`/`isOfflineDevice()` مش معزولين — موجودين جوه:
- `PatientController@store/update` (KEEP file) — الشرط اللي بيحدد `pending_create` vs `synced`
- `FileController@store` (KEEP file) — نفس الشيء
- `NoteController`, `VisitController` (KEEP files) — نفس النمط
- `PatientRepository.php` — فيه `isOfflineDevice()` كخط تحكم رئيسي في كل عمليات الكتابة

يعني Phase 2 = **تعديل داخل هذه الملفات** (شيل الـ branching، خلي كل حاجة تعدي على API عادي، وأي فشل شبكة يرجع "Network unavailable") — مش مجرد حذف ملفات منفصلة. ده متوقع وطبيعي حسب خطتك، بس المدى (scope) أوسع شوية من قائمة REMOVE الأولية لأن فيه ~20-28 ملف فيهم أسطر لازم تتشال مش ملفات كاملة.

---

**الحالة**: Phase 1 خلصت. لسه معملتش أي تعديل على الكود. جاهز أبدأ Phase 2 لما تأكد.
