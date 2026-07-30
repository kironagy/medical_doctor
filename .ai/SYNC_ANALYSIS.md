# Medical Plus — تحليل شامل: Sync + SQLite + Notes + Bugs

> **منهجية:** تتبع cycle كل ملف من ملف لملف، مبني على الكود الفعلي لا توقعات.
> **البيئتان:** App (NativePHP Android + SQLite) ↔ Website (MySQL Production at prof-hosam-fekry.online)

---

## 1. المعمارية الكاملة

```
ANDROID APP (NativePHP)
  WebView
    Vue Frontend (DoctorWorkspace.vue / CategoryBlock.vue)
      ↕ axios
    Composables: useWorkspace.js / useSyncEngine.js
      ↕ HTTP (localhost)
  Embedded Laravel (Local PHP Server)
    routes/web.php + routes/api.php
    SQLite (storage/data/medical_plus.sqlite)
      patients | patient_notes | offline_files
    ApiService.php → HTTP → Production

PRODUCTION SERVER (prof-hosam-fekry.online)
  MySQL Database — Source of Truth
  /api/v1/mobile/* routes
```

---

## 2. Sync Cycle — الـ Flow الكامل لإضافة Note (من ملف لملف)

```
[User] اضغط "إضافة" في CategoryBlock.vue:L9
  → openAddRecord('text')
  → AddRecordModal.vue يفتح

[AddRecordModal.vue:L184] submit()
  → checks navigator.onLine
  → axios.POST apiUrl(`/api/v1/mobile/patients/{uuid}/notes`)
     Body: { content, category: props.categorySlug }
     Headers: { Authorization: 'Bearer ' + localStorage.np_api_token }

[Utils/api.js:L43] apiUrl()
  - App  (window.NativePHP exists) → /api/v1/mobile/... → LOCAL PHP
  - Website (no window.NativePHP)  → /api/v1/...        → PRODUCTION

[App path] → routes/web.php:L133 → Mobile/NoteController.php:L34
  → captures bearer token → ApiService::setToken()
  → PatientNote::create([sync_status => 'pending_create']) in SQLite
  → returns 201 { note }

[AddRecordModal.vue:L213] addNoteLocally(createdNote)  ← UI يتحدث فوراً
[AddRecordModal.vue:L221] triggerSync()

[useSyncEngine.js] triggerSync()
  → POST /_native/api/sync/engine
     Headers: { Authorization: 'Bearer ' + np_api_token }

[routes/web.php:L261] /engine handler
  → captures bearer token again → ApiService::setToken()
  → SyncEngineService::syncAll()

[SyncEngineService.php:L606] syncPendingNotes()
  → SELECT notes WHERE sync_status = 'pending_create'
  → ApiService::post("/patients/{uuid}/notes", { content, category })
     URL: https://prof-hosam-fekry.online/api/v1/mobile/patients/{uuid}/notes
  → Production returns { uuid: remoteUuid }
  → UPDATE patient_notes SET sync_status='synced', remote_uuid=remoteUuid
```

---

## 3. المشاكل المكتشفة (مبنية على الكود الفعلي)

---

### BUG-01 [CRITICAL]: Note من DoctorWorkspace يتحفظ بدون category

**الملف:** `resources/js/Pages/DoctorWorkspace.vue:L784`

```js
// الكود الحالي — المشكلة:
const res = await axios.post(
    apiUrl(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`),
    { content: noteFormContent.value },   // ← بدون category!
    getApiConfig()
)
```

**الأثر:**
```
Api/NoteController.php:L38
  category = $validated['category'] ?? 'notes'
  // يتحفظ كـ 'notes' بدل slug التصنيف الفعلي
  // CategoryBlock يفلتر: n.category === props.slug
  // → Note غير مرئي في أي category غير 'notes'
```

**الحل:**
```js
axios.post(url, {
    content: noteFormContent.value,
    category: activeCategory.value?.slug || 'notes',  // ← أضف هذا
}, getApiConfig())
```

---

### BUG-02 [CRITICAL]: Visit Sync يستخدم URL غير موجود

**الملف:** `resources/js/Pages/DoctorWorkspace.vue:L856`

```js
// الكود الحالي — خاطئ:
axios.post('/_native/api/sync').catch(() => {})

