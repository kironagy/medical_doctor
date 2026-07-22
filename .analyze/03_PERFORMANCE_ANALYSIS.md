# Performance Analysis

## 1. DATABASE QUERIES

### 1.1 N+1 Query Patterns

**Patient List Loading (WorkspaceController::patientData):**
```
Query 1: SELECT * FROM patients WHERE uuid = ? LIMIT 1
Query 2: SELECT * FROM patient_files WHERE patient_id = ? ORDER BY created_at DESC
Query 3: SELECT * FROM patient_notes WHERE patient_id = ? ORDER BY created_at DESC  
Query 4: SELECT * FROM patient_visits WHERE patient_id = ? ORDER BY created_at DESC
```
Four sequential queries every time a patient is opened. Each query waits for the previous to complete. With 200ms network latency (local API), patient data takes ~800ms minimum.

**File List Loading:**
The `EloquentPatientFileRepository::forPatient()` loads ALL files with `->latest()->get()` and returns ALL of them, even though `patientData()` only uses 50:
```php
return $this->patient->files()->latest()->get()->toArray();
// Then sliced to 50 in controller
```
For patients with hundreds of files, all are loaded into memory and 90%+ are discarded.

### 1.2 Missing Eager Loading

**Mobile PatientController::index():**
```php
$query = Patient::query()
    ->with('primaryDoctor:id,name,email')
    ->orderBy('created_at', 'desc');
```
Only eager loads `primaryDoctor`. But the DoctorIsolationScope subquery adds:
```sql
WHERE id IN (SELECT patient_id FROM patient_shares WHERE doctor_id = ? AND ...)
```
This subquery runs for EVERY patient query, even when no shares exist.

**Mobile PatientController::show():**
```php
$patient = Patient::with([
    'primaryDoctor:id,name,email',
    'visits' => fn($q) => $q->latest(),
    'files' => fn($q) => $q->latest(),
])->where('uuid', $uuid)->firstOrFail();
```
Loads ALL visits and ALL files for the patient in one query. No pagination. For patients with 1000+ files, this is a massive payload.

### 1.3 Pagination Issues

**Patient List: Hardcoded 10**
```php
// WorkspaceController::patientList()
$apiResult = $this->getApiPatientRepo()->paginated(10, $page, $status);
// Per_page = 10, always
```
The frontend's `refreshPatientList()` doesn't specify per_page:
```javascript
const res = await axios.get("/api/v1/workspace/patients-list", {
    params: { page },
});
```
Always fetches 10 patients. A doctor with 100 patients sees 10 at a time with 10 pages of pagination.

**Patient List: 100 from API**
```php
// ApiPatientRepository::paginated()
$body = $this->apiCall('GET', '/patients', ['per_page' => $perPage, 'page' => $page])->json() ?? [];
```
But wait — the HybridRepo uses `paginated(10, ...)` while FullSyncService uses `$perPage = 100`. Different code paths use different page sizes for the same data.

## 2. MEMORY

### 2.1 Vanity Metrics from Cache Files

The `.gitignore` includes `storage/framework/cache/` and other framework dirs. But `storage/data/medical_plus.sqlite` is tracked in version control. This SQLite file can grow to hundreds of MB as patients, files, and sync queue grow.

### 2.2 Unbounded Tracking Sets

In `useWorkspace.js`:
- `locallyCreatedPatients`: Set<UUID> — never cleaned for failed operations
- `locallyAddedFileUuids`: Set<UUID> — only cleaned on API confirmation
- `locallyAddedNoteUuids`: Set<UUID> — only cleaned on API confirmation

If a user creates 500 patients offline, all 500 UUIDs stay in memory until each is confirmed by the API. If the API doesn't confirm (sync permanently fails), they grow without bound.

### 2.3 Analytics from app.js Crash Logging

The global error handler in `app.js`:
```javascript
function captureError(context, error, extra = {}) {
    const payload = {
        context, message, stack, time, url, userAgent, ...extra,
    }
    fetch('/api/v1/log/client-error', {
        method: 'POST',
        body: JSON.stringify(payload),
    })
}
```
Every unhandled error sends a POST request to the server. On a device with persistent errors, this creates continuous network activity.

## 3. NETWORK

### 3.1 Excessive Sync Triggers

In a 10-minute session with a patient open:
1. Initial mount: syncAndRefresh() after 100ms
2. 30s: connectivityCheckInterval triggers
3. 2min: app.js periodic sync
4. 4min: app.js periodic sync
5. 6min: app.js periodic sync
6. 8min: app.js periodic sync
7. 10min: app.js periodic sync
8. + Any PTR, navigation, or manual actions

Each sync triggers:
1. POST /api/native/sync (30s timeout) — FullSyncService::syncMetadataOnly()
2. GET /api/v1/workspace/patients-list — 10 patients
3. GET /api/v1/workspace/{uuid} — patient data + files + notes + visits

That's 3 API calls per sync, repeated every 2 minutes automatically, plus triggers from navigation.

