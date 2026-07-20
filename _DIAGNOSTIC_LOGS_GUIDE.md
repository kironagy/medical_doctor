# دليل قراءة الـ Diagnostic Logs

## الهدف من الـ logs
تتبع مسار بيانات المرضى من **قاعدة البيانات المركزية** حتى **الشاشة في التطبيق** لاكتشاف أين المريض موجود لكن مش شايف.

---

## الـ Logs Adds (by order of execution)

### 1. `[PATIENT_DEBUG] PatientController::index()` — طبقة API
```
[PATIENT_DEBUG] PatientController::index
```
🔍 **بيظهر فيه:** كم مريض موجود فعلاً في SQLite المركزية، كم رجع من الـ search/filter، uuid و names كلهم.

**اللي بنعرف منه:** المرضى دول موجودين فعلاً في قاعدة البيانات ولا لأ. لو هنا ومش موجودين في التطبيق → المشكلة في خط النقل أو السينك.

---

### 2. `[PATIENT_DEBUG] API raw response for ...` — طبقة HTTP (MakesApiRequests)
```
[PATIENT_DEBUG] API raw response for GET /patients
```
🔍 **بيظهر فيه:** الـ response body كامل من السيرفر — keys, total, meta, sample من uuids.

**اللي بنعرف منه:** API شايفة نفس الأرقام بتاعة PatientController ولا في مشكلة في الـ middleware أو الـ response formatting.

---

### 3. `[PATIENT_DEBUG] ApiPatientRepo::all()/search()/paginated()` — طبقة API Repo
```
[PATIENT_DEBUG] ApiPatientRepo::all()
[PATIENT_DEBUG] ApiPatientRepo::search()
[PATIENT_DEBUG] ApiPatientRepo::paginated()
```
🔍 **بينجم فيه:** كم مريض رجع من الـ remote API call بعد الـ response parsing.

---

### 4. `[PATIENT_DEBUG] FullSyncService::syncAll() - patients from remote` — السينك
```
[PATIENT_DEBUG] FullSyncService::syncAll() - patients from remote
[PATIENT_DEBUG] FullSyncService::syncAll() - LOCAL SQLite after sync
```
🔍 **بيظهر فيه:**
- كم مريض جاب من الـ remote API
- كم مريض متخزن في SQLite المحلي **بعد** الـ sync

**اللي بنعرف منه:**
- لو remote gave X and local after = X → السينك تمام، المشكلة في ال fetching
- لو remote gave X and local after < X → مشكلة في الـ conflict resolution أو الـ sync
- لو remote gave X but local has different patients → مشكلة في الـ updateOrCreate logic

---

### 5. `[PATIENT_DEBUG] WorkspaceController::patientList() / index()` — طبقة Controller
```
[PATIENT_DEBUG] WorkspaceController::patientList() - API returned
[PATIENT_DEBUG] WorkspaceController::patientList() - local SQLite after sync
[PATIENT_DEBUG] WorkspaceController::patientList() - FINAL response sent
[PATIENT_DEBUG] WorkspaceController::index() - FINAL
```
🔍 **بيظهر فيه:** source (api/local/offline), count, uuids for EVERY patient sent to the app.

**اللي بنعرف منه:** الـ Controller بيجيب منين (API ولا local fallback) وممكن نعترف أما المريض اتنحى.

---

### 6. `[PATIENT_DEBUG] EloquentPatientRepo::all()/paginated()/search()` — طبقة Local SQLite
```
[PATIENT_DEBUG] EloquentPatientRepo::all()
[PATIENT_DEBUG] EloquentPatientRepo::paginated()
[PATIENT_DEBUG] EloquentPatientRepo::search()
```
🔍 **بيظهر فيه:** الـ local SQLite فعلاً فيه إيه — كنترول عشان نعرف لو المشكلة من هنا.

---

### 7. `[PATIENT_DEBUG] HybridPatientRepo::all()` — طبقة Hybrid Gate
```
[PATIENT_DEBUG] HybridPatientRepo::all() - isOnline
[PATIENT_DEBUG] HybridPatientRepo::all() - API returned
[PATIENT_DEBUG] HybridPatientRepo::all() - ConnectionException
[PATIENT_DEBUG] HybridPatientRepo::all() - FINAL
```
🔍 **بيظهر فيه:** الاتصال بالـ remote اتكسر ولا لأ، وماذا جاب من API vs local.

---

### 8. `[PATIENT_DEBUG] Conflict resolution - skipped patient` — الـ conflicts
```
[PATIENT_DEBUG] Conflict resolution - skipped patient
```
🔍 **بيظهر فيه:** مريض موجود في API بس **مش متخزن محلياً** عشان الـ local version عليه `client_updated_at` أصعب من الـ server.

**هذا هو المشتبه به الأول!** لو بتشوف الـ patient uuid بتاع المريض هنا — ده السبب الرئيسي.

---

### 9. Vue Console Logs — طبقة الـ Frontend
```
[DoctorWorkspace] onMounted - Inertia props
[PatientSidebar] GET /api/v1/workspace/patients-list?page=
[PatientSidebar] UUIDs on page
```

---

## طريقة الاستخدام

1. أفتح التطبيق
2. عمل **Pull-to-Refresh** عشان يجيب بيانات جديدة
3. شوف `storage/logs/laravel.log` وابحث عن `[PATIENT_DEBUG]`
4. قارن:
   - موجود في `syncAll - patients from remote` ✅ ومش موجود في `syncAll - LOCAL SQLite after sync` ❌ → مشكلة في ال sync
   - موجود في `syncAll - LOCAL SQLite after sync` ✅ ومش موجود في `WorkspaceController::patientList() - FINAL` ❌ → مشكلة في ال fetch
   - موجود في `WorkspaceController - FINAL` ✅ ومش موجود في Vue console ❌ → مشكلة في الـ rendering أو الـ filter

---

## طريقة البحث في الـ log file
```bash
# كل الـ patient-related logs
grep "PATIENT_DEBUG" storage/logs/laravel.log

# بس الـ conflict logs
grep "Conflict resolution" storage/logs/laravel.log

# بس الـ Vue logs (في console التطبيق)
# افتح Chrome DevTools Console في NativePHP WebView
```