// الـ route الصحيح (routes/web.php:L261):
// POST /_native/api/sync/engine
// الـ route القديم تم حذفه (SYNC-005 FIX)
```

**الأثر:** الـ Visits لا تُسنك لحد ما الـ heartbeat (كل 30 ثانية) يشتغل.

**الحل:**
```js
axios.post('/_native/api/sync/engine').catch(() => {})
```

---

### BUG-03 [CRITICAL]: Note يختفي بعد refreshWorkspaceData عند sync سريع

**الملف:** `resources/js/Composables/useWorkspace.js:L284-291`

```js
// الكود الحالي:
const localNotes = (workspaceSnapshot.notes || []).filter(n =>
    !serverNoteUuids.has(n.uuid) &&
    (n.sync_status === 'pending_create' || n.sync_status === 'pending')
    // ← لا يحتفظ بـ sync_status = 'synced'!
)
```

**السيناريو المكسور:**
```
1. User adds note → sync_status = 'pending_create'
2. SyncEngine syncs → sync_status = 'synced' (SQLite)
3. refreshWorkspaceData() runs (race condition!)
4. Production server didn't receive note yet
5. server notes = [] → serverNoteUuids = empty set
6. localNotes filter: sync_status NOT in ['pending_create','pending']
   → note يختفي من workspaceSnapshot!
7. workspaceData.notes = [] → Note غير مرئي
```

**الحل:**
```js
const localNotes = (workspaceSnapshot.notes || []).filter(n =>
    !serverNoteUuids.has(n.uuid) &&
    (n.sync_status === 'pending_create' ||
     n.sync_status === 'pending' ||
     n.sync_status === 'synced')  // ← أضف هذا
)
```

---

### BUG-04 [HIGH]: ApiService — الـ token قد لا يكون متاح لـ SyncEngineService

**الملف:** `app/Services/Mobile/ApiService.php`

```php
// Constructor (يعمل في كل request جديد):
$encrypted = session('api_token');
if ($encrypted) {
    $this->token = decrypt($encrypted);
}
if (empty($this->token)) {
    $this->loadTokenFromFile();
}
```

**المشكلة:**
```
app(ApiService::class) في /engine route handler
  → setToken($bearerToken)
  → session(['api_token' => encrypt($token)])  ← يكتب في session
  → writeTokenToFile($token)                    ← يكتب في ملف

app(SyncEngineService::class)
  → new ApiService() [constructor]
  → session('api_token')  ← هل الـ session ID نفسه؟
  → إذا app() لم يكن singleton → instance مختلف → token مختلف
```

**الحل:** تسجيل ApiService كـ singleton في AppServiceProvider:
```php
// AppServiceProvider::register()
$this->app->singleton(\App\Services\Mobile\ApiService::class);
```

---

### BUG-05 [HIGH]: Note لا يظهر في CategoryBlock بعد الإضافة مباشرة

**الملف:** `resources/js/Components/workspace/CategoryBlock.vue:L477-531`

**الـ Flow المكسور:**
```
AddRecordModal.vue → emit('noteAdded')
  → CategoryBlock:L357 → @noteAdded="loadCategoryData(1)"
  → GET /api/v1/patients/{uuid}/categories/{slug}/files
  → CategoryFileController → queries PRODUCTION DB
  → Production ليس عنده الـ note بعد (لا يزال pending_create)
  → response.data.notes = []

CategoryBlock:L520-521:
  const workspaceLocalNotes = (allNotes.value || []).filter(
      n => n.category === props.slug && !freshServerUuids.has(n.uuid)
  )
  // allNotes.value = workspaceData.notes (production data)
  // Production لم تستقبل الـ note بعد!
  // → workspaceLocalNotes = []
