# 🚨 مشاكل النظام الكاملة - تحليل شامل
## Full System Problem Analysis - Medical Plus v3

**تاريخ التحليل:** 21 يوليو 2026
**الهدف:** تحليل شامل لجميع المشاكل في النظام وتوثيقها لإصلاح 100% من مشاكل الـ Online/Offline

---

## 📋 فهرس المشاكل

1. [العمارة المزدوجة - Dual Architecture Conflict](#1-العمارة-المزدوجة)
2. [تكرار منطق المزامنة - Sync Logic Duplication](#2-تكرار-منطق-المزامنة)
3. [فشل جلب الملفات - File Fetch Failures](#3-فشل-جلب-الملفات)
4. [ID Resolution Bug](#4-id-resolution-bug)
5. [تجاوز HybridRepository تماماً](#5-تجاوز-hybridrepository)
6. [عدم تحميل الملفات الـ Binary للـ Offline](#6-عدم-تحميل-الملفات-binary)
7. [مشكلة URLs الملفات](#7-مشكلة-urls-الملفات)
8. [تضارب المزامنة - Sync Race Conditions](#8-تضارب-المزامنة)
9. [قلة التحقق من Queue Offline](#9-قلة-التحقق-من-queue-offline)
10. [Token Management Issues](#10-token-management-issues)
11. [Observer vs Upload Sync Conflict](#11-observer-vs-upload-sync-conflict)
12. [DoctorIsolationScope والمزامنة](#12-doctorisolutionscope-والمزامنة)
13. [Category File Loading Issues](#13-category-file-loading-issues)
14. [Field Mapping Inconsistency](#14-field-mapping-inconsistency)
15. [Permissions/Authorization Gaps](#15-permissionsauthorization-gaps)

---

## 1. العمارة المزدوجة - Dual Architecture Conflict

### المشكلة
النظام يستخدم **مسارين مختلفين تماماً** لجلب وعرض بيانات المرضى والملفات:

#### المسار A - Website/Inertia (قديم):
```
PatientController::show()
  → PatientFileRepositoryInterface (واجهة)
    → HybridPatientFileRepository (يحاول API أولاً، ثم يرجع للـ Local)
```

#### المسار B - Mobile/Workspace (جديد):
```
WorkspaceController::patientData()
  → EloquentPatientRepository::findByUuid() (مباشر)
  → EloquentPatientFileRepository::forPatient() (مباشر، بدون Hybrid)
```

### الملفات المتأثرة:
- `app/Http/Controllers/PatientController.php` - المسار القديم
- `app/Http/Controllers/WorkspaceController.php` - المسار الجديد
- `app/Repositories/Hybrid/HybridPatientFileRepository.php` - غير مستخدم في المسار الجديد

### التأثير:
- **الكود المكرر**: نفس منطق `syncLocalCache` موجود في 3 أماكن مختلفة (HybridRepo, WorkspaceController, FullSyncService)
- **تضارب البيانات**: المسار B يكتب في SQLite مباشرة، والمسار A يقرأ من Hybrid. يمكن أن يكون لديهم بيانات مختلفة.
- **صعوبة الصيانة**: أي إصلاح في أحد المسارات لا يؤثر على الآخر

### الحل المطلوب:
- توحيد المسارين في مسار واحد
- استخدام `HybridPatientFileRepository` في كل مكان
- إزالة منطق الـ Bootstrap المكرر من `WorkspaceController`

---

## 2. تكرار منطق المزامنة - Sync Logic Duplication

### المشكلة
منطق مزامنة الملفات (API → Local SQLite) مكرر في **4 أماكن مختلفة**:

| المكان | الملف | الوظيفة |
|--------|-------|---------|
| 1 | `FullSyncService::syncFilesWithLocalPatientId()` | المزامنة الخلفية الكاملة |
| 2 | `WorkspaceController::patientData()` (سطور ~230-280) | Bootstrap عند فتح صفحة المريض |
| 3 | `HybridPatientFileRepository::syncLocalCache()` | مزامنة الـ Hybrid Repository |
| 4 | `WorkspaceController::syncPatientsLocally()` | مزامنة قائمة المرضى |

### الملفات المتأثرة:
- `app/Services/FullSyncService.php`
- `app/Http/Controllers/WorkspaceController.php`
- `app/Repositories/Hybrid/HybridPatientFileRepository.php`

### التأثير:
- **تضارب في التعامل مع الحقول**: كل مكان يعالج mapping الحقول بشكل مختلف قليلاً
- **أخطاء غير متوقعة**: إصلاح مشكلة في مكان واحد لا يطبق على الأماكن الأخرى
- **أداء سيء**: Bootstrap في `patientData()` يجلب من API كل مرة يتم فتح صفحة المريض (إذا كانت local فارغة)

### الحل المطلوب:
- توحيد كل منطق المزامنة في `FullSyncService`
- إزالة duplicate logic من الـ Controllers
- استخدام `FullSyncService::syncMetadataOnly()` كمصدر وحيد للمزامنة

---

## 3. فشل جلب الملفات - File Fetch Failures

### المشكلة
عند فتح صفحة تفاصيل المريض، الملفات لا تظهر للأسباب التالية:

#### أ. Bootstrap فاشل أو غير مكتمل:
في `WorkspaceController::patientData()`, الـ Bootstrap من API يحدث فقط عندما:
```php
if (empty($allFiles) && NetworkStatusService::isOnline()) {
```
ولكن:
- `EloquentPatientFileRepository::forPatient()` يستخدم `Patient::where('uuid', ...)->firstOrFail()`
- إذا لم يتم مزامنة المريض محلياً (UUID غير موجود في SQLite)، سيرمي `ModelNotFoundException`
- حتى لو نجح Bootstrap، الـ `allFiles` تأتي من `EloquentPatientFileRepository` الذي يعتمد على `patient_id` صحيح

#### ب. الـ Repository Service Provider لا يستخدم Hybrid:
```php
// RepositoryServiceProvider.php
if (NativePhp::isRunningInNativeApp()) {
    $this->app->bind(PatientFileRepositoryInterface::class, EloquentPatientFileRepository::class);
} else {
    $this->app->bind(PatientFileRepositoryInterface::class, HybridPatientFileRepository::class);
}
```
في وضع Native (التطبيق المثبت)، يستخدم `EloquentPatientFileRepository` مباشرة بدون أي try-API-first logic!

### الملفات المتأثرة:
- `app/Providers/RepositoryServiceProvider.php`
- `app/Repositories/Eloquent/EloquentPatientFileRepository.php`
- `app/Http/Controllers/WorkspaceController.php`
- `app/Http/Controllers/Api/Mobile/FileController.php`

### التأثير:
- المستخدم لا يرى ملفات المريض أبداً إذا كانت SQLite المحلية فارغة
- حتى بعد الضغط على "Sync" الملفات لا تظهر
- كود Bootstrap غير موثوق (يعتمد على عدة شروط يجب أن تتحقق كلها)

### الحل المطلوب:
- استخدام `HybridPatientFileRepository` دائماً (حتى في Native mode)
- تحسين `EloquentPatientFileRepository::forPatient()` ليعيد مصفوفة فارغة بدلاً من رمي Exception إذا لم يتم العثور على المريض
- إصلاح `RepositoryServiceProvider` لاستخدام Hybrid دائماً

---

## 4. ID Resolution Bug

### المشكلة
الـ Remote API و Local SQLite يستخدمان IDs مختلفة:
- **Remote (MySQL)**: auto-increment IDs مختلفة لكل دكتور
- **Local (SQLite)**: auto-increment IDs محلية تبدأ من جديد

### أين يحدث؟
الـ `PatientFile` model لديه `patient_id` foreign key. عند مزامنة الملفات من API، الـ API يُرجع `patient_id` الخاص بالـ Remote DB. إذا تم حفظ هذا الـ ID مباشرة، الملفات تصبح orphaned لأن الـ `patient_id` المحلي مختلف.

### أين تم إصلاحها جزئياً:
- `FullSyncService::syncFilesWithLocalPatientId()` → يحل المشكلة عن طريق resolve UUID → local ID ✅
- `WorkspaceController::patientData()` → يحل المشكلة Bootstrap ✅
- `HybridPatientFileRepository::syncLocalCache()` → يحل المشكلة ✅

### أين لم يتم إصلاحها:
- `FullSyncService::syncLocalCache()` → **لا** يحل المشكلة ويحذر في الـ log ❌
- عندما يتم استدعاء `syncLocalCache()` مباشرة (بدون `syncFilesWithLocalPatientId`) ❌

### الملفات المتأثرة:
- `app/Services/FullSyncService.php`
- `app/Http/Controllers/WorkspaceController.php`
- `app/Repositories/Hybrid/HybridPatientFileRepository.php`

### التأثير:
- ملفات تظهر في DB لكن بدون `patient_id` صحيح
- `$patient->files()` يرجع مصفوفة فارغة لأن العلاقة تعتمد على `patient_id`
- المستخدم يرى "لا توجد ملفات" رغم وجودها في API

---

## 5. تجاوز HybridRepository تماماً

### المشكلة
الـ `WorkspaceController` لا يستخدم `PatientFileRepositoryInterface` (الذي تم bind لـ HybridRepository). بدلاً من ذلك يحقن `EloquentPatientFileRepository` و `ApiPatientFileRepository` مباشرة.

```php
public function __construct(
    ...
    private readonly EloquentPatientFileRepository $eloquentFileRepo,
    private readonly ApiPatientFileRepository $apiFileRepo,
    ...
)
```

### أين يحدث:
- `WorkspaceController::patientData()` → يستخدم `$this->eloquentFileRepo->forPatient()` مباشرة
- `WorkspaceController::patientList()` → يستخدم `$this->eloquentPatientRepo->paginated()` مباشرة
- `WorkspaceController::index()` → يستخدم `$this->eloquentPatientRepo->all()` مباشرة

### الملفات المتأثرة:
- `app/Http/Controllers/WorkspaceController.php`

### التأثير:
- خاصية `syncLocalCache` من HybridRepository لا تُستخدم أبداً
- أي تحسينات على HybridRepository لا تؤثر على الـ Workspace
- كود غير متسق مع بقية النظام

### الحل المطلوب:
- استخدام `PatientFileRepositoryInterface` في WorkspaceController
- إزالة الحقن المباشر لـ EloquentPatientFileRepository و ApiPatientFileRepository
- التأكد أن الـ HybridRepository يعمل بشكل صحيح في كل الحالات

---

## 6. عدم تحميل الملفات Binary للـ Offline

### المشكلة
`FullSyncService::syncMetadataOnly()` صراحةً يقول:
```php
/**
 * Lightweight metadata-only sync. Syncs patients, notes, visits, and file
 * METADATA (no binary downloads).
 *
 * File binaries are downloaded on-demand when the user opens a file.
 */
```

ولكن لا يوجد آلية لتحميل الـ Binary files من الـ Remote API !!! ❌

### أين يوجد الملفات Binary حالياً:
- على الـ Remote server (`https://prof-hosam-fekry.online`) في مجلد `storage/app/patients/{uuid}/`
- على الـ Local device في مجلد `storage/app/patients/{uuid}/` (فقط الملفات المرفوعة محلياً)

### ملفات جديدة مرفوعة من أي جهاز:
- إذا رفع دكتور A ملف من موقعه، ويريد دكتور B رؤيته Offline
- الـ Metadata سيتم مزامنته (الاسم، التاريخ، الحجم، ...)
- لكن الـ Binary file نفسه **لن يتم تحميله** أبداً
- الـ Local URL سيشير إلى ملف غير موجود → 404

### الملفات المتأثرة:
- `app/Services/FullSyncService.php`
- `app/Http/Controllers/Api/FileAccessController.php`
- `app/Domains/Mobile/Resources/MobilePatientFileResource.php`

### التأثير:
- **لا يوجد Offline 100% للملفات**: يمكن رؤية الميتاداتا فقط Offline
- **الملفات تظهر كأنها موجودة** لكن لا يمكن فتحها

### الحل المطلوب:
- إضافة `syncFileBinaries()` إلى `FullSyncService` لتحميل الملفات عند الطلب أو بشكل مجدول
- تحميل الملفات عند فتح الصفحة (background download مع progress)
- تخزين المسار المحلي الفعلي في `PatientFile.file_path`

---

## 7. مشكلة URLs الملفات

### المشكلة
الـ `MobilePatientFileResource` يبني URLs تشير إلى الـ **Local Server**:
```php
'url' => $this->url,  // app()->url + /api/v1/files/{uuid}
'thumbnail_url' => $this->thumbnail_url,  // نفس الشيء
```

هذه الـ URLs تعمل فقط إذا كان الـ Local Server يعمل (NativePHP).

### أماكن مختلفة لبناء الـ URL:
1. `PatientFile::getUrlAttribute()` → يستخدم `config('app.url')` المحلي
2. `HybridPatientFileRepository::rewriteUrls()` → يبني URL محلي
3. `ApiPatientFileRepository::forPatient()` → يرجع URLs الـ Remote API

### أين هو الخطأ:
عندما يتم جلب الملفات من `ApiPatientFileRepository::forPatient()` (الـ Remote API)، الـ URLs التي تأتي من الـ Remote تحتوي على مسارات الـ Remote Server. ولكن عندما يتم حفظها في SQLite المحلي، الـ URL يتم استبداله بالـ Local URL.

### الملفات المتأثرة:
- `app/Domains/Media/Models/PatientFile.php`
- `app/Repositories/Hybrid/HybridPatientFileRepository.php`
- `app/Http/Controllers/Api/FileAccessController.php`

### التأثير:
- الملفات التي لم يتم تحميل Binary بتاعها محلياً لها URLs تشير إلى Local Server
- Local Server يحاول إيجاد ملف غير موجود → 404
- المستخدم يظن أن الملف موجود لكن لا يمكنه فتحه

### الحل المطلوب:
- إضافة حقل `remote_url` لحفظ URL الـ Remote للـ Binary
- إضافة حقل `is_cached_locally` لتحديد إذا كان الملف متاحاً Offline
- تحميل الـ Binary من الـ Remote عند الحاجة

---

## 8. تضارب المزامنة - Sync Race Conditions

### المشكلة
هناك **مسارين متوازيين** للمزامنة يمكن أن يتعارضا:

#### المسار 1: `syncAndRefresh()` من الـ Frontend
```
DoctorWorkspace onMounted()
  → syncAndRefresh()
    → axios.post('/api/native/sync')
      → NativeSyncController::sync()
        → FullSyncService::syncMetadataOnly()
          → push pending operations (local → remote)
          → sync patients (remote → local)
          → sync files (remote → local)
  → refreshPatientList()
    → axios.get('/api/v1/workspace/patients-list')
      → WorkspaceController::patientList()
```

#### المسار 2: Bootstrap في `patientData()`
```
selectPatient(uuid)
  → axios.get('/api/v1/workspace/{uuid}')
    → WorkspaceController::patientData()
      → إذا local فارغ + Online:
        → apiFileRepo->forPatient() (remote API)
        → sync files to local SQLite
```

### المشكلة الحقيقية:
عندما يكون Bootstrap في `patientData()` قيد التشغيل، وفي نفس الوقت `syncMetadataOnly()` يشغل أيضاً، فإنهما:
1. **يجرّبان جلب نفس البيانات من API مرتين**
2. **يكتبان في SQLite في نفس الوقت** → race condition
3. **يستنزفان Bandwidth المستخدم**

### الملفات المتأثرة:
- `app/Http/Controllers/WorkspaceController.php` (Bootstrap logic)
- `app/Services/FullSyncService.php`
- `resources/js/Composables/useWorkspace.js` (syncAndRefresh)

### التأثير:
- بيانات غير متسقة في SQLite
- إبطاء التطبيق
- استهلاك غير ضروري للبيانات

### الحل المطلوب:
- إزالة Bootstrap من `patientData()`
- الاعتماد كلياً على `syncMetadataOnly()` لمزامنة metadata
- إضافة queue للمزامنات لتجنب الـ race conditions

---

## 9. قلة التحقق من Queue Offline

### المشكلة
عندما يكون المستخدم Offline ويقوم بعمليات (إنشاء مريض، رفع ملف، ...)، هذه العمليات تُضاف إلى `sync_queue` ولكن:

1. **لا يوجد تأكيد مرئي**: المستخدم لا يعرف أن العملية في queue
2. **لا يوجد Retry محدود**: `markItemResult()` يعيد محاولة الفاشلة لكن بدون limit واضح
3. **لا يوجد Clear للفاشل**: العمليات الفاشلة تبقى في queue للأبد
4. **لا يوجد Serialization صحيح**: الـ `payload` يخزن كـ JSON، ولكن بعض الحقول مثل `local_path` قد تتغير بعد المزامنة

### الملفات المتأثرة:
- `app/Services/SyncQueueService.php`
- `app/Models/SyncQueueItem.php`

### التأثير:
- عمليات في الـ queue لا تتم مزامنتها أبداً
- المستخدم لا يعلم أن البيانات لم تُحفظ على الـ Server
- فقدان بيانات

### الحل المطلوب:
- إضافة max retry limit (مثلاً 5 محاولات)
- إظهار حالة الـ queue للمستخدم
- إضافة زر "Force Sync" للمزامنة اليدوية
- تنظيف queue من الفاشل تلقائياً

---

## 10. Token Management Issues

### المشكلة
Token الـ API يُخزن في الـ Session و Local DB، ولكن:

1. **Token Expiry**: إذا انتهت صلاحية الـ Token، `MakesApiRequests::apiCall()` يرمي `AuthenticationException`، ولكن لا يوجد Refresh Token
2. **Token Storage**: 
   - Session: `api_token_raw`
   - DB: جدول `sync_states` مع key `api_token`
   - ApiService: يحاول Session أولاً، ثم DB
3. **No Refresh**: إذا فشل الـ Token، الـ Session يُمسح ولكن لا يوجد إعادة تسجيل دخول

### الملفات المتأثرة:
- `app/Services/Mobile/ApiService.php`
- `app/Repositories/Api/Traits/MakesApiRequests.php`

### التأثير:
- بعد انتهاء صلاحية الـ Token، جميع عمليات API تفشل
- المستخدم يضطر لتسجيل الخروج والدخول مرة أخرى
- لا يوجد مزامنة حتى يعيد المستخدم تسجيل الدخول

### الحل المطلوب:
- إضافة `refreshToken()` method
- إعادة محاولة API call مع Token جديد
- عرض رسالة للمستخدم "انتهت الجلسة، الرجاء إعادة تسجيل الدخول"

---

## 11. Observer vs Upload Sync Conflict

### المشكلة
`PatientFileObserver` يُسجل عملية مزامنة عند إنشاء ملف جديد:
```php
public function created(PatientFile $file)
{
    $this->syncQueue->enqueueOperation('PatientFile', 'create', ...);
}
```

ولكن، بعض طرق رفع الملفات (مثل `ChunkUploadController`) تُسجل العملية يدوياً أيضاً. هذا يؤدي إلى:
1. **Duplicate sync entries**: العملية تُسجل مرتين
2. **مشكلة في الـ file path**: الـ Observer يُسجل المسار المحلي، لكن في وقت المزامنة الفعلية قد يكون المسار قد تغير

### الملفات المتأثرة:
- `app/Observers/PatientFileObserver.php`
- `app/Http/Controllers/Api/ChunkUploadController.php`
- `app/Http/Controllers/Api/UploadsController.php`

### التأثير:
- محاولة رفع الملف نفسه مرتين للـ Remote API
- فشل المزامنة مع رسالة خطأ غير واضحة

---

## 12. DoctorIsolationScope والمزامنة

### المشكلة
`DoctorIsolationScope` يُطبق تلقائياً على `PatientFile` و `Patient` models:
```php
static::addGlobalScope(new DoctorIsolationScope);
```

هذا الـ Scope يُفلتر البيانات لتظهر فقط للدكتور الحالي. أثناء المزامنة التي تحدث في الخلفية (دون مستخدم معين)، هذا الـ Scope يمنع رؤية البيانات.

### أين تم إصلاحها:
- `FullSyncService` يستخدم `withoutGlobalScopes()` ✅
- `WorkspaceController` يستخدم `withoutGlobalScopes()` في `syncPatientsLocally()` ✅
- `HybridPatientFileRepository` يستخدم `withoutGlobalScopes()` ✅

### أين لم يتم إصلاحها:
- `EloquentPatientFileRepository::forPatient()` يستخدم `$patient->files()` (يخضع للـ Scope)
- إذا كان المستخدم الحالي ليس Primary Doctor للمريض، الملفات لا تظهر

### الملفات المتأثرة:
- `app/Repositories/Eloquent/EloquentPatientFileRepository.php`

### التأثير:
- إذا تم مزامنة ملفات لطبيب آخر، ثم تم التحقق من أن المستخدم الحالي لديه صلاحية (share)، `EloquentPatientFileRepository` ما زال لا يرجع الملفات بسبب الـ Scope

---

## 13. Category File Loading Issues

### المشكلة
`CategoryBlock.vue` يقوم بجلب الملفات لكل category على حدة باستخدام endpoint منفصل:
```
GET /api/v1/patients/{uuid}/categories/{slug}/files
```

### أين يذهب هذا الـ Request:
`CategoryFileController::files()` → يستخدم `PatientFile::withoutGlobalScope(DoctorIsolationScope::class)`... ولكن:
1. لا يتحقق من صلاحية المستخدم بشكل صحيح
2. لا يستخدم الـ Hybrid Repository
3. لا يوفر الـ offline fallback

### الملفات المتأثرة:
- `app/Http/Controllers/Api/CategoryFileController.php`
- `resources/js/Components/workspace/CategoryBlock.vue`

### التأثير:
- فشل تحميل الملفات حسب التصنيف Offline
- رسائل الخطأ غير واضحة للمستخدم
- تكرار جلب الملفات من API (الملفات موجودة فعلاً في `workspaceData`)

### الحل المطلوب:
- جلب الملفات من `workspaceData` أولاً
- استخدام الـ Hybrid Repository في `CategoryFileController`
- إضافة offline fallback للبيانات المخزنة محلياً

---

## 14. Field Mapping Inconsistency

### المشكلة
كل مكان يعالج mapping الحقول بشكل مختلف:

#### في `WorkspaceController::patientData()`:
```php
$cleanData = Arr::except($fileData, ['id', 'patient', 'creator', 'uploader', 'description', 'url', 'thumbnail_url']);
// Maps description → desc
if (isset($cleanData['desc']) || isset($fileData['description'])) {
    $cleanData['desc'] = $cleanData['desc'] ?? $fileData['description'];
}
unset($cleanData['description'], $cleanData['url'], $cleanData['thumbnail_url']);
```

#### في `FullSyncService::syncFilesWithLocalPatientId()`:
```php
if ($key === 'description' && !isset($record['desc'])) {
    $cleanRecord['desc'] = $value;
    continue;
}
if (in_array($key, ['id', 'patient', 'creator', 'uploader', 'url', 'thumbnail_url', 'description'], true)) {
    continue;
}
```

#### في `HybridPatientFileRepository::syncLocalCache()`:
```php
if (isset($cleanData['description']) && !isset($cleanData['desc'])) {
    $cleanData['desc'] = $cleanData['description'];
}
unset($cleanData['description'], $cleanData['url'], $cleanData['thumbnail_url']);
```

كل واحد له ترتيب مختلف، ومشروطة مختلفة، يمكن أن تؤدي إلى:
- `desc` مفقود في SQLite
- `description` يحفظ كحقل إضافي
- فقدان بيانات

---

## 15. Permissions/Authorization Gaps

### المشكلة
هناك عدة ثغرات في التحقق من الصلاحيات:

1. **EloquentPatientFileRepository لا يتحقق من صلاحية المستخدم**:
   - `forPatient()` يستخدم `Patient::where('uuid', ...)->firstOrFail()` و `$patient->files()`
   - لا يتحقق إذا كان المستخدم الحالي لديه صلاحية الوصول للمريض

2. **CategoryFileController::files()**:
   - يستخدم `withoutGlobalScope(DoctorIsolationScope::class)` → يتجاوز العزل
   - لا يتحقق من الـ Share permissions

3. **NativeSyncController::sync()**:
   - لا يتحقق من صلاحية الـ Token
   - أي مستخدم مسجل يمكنه طلب مزامنة

### الملفات المتأثرة:
- `app/Repositories/Eloquent/EloquentPatientFileRepository.php`
- `app/Http/Controllers/Api/CategoryFileController.php`
- `app/Http/Controllers/NativeSyncController.php`

---

## 📊 ملخص الأولويات

| الأولوية | المشكلة | التأثير |
|----------|---------|---------|
| 🔴 عاجل | #1 Dual Architecture | النظام بأكمله غير متسق |
| 🔴 عاجل | #3 File Fetch Failures | المستخدم لا يرى الملفات |
| 🔴 عاجل | #4 ID Resolution Bug | الملفات Orphaned في SQLite |
| 🔴 عاجل | #6 No Binary Download | لا يوجد Offline حقيقي للملفات |
| 🟡 عالي | #2 Sync Logic Duplication | تضارب وصعوبة الصيانة |
| 🟡 عالي | #5 HybridRepository Bypass | الـ Hybrid لا يعمل أبداً |
| 🟡 عالي | #8 Sync Race Conditions | بيانات غير متسقة |
| 🟡 عالي | #11 Observer Conflict | مزامنة مكررة للملفات |
| 🟢 متوسط | #7 File URLs | 404 للملفات غير المحملة |
| 🟢 متوسط | #9 Queue Verification | فقدان بيانات Offline |
| 🟢 متوسط | #10 Token Management | انتهاء الجلسة بدون إشعار |
| 🟢 متوسط | #13 Category Loading | فشل تحميل الملفات حسب التصنيف |
| 🔵 منخفض | #12 DoctorIsolationScope | ملفات مخفية للمستخدمين المشاركين |
| 🔵 منخفض | #14 Field Mapping | فقدان بعض البيانات |
| 🔴 عاجل | #16 Timing Race Condition | أول مرة تفتح مريض → 2 syncs يتضاربان |
| 🟡 عالي | #17 Remote vs Local API | API مختلفين تماماً: MySQL vs SQLite |
| 🔵 منخفض | #18 SoftDeletes غير متزامن | حذف الملفات لا يتم مزامنته |
| 🔵 منخفض | #15 Permissions | ثغرات صلاحيات |

---

## 💡 الحلول المقترحة حسب الأولوية

### المرحلة 1 - إصلاحات عاجلة (تجعل التطبيق يعمل أساساً):
1. توحيد المسارين → استخدام `HybridPatientFileRepository` في كل مكان
2. إزالة Bootstrap من `patientData()` → الاعتماد على `syncMetadataOnly()`
3. إصلاح `EloquentPatientFileRepository` ليعيد مصفوفة فارغة بدلاً من Exception
4. توحيد Field Mapping في `FullSyncService` وإزالة التكرار

### المرحلة 2 - تحسين Offline:
1. إضافة `syncFileBinaries()` لتحميل الملفات الفعلية
2. إضافة `is_cached_locally` و `remote_url` للملفات
3. تحميل الملفات عند أول طلب (lazy download)
4. إضافة Progress indicator للمستخدم

### المرحلة 3 - تحسين الـ Sync Queue:
1. إضافة max retry limit
2. عرض حالة الـ Queue
3. Force Sync يدوي
4. تنظيف تلقائي للفاشل

### المرحلة 4 - إصلاح Timing & SoftDeletes:
1. إضافة guard في `selectPatient()` لمنع bootstrap إذا كان sync قيد التشغيل
2. إضافة `deleted` و `restored` events إلى `PatientFileObserver`
3. توثيق الفرق بين Remote API و Local API في الكود

### المرحلة 5 - تحسين الـ Auth & Permissions:
1. Refresh Token تلقائي
2. تحسين التحقق من الصلاحيات في Repositories
3. إظهار رسالة انتهاء الجلسة للمستخدم

---

## 16. Timing Race Condition - أول نقرة على المريض

### المشكلة
عندما يُحمل `DoctorWorkspace.vue`:
1. `onMounted()`: يحمل المرضى من Inertia props (فوري)
2. بعد 100ms: يشغل `syncAndRefresh()` في الخلفية → `/api/native/sync` → `syncMetadataOnly()`
3. **إذا ضغط المستخدم على مريض قبل انتهاء الـ Sync** → `selectPatient()` → `patientData()` → يجد local files فارغة → يشغل Bootstrap الخاص به من API

**النتيجة**: عمليتا مزامنة تجريان بالتوازي وتكتبان في SQLite في نفس الوقت.

### الملفات المتأثرة:
- `resources/js/Pages/DoctorWorkspace.vue` (السطور 355-388)
- `resources/js/Composables/useWorkspace.js` (selectPatient, syncAndRefresh)
- `app/Http/Controllers/WorkspaceController.php` (patientData bootstrap)

### التأثير:
- **تضارب كتابة في SQLite**: عمليتان تكتبان نفس البيانات في نفس الوقت
- **فقدان ملفات**: يمكن أن تنتهي إحدى العمليتين ببيانات غير كاملة
- **إبطاء التطبيق**: المستخدم ينتظر Bootstrap بينما Sync يعمل بالفعل

### الحل المطلوب:
- إضافة `syncInProgress` flag يمنع Bootstrap في `patientData()` إذا كان Sync قيد التشغيل
- أو: إزالة Bootstrap بالكامل من `patientData()` والاعتماد على `syncAndRefresh` فقط

---

## 17. فصل تام: Remote API vs Local API

### المشكلة
النظام لديه **API endpoint مختلفين تماماً** يعملان على **Database مختلفين**:

#### الـ Remote API (`https://prof-hosam-fekry.online/api/v1/mobile/`):
- معرف في `routes/api.php`
- يستخدم **MySQL في السيرفر البعيد**
- `ApiPatientRepository`, `ApiPatientFileRepository` يتصلون به
- بياناته هي **المصدر الأساسي** (Source of Truth)

#### الـ Local API (`/api/v1/workspace/`):
- معرف في `routes/web.php` (تحت `auth` middleware)
- يستخدم **SQLite المحلي** (في التطبيق)
- `EloquentPatientRepository`, `EloquentPatientFileRepository` يقرأون منه
- بياناته هي **Cache محلي** (Local Cache)

### أين يحدث الخلط:
```php
// ApiPatientFileRepository::forPatient() -> يتصل بالـ Remote API
return $this->apiCall('GET', '/patients/' . $patientUuid . '/files')->json() ?? [];

// Mobile\FileController::index() -> يقرأ من MySQL (على السيرفر)
$files = $query->paginate(...);

// WorkspaceController::patientData() -> يقرأ من SQLite المحلي
$allFiles = $this->eloquentFileRepo->forPatient($uuid);
```

### التأثير:
- الملفات المرفوعة عبر الموقع تظهر في Remote MySQL فقط
- الملفات المرفوعة عبر التطبيق تظهر في Local SQLite فقط
- **المزامنة هي الحلقة الوحيدة بينهما**، وإذا فشلت لا ترى الملفات

### الحل المطلوب:
- توثيق الفرق بين API endpoints في `ARCHITECTURE.md`
- التأكد أن كل Sync كامل يجلب جميع الملفات من Remote إلى Local
- إضافة عملية `syncFileBinaries()` تنزيل الملفات الفعلية

---

## 18. SoftDeletes غير متزامن مع Observer

### المشكلة
`PatientFile` يستخدم `SoftDeletes` trait، و `PatientFileObserver` يراقب فقط:
```php
class PatientFileObserver
{
    public function created(PatientFile $file) { ... }  // ✅ موجود
    // public function deleted(PatientFile $file) { ... }  ❌ غير موجود
    // public function restored(PatientFile $file) { ... }  ❌ غير موجود
}
```

إذا قام أي كود باستدعاء `$file->delete()` مباشرة (بدون المرور بـ `HybridPatientFileRepository::delete()`)، فإن:
1. الملف يُحذف محلياً (soft delete)
2. لا يتم إضافة أي item إلى `sync_queue`
3. **الـ Remote API لا يعلم بالحذف أبداً**
4. عند المزامنة التالية، `syncMetadataOnly()` لا يعيد حذف الملف (لأنه ما زال موجوداً في Remote DB)

### الملفات المتأثرة:
- `app/Observers/PatientFileObserver.php`
- `app/Domains/Media/Models/PatientFile.php` (يستخدم SoftDeletes)
- `app/Repositories/Hybrid/HybridPatientFileRepository.php` (
حذف `delete()` يضيف إلى الـ queue يدوياً)

### التأثير:
- ملفات محذوفة محلياً تبقى في Remote API للأبد
- مزامنة غير مكتملة
- اختلاف البيانات بين Local و Remote

### الحل المطلوب:
- إضافة `deleted()` و `restored()` events إلى `PatientFileObserver`
- أو إزالة `SoftDeletes` والاعتماد على الـ Queue فقط