### 3.2 Inefficient Sync Payload

`FullSyncService::syncMetadataOnly()` fetches ALL patients with `per_page=1000`:
```php
$body = $this->apiCall('GET', '/patients', ['per_page' => 1000])->json() ?? [];
```
Then fetches files, notes, and visits for EVERY patient — even patients that haven't changed. For a practice with 500 patients, each with 10 files and 5 notes:
- 1 request: all 500 patients
- 500 requests: files for each patient  
- 500 requests: notes for each patient
- 500 requests: visits for each patient
- Total: 1501 API calls per sync cycle

The batching in `fetchChildResourcesBatched()` groups by 10, so it's ~150 batches of up to 10 requests each — still 150 sequential batches.

### 3.3 NetworkStatusService Caching

```php
public static function isOnline(): bool
{
    // Cache success for 60 seconds, failure for 15 seconds
    \Illuminate\Support\Facades\Cache::put($cacheKey, $online, $online ? 60 : 15);
}
```
The online status is cached for 60 seconds. During this window, if connectivity drops, the app believes it's online and tries API calls that will fail. Each failed API call then triggers `NetworkStatusService::handleThrowable()` which sets `self::$isOnline = false` — but the cache still has `true` for the TTL. Other parts of the code that call `isOnline()` will still get the cached `true`.

## 4. VUE RENDERING

### 4.1 CategoryBlock Re-renders

The `DoctorWorkspace` template:
```html
<CategoryBlock v-for="cat in displayCategories" :key="cat.slug" ... />
```
Every time `workspaceData` changes (refresh, add file, edit note), ALL category blocks re-render because `displayCategories` recomputes from the new object.

### 4.2 Timeline Re-computation

```javascript
const allTimelineEvents = computed(() => {
    const events = [];
    // ... loops through files, notes, visits
    events.sort((a, b) => new Date(b.date) - new Date(a.date));
    return events;
});
```
This computed runs on EVERY `workspaceData` change. For patients with 50 files, 30 notes, 20 visits = 100 iterations + sort. The result is watched to update `timelineItems`:
```javascript
watch(allTimelineEvents, (events) => {
    timelineItems.value = events.slice(0, timelinePageSize);
    ...
}, { immediate: true });
```
Each workspaceData change cascades through ALL this computation.

### 4.3 Inertia Props Bloat

```php
return Inertia::render('DoctorWorkspace', [
    'patients' => $patients,      // All patients (could be 1000+)
    'categories' => $categories,  // All categories
    'user' => [...],               // User data
]);
```
The entire patient array is serialized into HTML as Inertia props. For 100 patients with full details (name, phone, code, address, etc.), this can be 100KB+ of JSON embedded in the HTML page.

### 4.4 Performance.mark Measurements

The code has `performance.mark('vue-mount-start')`, `performance.mark('inertia-nav-start')` measurements. These are manual profiling markers but they aren't used for any adaptive behavior. They just log to console.

## 5. SQLITE

### 5.1 Foreign Key Constraint Toggling

Multiple sync operations toggle foreign keys:
```php
try { DB::statement('PRAGMA foreign_keys = OFF'); } catch (\Throwable $e) {}
// ... sync operations ...
try { DB::statement('PRAGMA foreign_keys = ON'); } catch (\Throwable $e) {}
```
SQLite's `PRAGMA foreign_keys` is a per-connection setting. If two concurrent requests toggle this, they can interfere. One request sets OFF, another query runs with OFF, first sets ON — second is now running without FK enforcement.

### 5.2 updateOrCreate Without Locking

Multiple sync paths call `Patient::withoutGlobalScopes()->updateOrCreate()` concurrently. SQLite uses file-level locking for writes, but `updateOrCreate` is a SELECT + INSERT/UPDATE. Without explicit transactions, two concurrent calls can:
1. Both SELECT (neither finds record)
2. Both INSERT (one gets UNIQUE constraint violation)
3. Or: Both find record, both UPDATE (last write wins)

## 6. OPTIMIZATION ROADMAP

### Immediate (Critical):
1. Fix 10-patient pagination → increase to 50 or 100
2. Remove 50-file slice → implement proper pagination
3. Reduce periodic sync frequency or make it conditional on changes
4. Fix fetchChildResourcesBatched to use concurrent requests (currently sequential per patient)

### Short-term (High):
5. Implement incremental sync (only fetch changed records)
6. Cache sync_queue items in memory instead of querying DB for every status
7. Debounce workspaceData-triggered recomputations
8. Reduce Inertia props payload — only send necessary fields

### Medium-term (Medium):
9. Parallelize patient data queries (files, notes, visits concurrently)
10. Lazy-load patient files (only load when category is opened)
11. Implement Service Worker cache for API responses
12. Move tracking Sets to SQLite with periodic cleanup

### Long-term (Low):
13. Implement proper delta sync with timestamps
14. WebSocket-based real-time updates instead of polling
15. Image/video lazy loading with IntersectionObserver