```

**لكن addNoteLocally() شغال:** يضيف لـ workspaceData.notes مباشرة.

**المشكلة الدقيقة:** `loadCategoryData()` يُعيد تحميل `serverNotes` من production مما يعيد override الـ merge logic.

**الحل المقترح:** تأخير `loadCategoryData()` بعد emit بوقت كافٍ للـ sync:
```js
// CategoryBlock.vue
@noteAdded="() => setTimeout(() => loadCategoryData(1), 3000)"
// أو: انتظر حتى SyncEngine يُشير بانتهاء الـ sync
```

---

### BUG-06 [HIGH]: Patient UUID mismatch بعد الـ Sync

**الملف:** `app/Services/SyncEngineService.php:L313-346`

```php
// عند sync patient من offline:
if ($remoteUuid !== $localUuid) {
    // يعدل patients table: uuid = remoteUuid ✅
    // يعدل offline_files: patient_uuid = remoteUuid ✅
    // ❌ لا يعدل patient_notes!
}
```

**الأثر:** Notes المحفوظة أثناء offline بعد UUID mismatch:
```
local patient uuid = 'local-abc'
note.patient_id → FK to patients.id (not uuid) → OK!

لكن في syncPendingNotes():
$patient->uuid → الـ new uuid 'server-xyz' ✅
POST /api/v1/mobile/patients/server-xyz/notes → OK
```

**ملاحظة:** هذا الـ bug أقل خطورة مما بدا، لأن الـ FK هو ID مش UUID. لكن يحتاج تأكيد.

---

### BUG-07 [MEDIUM]: Double onMounted في DoctorWorkspace.vue

**الملف:** `resources/js/Pages/DoctorWorkspace.vue`

```js
// onMounted #1 (L351): refreshPatientList() ← مرتين!
onMounted(() => { ... refreshPatientList() ... })

// onMounted #2 (L876): history.pushState + performance
onMounted(() => { ... })
```

**الأثر:** API calls مزدوجة عند mount. يمكن دمجهما في onMounted واحد.

---

## 4. جدول الأولويات

| # | البـاg | الأولوية | الملف | السطر |
|---|--------|----------|-------|-------|
| 1 | Note بدون category → يتحفظ كـ 'notes' | 🔴 Critical | DoctorWorkspace.vue | 784 |
| 2 | Visit sync URL غاطئ | 🔴 Critical | DoctorWorkspace.vue | 856 |
| 3 | Note يختفي بعد sync سريع | 🔴 Critical | useWorkspace.js | 284 |
| 4 | ApiService مش singleton | 🟠 High | AppServiceProvider.php | — |
| 5 | CategoryBlock لا يعرض note فوراً | 🟠 High | CategoryBlock.vue | 357 |
| 6 | Patient UUID mismatch + notes | 🟠 High | SyncEngineService.php | 313 |
| 7 | Double onMounted | 🟡 Medium | DoctorWorkspace.vue | 351, 876 |

---

## 5. الـ Token Flow الكامل

```
[Login]
  POST https://prof-hosam-fekry.online/api/v1/login
  ← { token: "5|sanctum_hash" }
  localStorage.setItem('np_api_token', token)

[App Restart]
  POST /api/session/restore { api_token: token }
  → web.php:L39 → ApiService::setToken(token)
    → session(['api_token' => encrypt(token)])
    → writeTokenToFile(token)  [app/.api_sync_token]

[Add Note]
  POST /api/v1/mobile/.../notes
  Authorization: Bearer <token>
  → Mobile/NoteController:L34 → ApiService::setToken(bearerToken)

[Trigger Sync]
  POST /_native/api/sync/engine
  Authorization: Bearer <token>
  → web.php:L278 → ApiService::setToken(bearerToken)
  → SyncEngineService::syncAll()
    → new ApiService() [reads from session or file]
    → api->post(production_url, data)
```

---

## 6. SQLite Schema (local)

```sql
-- patients
sync_status: pending_create | syncing | synced | pending_update | pending_delete

-- patient_notes
sync_status: pending_create | synced | pending_delete
category: varchar (slug of category)
remote_uuid: nullable (set after sync)

-- offline_files
sync_status: pending_upload | uploading | synced | failed

-- sessions (SESSION_DRIVER=database)
id | user_id | ip_address | user_agent | payload | last_activity
-- الـ token بيتحفظ هنا مشفر (ثم في ملف app/.api_sync_token)
```

---

## 7. Files Map — كل ملف بيعمل إيه

| الملف | الدور | الـ Destination |
|-------|-------|----------------|
| DoctorWorkspace.vue | Main page + note/visit forms | useWorkspace + axios |
| CategoryBlock.vue | يعرض files + notes per category | CategoryFileController |
| AddRecordModal.vue | Modal للإضافة | Mobile/NoteController + triggerSync |
| useWorkspace.js | Global state (patients, notes, files) | /api/v1/workspace/* |
| useSyncEngine.js | Heartbeat + trigger sync | /_native/api/sync/engine |
| Utils/api.js | URL routing: App vs Website | كل الـ components |
| Mobile/NoteController.php | حفظ note محلياً (SQLite) | PatientNote model |
| Api/NoteController.php | حفظ note على production (MySQL) | PatientNote model |
| SyncEngineService.php | الـ sync engine الكامل | ApiService → Production |
| ApiService.php | HTTP client للـ production | prof-hosam-fekry.online |
| WorkspaceController.php | Patient data + workspace payload | PatientNoteRepo + FileRepo |
| routes/web.php | كل الـ routes | Controllers |

---

## 8. الإصلاحات المطلوبة (بالترتيب)

### Fix 1 — DoctorWorkspace.vue:L784 (أضف category)
```js
// قبل:
{ content: noteFormContent.value }

// بعد:
{ content: noteFormContent.value, category: 'notes' }
// ملاحظة: DoctorWorkspace note modal مش مربوط بـ category معين
// يحتاج ربط أو إضافة category selector
```

### Fix 2 — DoctorWorkspace.vue:L856 (صحح URL)
```js
// قبل:
axios.post('/_native/api/sync').catch(() => {})

// بعد:
axios.post('/_native/api/sync/engine').catch(() => {})
```

### Fix 3 — useWorkspace.js:L287 (احتفظ بـ synced notes)
```js
// قبل:
(n.sync_status === 'pending_create' || n.sync_status === 'pending')

// بعد:
(n.sync_status === 'pending_create' ||
 n.sync_status === 'pending' ||
 n.sync_status === 'synced')
```

### Fix 4 — AppServiceProvider.php (Singleton)
```php
// في register():
$this->app->singleton(\App\Services\Mobile\ApiService::class);
```

### Fix 5 — CategoryBlock.vue:L357 (تأجيل reload)
```js
// قبل:
@noteAdded="loadCategoryData(1)"

// بعد:
@noteAdded="onNoteAdded"

// في script:
function onNoteAdded(note) {
    // أضف locally فوراً ثم أعد التحميل بعد تأخير
    if (note) serverNotes.value = [note, ...serverNotes.value]
    setTimeout(() => loadCategoryData(1), 4000)
}
```

---

## 9. Debug: كيف تتحقق من المشاكل

```bash
# 1. تحقق من الـ notes على production DB
# اتصل بـ phpMyAdmin أو SSH:
SELECT id, uuid, content, category, sync_status, created_at
FROM patient_notes
ORDER BY created_at DESC
LIMIT 10;

# 2. تحقق من الـ category المحفوظة
# إذا كل الـ notes category = 'notes' → BUG-01 مؤكد

# 3. تحقق من الـ App logs
# storage/logs/laravel.log
grep "DIAG.ApiService" storage/logs/laravel.log | tail -30
grep "syncPendingNotes" storage/logs/laravel.log | tail -20

# 4. تحقق من الـ token
grep "NO token in session" storage/logs/laravel.log
# إذا ظهر كثير → BUG-04 مؤكد
```

---

## 10. Environment

```ini
# .env (App)
DB_CONNECTION=sqlite
DB_DATABASE=storage/data/medical_plus.sqlite
MOBILE_API_URL=https://prof-hosam-fekry.online/api/v1/mobile
SESSION_DRIVER=database
APP_DEBUG=false

# Detection logic (ApiService + Controllers):
config('database.default') === 'sqlite'
→ true  = App (local SQLite, no Sanctum)
→ false = Production Website (MySQL, Sanctum required)
```
