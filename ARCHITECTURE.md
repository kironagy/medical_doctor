# Medical Plus V3 — Complete Architecture Documentation

> **Generated:** 2026-07-19  
> **Source of truth:** The PHP/Laravel backend codebase and Vue/Inertia frontend at `/Users/kiro/Downloads/mediacal plus/Final_Medical/Medical_Plus_v3 2`  
> **Production website:** https://prof-hosam-fekry.online/  
> **Important:** This is a live production system with real users and real data. No business logic has been invented or assumed — every section below describes what the code actually implements.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [Architecture Principles](#3-architecture-principles)
4. [Project Structure](#4-project-structure)
5. [Domain Model & Database Schema](#5-domain-model--database-schema)
6. [Roles, Permissions & Authorization](#6-roles-permissions--authorization)
7. [Routing](#7-routing)
8. [Backend — Controllers, Services & Repositories](#8-backend--controllers-services--repositories)
9. [Frontend — Vue Pages, Components & Composables](#9-frontend--vue-pages-components--composables)
10. [Offline-First Sync Architecture](#10-offline-first-sync-architecture)
11. [Chunked File Upload Pipeline](#11-chunked-file-upload-pipeline)
12. [Media Processing & Streaming](#12-media-processing--streaming)
13. [User Flows](#13-user-flows)
14. [Key Business Rules](#14-key-business-rules)
15. [Dual API Layer](#15-dual-api-layer)
16. [NativePHP Mobile Integration](#16-nativephp-mobile-integration)
17. [Internationalization (i18n)](#17-internationalization-i18n)
18. [Observability & Logging](#18-observability--logging)
19. [Notable Architectural Inconsistencies & Technical Debt](#19-notable-architectural-inconsistencies--technical-debt)
20. [Glossary](#20-glossary)

---

## 1. Project Overview

Medical Plus V3 is a **medical practice management system** built for a doctor (Prof. Hosam Fekry). It manages patients, clinical visits, medical file attachments, doctor-to-doctor patient sharing, and administrative oversight.

The project serves **three clients** from the same Laravel backend:

| Client | Technology | Purpose |
|--------|-----------|---------|
| **Web browser** | Vue 3 + Inertia.js + Tailwind CSS v4 | Primary interface for doctors and super-admins |
| **Mobile (NativePHP)** | NativePHP (Android/iOS wrapper around the same Laravel app) | Offline-capable mobile client with local SQLite cache |
| **REST API** | Laravel Sanctum bearer tokens | Programmatic access for the mobile app and integrations |

The web application is the **reference implementation**. The mobile app must behave identically to the web application whenever applicable — the same validations, the same permissions, the same business rules, the same workflows.

---

## 2. Technology Stack

### Backend

| Layer | Technology | Details |
|-------|-----------|---------|
| Language | PHP 8+ | |
| Framework | Laravel 11+ | Uses `withRouting()` in `bootstrap/app.php` (no `RouteServiceProvider`) |
| Auth (web) | Laravel Sessions | Cookie-based, session driver |
| Auth (API) | Laravel Sanctum | Bearer token (`auth:sanctum` middleware) |
| Authorization | spatie/laravel-permission | Role-based: `super-admin`, `admin`, `doctor` |
| Database | MySQL (production) / SQLite (local dev) | |
| Offline DB | SQLite | `storage/data/` — local cache for mobile |
| Queue | Database driver | `sync_queue`, `pending_operations`, `sync_jobs` |
| Media processing | FFmpeg | Video optimization for streaming, thumbnail extraction |
| File storage | Local disk + S3 config | `storage/app/private` and `storage/app/public` |

### Frontend (Web)

| Layer | Technology | Details |
|-------|-----------|---------|
| Framework | Vue 3.5 | Composition API |
| Routing | Inertia.js v3 | Server-driven (no Vue Router) |
| Build tool | Vite 8 + laravel-vite-plugin | |
| UI framework | Tailwind CSS v4 | CSS-driven config (no `tailwind.config.js`) |
| Icons | @heroicons/vue | |
| i18n | vue-i18n 11 | English + Arabic (full RTL support) |
| Image cropping | cropperjs | Avatar upload |
| Image viewing | v-viewer / viewerjs | File preview lightbox |
| Video playback | video.js | Custom VideoPlayer component |
| Code highlighting | highlight.js | PDF/text file viewer |
| HTTP client | Axios | |

### Mobile (NativePHP)

| Layer | Technology | Details |
|-------|-----------|---------|
| Runtime | NativePHP | Classic mode (full init per request) |
| Platforms | Android (SDK 35) + iOS | Portrait primary; landscape on Android |
| Local storage | SQLite | Same schema as production MySQL |
| Sync engine | Custom | Bidirectional sync via REST API |

---

## 3. Architecture Principles

### 3.1 Laravel is the Single Source of Truth

The Laravel backend owns:
- **Authentication** (both session-based web and Sanctum token-based API)
- **Authorization** (spatie roles + Gate policies + global Eloquent scopes)
- **Validation** (inline in controllers — no Form Request classes)
- **Business Logic** (all clinical rules, sharing rules, upload rules)
- **Database** (production MySQL, the only authoritative data store)
- **Security** (CSRF tokens, rate limiting, signed URLs)
- **Permissions** (granular per-patient access via `PatientShare`)
- **Reports** (admin doctor statistics, patient exports)
- **Medical Rules** (file categorization, visit type tracking, clinical data fields)
- **API Responses** (JSON serialization via Resources)

The mobile app **never reimplements** any of these. It consumes the REST API and renders natively.

### 3.2 The Web Application is the Reference

Before building any mobile feature:
1. Inspect the Laravel controller/service logic
2. Inspect the Vue component behavior
3. Understand the validation rules
4. Understand the permission checks
5. Reproduce the same behavior on mobile via the API

### 3.3 No WebView Policy

The mobile application must NOT:
- Embed the website in a WebView
- Render Vue/Inertia pages
- Access MySQL directly
- Bypass Laravel

Only API data + native UI. Never HTML. Never Vue. Never Inertia.

### 3.4 Separation of Concerns

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │ Vue + Inertia│  │  NativePHP   │  │  REST API Clients │  │
│  │  (Web SPA)   │  │  (Android)   │  │  (External)       │  │
│  └──────┬───────┘  └──────┬───────┘  └────────┬─────────┘  │
│         │                 │                    │             │
│         └─────────────────┼────────────────────┘             │
│                           │                                 │
│                    ┌──────▼──────────┐                     │
│                    │  HTTP / API     │                     │
│                    │  Controllers    │                     │
│                    └──────┬──────────┘                     │
│                           │                                 │
│                    ┌──────▼──────────┐                     │
│                    │  Service Layer  │                     │
│                    │  + Repositories │                     │
│                    └──────┬──────────┘                     │
│                           │                                 │
│                    ┌──────▼──────────┐                     │
│                    │   Eloquent ORM  │                     │
│                    │  + Scopes       │                     │
│                    └──────┬──────────┘                     │
│                           │                                 │
│                    ┌──────▼──────────┐                     │
│                    │   MySQL / SQLite│                     │
│                    │   (production)  │                     │
│                    └─────────────────┘                     │
└─────────────────────────────────────────────────────────────┘
```

---

## 4. Project Structure

```
Medical_Plus_v3/
├── app/
│   ├── Auth/                      # Login/logout actions, scopes
│   │   ├── Actions/
│   │   │   └── LoginAction.php    # Sanctum token creation
│   │   └── Scopes/
│   │       └── DoctorIsolationScope.php  # Global data isolation scope
│   ├── Console/
│   │   └── Commands/              # Artisan commands
│   ├── Contracts/
│   │   └── Repositories/          # Repository interfaces
│   │       ├── PatientFileRepositoryInterface.php
│   │       ├── PatientNoteRepositoryInterface.php
│   │       ├── PatientRepositoryInterface.php
│   │       ├── PatientVisitRepositoryInterface.php
│   │       └── UserRepositoryInterface.php
│   ├── Domains/                   # Domain-Driven Design layering
│   │   ├── ActivityLogs/
│   │   │   ├── Models/
│   │   │   │   └── ActivityLog.php
│   │   │   └── Services/
│   │   │       └── ActivityLogger.php  # Audit trail service
│   │   ├── Auth/
│   │   │   ├── Actions/
│   │   │   │   └── LoginAction.php
│   │   │   └── Scopes/
│   │   │       └── DoctorIsolationScope.php
│   │   ├── Media/
│   │   │   ├── Jobs/
│   │   │   │   ├── GenerateThumbnailJob.php
│   │   │   │   └── OptimizeVideoForStreaming.php
│   │   │   ├── Models/
│   │   │   │   ├── FileCategory.php
│   │   │   │   ├── PatientFile.php
│   │   │   │   └── UploadSession.php
│   │   │   ├── Resources/
│   │   │   │   └── FileResource.php
│   │   │   └── Services/
│   │   │       └── UploadService.php  # Direct (non-chunked) upload
│   │   ├── Mobile/
│   │   │   └── Resources/          # Mobile API resources (empty/minimal)
│   │   ├── Patients/
│   │   │   ├── Models/
│   │   │   │   ├── Patient.php
│   │   │   │   ├── PatientNote.php
│   │   │   │   ├── PatientShare.php
│   │   │   │   └── PatientVisit.php
│   │   │   ├── Resources/
│   │   │   │   └── PatientResource.php
│   │   │   └── Services/
│   │   │       ├── PatientService.php  # Ownership creation/transfer
│   │   │       └── ShareService.php    # Share management + activity logging
│   │   └── Users/
│   │       ├── Models/
│   │       │   └── User.php       # Doctor/Admin user with Spatie roles
│   │       └── Services/
│   │           └── PermissionService.php  # Seeds roles + permissions
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php     # Empty base controller
│   │   │   ├── AuthController.php       # Hybrid login (local SQLite → remote API)
│   │   │   ├── DashboardController.php  # Admin dashboard or doctor redirect
│   │   │   ├── PatientController.php    # Web patient CRUD + notes
│   │   │   ├── WorkspaceController.php  # Doctor workspace (hybrid online/offline)
│   │   │   ├── SettingsController.php   # Profile, avatar, password, preferences
│   │   │   ├── Admin/
│   │   │   │   ├── AdminController.php  # Admin dashboard stats
│   │   │   │   └── DoctorController.php # Admin doctor CRUD, suspend, stats
│   │   │   ├── Api/
│   │   │   │   ├── AuthController.php          # Sanctum token auth (login/logout/me)
│   │   │   │   ├── CategoryController.php      # File category CRUD
│   │   │   │   ├── CategoryFileController.php  # Paginated files+notes per category
│   │   │   │   ├── ChunkUploadController.php   # Chunked upload lifecycle
│   │   │   │   ├── FileAccessController.php    # File streaming, thumbnails, signed URLs
│   │   │   │   ├── GlobalSearchController.php  # Unified search (patients, files, doctors)
│   │   │   │   ├── NoteController.php           # Patient notes CRUD
│   │   │   │   ├── PatientShareController.php  # Patient sharing
│   │   │   │   ├── UploadController.php         # Direct single-file upload
│   │   │   │   └── UploadsController.php        # Second chunked upload impl (parallel)
│   │   │   │   ├── VisitController.php          # Patient visits CRUD
│   │   │   │   └── Mobile/
│   │   │   │       ├── DashboardController.php   # Mobile dashboard stats
│   │   │   │       ├── DoctorController.php       # Mobile doctor profile + search
│   │   │   │       ├── FileController.php         # Mobile file CRUD + streaming
│   │   │   │       ├── NoteController.php         # Mobile notes CRUD
│   │   │   │       ├── PatientController.php      # Mobile patient CRUD
│   │   │   │       ├── SearchController.php       # Mobile unified search
│   │   │   │       ├── ShareController.php        # Mobile patient sharing
│   │   │   │       └── VisitController.php        # Mobile visits CRUD
│   │   │   └── NativeSyncController.php  # Background sync (push + pull)
│   │   ├── Middleware/
│   │   │   ├── HandleInertiaRequests.php   # Shares auth.user.roles to every Inertia page
│   │   │   ├── NativePHPProfilerMiddleware.php  # Dev-only request profiling
│   │   │   ├── PreventBackHistory.php      # Cache-busting headers
│   │   │   └── SyncMiddleware.php          # Offline-first write interception
│   │   └── Jobs/
│   │       ├── ExportPatientFilesJob.php   # ZIP export of patient files
│   │       ├── FullSyncJob.php             # Queued full sync
│   │       ├── ProcessUploadedFileJob.php  # Dispatches thumbnail generation
│   │       └── SyncPendingOperationsJob.php # Replays legacy pending ops
│   ├── Models/
│   │   ├── PendingOperation.php   # Legacy offline queue entry
│   │   └── SyncQueueItem.php       # Canonical sync queue entry
│   ├── Observers/
│   │   └── PatientFileObserver.php # Dispatches video optimization on file create
│   ├── Policies/
│   │   └── PatientPolicy.php       # Gate-based authorization per patient
│   ├── Providers/
│   │   ├── AppServiceProvider.php  # Binds ApiService singleton, mobile migrations, startup sync
│   │   ├── NativeServiceProvider.php  # Whitelists NativePHP plugins
│   │   └── RepositoryServiceProvider.php  # Binds repo interfaces (Eloquent vs Hybrid)
│   ├── Repositories/
│   │   ├── Api/                    # Remote-only repositories (call REST API)
│   │   │   ├── ApiPatientRepository.php
│   │   │   ├── ApiPatientFileRepository.php
│   │   │   ├── ApiPatientNoteRepository.php
│   │   │   ├── ApiPatientVisitRepository.php
│   │   │   └── ApiUserRepository.php
│   │   │   └── Traits/
│   │   │       ├── DebugLogsHttp.php
│   │   │       └── MakesApiRequests.php
│   │   ├── Eloquent/               # Local-only repositories (query SQLite/MySQL)
│   │   │   ├── EloquentPatientRepository.php
│   │   │   ├── EloquentPatientFileRepository.php
│   │   │   ├── EloquentPatientNoteRepository.php
│   │   │   ├── EloquentPatientVisitRepository.php
│   │   │   └── EloquentUserRepository.php
│   │   └── Hybrid/                 # Offline-first orchestrator (API → local → queue)
│   │       ├── HybridPatientRepository.php
│   │       ├── HybridPatientFileRepository.php
│   │       ├── HybridPatientNoteRepository.php
│   │       ├── HybridPatientVisitRepository.php
│   │       └── HybridUserRepository.php
│   └── Services/
│       ├── ApiProxy.php            # Stub — always disabled
│       ├── FullSyncService.php     # Bidirectional sync orchestrator
│       ├── NetworkStatusService.php # Online/offline detection (60s cache)
│       ├── SyncQueueService.php    # Sync queue CRUD + state management
│       └── Upload/
│           ├── ChunkMergeService.php       # Merge chunks → PatientFile record
│           ├── ChunkUploadService.php      # Store individual chunks
│           ├── UploadChecksumService.php   # SHA-256 integrity verification
│           ├── UploadCleanupService.php    # Purge expired/cancelled sessions
│           └── UploadValidationService.php # Validate upload constraints
├── bootstrap/
│   └── app.php                      # Laravel 11 bootstrap with withRouting()
├── config/
│   ├── app.php
│   ├── auth.php                     # Guards: web (session) + sanctum (token)
│   ├── categories.php               # 6 default file categories (bilingual)
│   ├── database.php                 # MySQL (prod) + SQLite (dev) + Redis
│   ├── filesystems.php              # local, public, s3 disks
│   ├── nativephp.php                # NativePHP app packaging config
│   ├── permission.php               # spatie/laravel-permission config
│   ├── sanctum.php                  # Token expiry, abilities
│   └── services.php                 # Postmark, Resend, AWS SES, S3, Slack
├── database/
│   ├── factories/
│   │   └── UserFactory.php
│   ├── migrations/                  # 25 migrations
│   └── seeders/
│       └── DatabaseSeeder.php       # Seeds super-admin + doctor users
├── resources/
│   ├── css/
│   │   └── app.css                  # Tailwind v4 entry, Cairo/Inter fonts, custom theme
│   ├── js/
│   │   ├── app.js                   # Inertia app entry (3 persistent Vue roots)
│   │   ├── bootstrap.js             # Axios global config
│   │   ├── Components/
│   │   │   ├── BaseButton.vue       # Variant/size loading button
│   │   │   ├── BaseCard.vue         # Surface card
│   │   │   ├── BaseDialog.vue       # Full-featured dialog (Teleport, mobile bottom-sheet)
│   │   │   ├── BaseInput.vue        # Labeled input with error display
│   │   │   ├── GlobalDialog.vue     # Dialog singleton (confirm/alert, danger/success/warning)
│   │   │   ├── GlobalSearch.vue     # Debounced global search dropdown
│   │   │   ├── PullToRefresh.vue    # Touch-based pull-to-refresh with haptics
│   │   │   ├── ToastContainer.vue   # Toast notification system
│   │   │   ├── UnifiedMediaViewer.vue # Full-screen media viewer (video/image/pdf/text/audio)
│   │   │   ├── UploadManager.vue    # Fixed upload panel (pause/resume/retry/cancel)
│   │   │   ├── VideoPlayer.vue      # Custom HTML5 video player (PiP, speed, keyboard)
│   │   │   ├── admin/               # Admin-specific components
│   │   │   │   ├── AdminSidebar.vue
│   │   │   │   ├── AdminStatsBlock.vue
│   │   │   │   └── DoctorSummary.vue
│   │   │   └── workspace/           # Doctor workspace components
│   │   │       ├── AddPatientModal.vue
│   │   │       ├── AddRecordModal.vue      # Note creation + file upload
│   │   │       ├── CategoryBlock.vue       # Most complex component (~1200 lines)
│   │   │       ├── CategoryManagerModal.vue
│   │   │       ├── EditPatientModal.vue
│   │   │       ├── FileActions.vue         # Desktop overlay + mobile bottom-sheet
│   │   │       ├── InlineFilePreview.vue   # Full-screen preview with prev/next nav
│   │   │       ├── MobileBottomBar.vue     # 4-tab mobile nav
│   │   │       ├── PatientListSidebar.vue  # Paginated patient list
│   │   │       ├── PatientSummary.vue      # Patient info + action buttons
│   │   │       ├── QuickActions.vue        # 6 action buttons (share, appoint, notes, upload, history, categories)
│   │   │       ├── SettingsModal.vue       # Mobile-friendly settings drawer
│   │   │       ├── SharePatientModal.vue   # 3-step sharing flow
│   │   │       ├── WorkspaceHeader.vue     # Sticky patient header bar
│   │   │       └── WorkspaceModal.vue
│   │   ├── Composables/             # Shared reactive logic (module-level singletons)
│   │   │   ├── useDialog.js         # Global confirm/alert promise-based dialog
│   │   │   ├── useLocale.js         # Locale switching + RTL + debounced save
│   │   │   ├── useNativeBridge.js   # Android permission/camera/file bridge
│   │   │   ├── usePullToRefresh.js  # Touch-based PTR with haptics
│   │   │   ├── useTheme.js          # Light/dark/system + debounced save
│   │   │   ├── useToast.js          # Global toast notifications
│   │   │   ├── useUploadDiagnostics.js # Debug upload profiler (opt-in)
│   │   │   ├── useUploads.js        # Chunked upload engine with semaphore + resume
│   │   │   └── useWorkspace.js      # Central workspace state (30+ reactive refs)
│   │   ├── Layouts/
│   │   │   └── AppLayout.vue        # Shell layout: sidebar + header + main + mobile bottom-nav
│   │   ├── Locales/
│   │   │   ├── ar.json              # 601-line Arabic translation
│   │   │   ├── en.json              # 601-line English translation
│   │   │   └── en_temp.json         # Work-in-progress English backup
│   │   ├── Pages/
│   │   │   ├── Admin/
│   │   │   │   └── Dashboard.vue    # Admin dashboard (doctor stats, management)
│   │   │   │   └── Doctors/
│   │   │   │       ├── Create.vue  # Create new doctor
│   │   │   │       ├── Edit.vue    # Edit existing doctor
│   │   │   │       ├── Index.vue   # Doctor list (search + paginate)
│   │   │   │       └── Show.vue    # Doctor detail (patients/files tabs)
│   │   │   ├── Auth/
│   │   │   │   └── Login.vue        # Email/password login form
│   │   │   ├── Dashboard/
│   │   │   │   └── Index.vue        # User dashboard (stats cards)
│   │   │   ├── DoctorWorkspace.vue  # Main doctor workspace (multi-panel)
│   │   │   ├── PatientPrint.vue     # Print-optimized patient export view
│   │   │   └── Settings/
│   │   │       └── Partials/
│   │   │           ├── ProfileForm.vue    # Avatar + profile fields
│   │   │           ├── PasswordForm.vue   # Password change with strength meter
│   │   │           ├── PreferencesForm.vue # Theme + locale selectors
│   │   │           ├── CategoryForm.vue   # Category CRUD
│   │   │           └── DownloadAppForm.vue # APK download from GitHub releases
│   │   └── Plugins/
│   │       └── i18n.js              # vue-i18n instance setup
│   └── views/
│       └── app.blade.php            # Inertia root template
├── routes/
│   ├── api.php                      # Mobile API routes (Sanctum)
│   ├── console.php                  # Artisan `inspire` command
│   └── web.php                      # Web routes (Inertia pages + SPA JSON API)
├── storage/
│   ├── app/
│   │   ├── mobile-cache/            # Downloaded file cache for offline use
│   │   ├── private/                 # Private file storage
│   │   └── public/                  # Public file storage (patients/, uploads/)
│   ├── data/                        # SQLite database files
│   └── framework/                   # Cache, sessions, views
└── native/                          # NativePHP build artifacts
```

---

## 5. Domain Model & Database Schema

### 5.1 Entity-Relationship Overview

```
                    ┌──────────────┐
                    │    USERS     │ (doctors + admins)
                    │              │
                    │ role: doctor │◄──── role: super-admin / admin
                    │ status: act.│
                    └──────┬───────┘
                           │ 1:N (primary_doctor_id)
                           │
            ┌──────────────▼─────────────────────┐
            │            PATIENTS                 │
            │  (soft-deletable, UUID-based)       │
            │                                    │
            │  Clinical fields:                  │
            │  - name, phone, email, address     │
            │  - diagnosis                        │
            │  - date_of_birth, gender            │
            │  - blood_group, weight, height      │
            │  - allergies, chronic_diseases      │
            │  - medical_status, MRN             │
            │  - code (6-digit random)           │
            └──┬────────┬────────┬────────┬────┘
               │ 1:N    │ 1:N    │ 1:N    │ 1:N
     ┌─────────┘        │        │        │
     │                  │        │        │
┌────▼──────┐  ┌───────▼───┐ ┌──▼─────┐ ┌▼──────────┐
│ PATIENT   │  │ PATIENT   │ │ PATIENT│ │ PATIENT   │
│ FILES     │  │ VISITS    │ │ NOTES  │ │ SHARES    │
│ (media)   │  │(clinical) │ │(text)  │ │(access)   │
└───────────┘  └───────────┘ └────────┘ └───────────┘
     │               │             │              │
     │ 1:N           │             │              │ N:1
     ▼               ▼             ▼              ▼
┌──────────┐  ┌─────────────┐ ┌──────────┐  ┌──────────┐
│UPLOAD_   │  │ Uploaded by │ │ Author   │  │ doctor   │
│SESSIONS  │  │ doctor     │ │ doctor   │  │ (shared) │
│(chunked) │  └─────────────┘ └──────────┘  └──────────┘
└────┬─────┘
     │ 1:N
     ▼
┌──────────────────┐
│UPLOAD_CHUNK_     │
│  RECEIPTS        │
│(idempotent chunk │
│  tracking)       │
└──────────────────┘


Supporting infrastructure:
┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐
│  ACTIVITY_LOGS  │  │  SYNC_QUEUE      │  │ PENDING_       │
│  (audit trail)  │  │  (offline ops)   │  │ OPERATIONS     │
│                 │  │                  │  │ (legacy queue) │
└─────────────────┘  └──────────────────┘  └────────────────┘

┌─────────────────┐  ┌────────────────┐
│  SYNC_STATES    │  │  SYNC_JOBS     │
│  (checkpoints)  │  │  (batch tracker)│
└─────────────────┘  └────────────────┘
```

### 5.2 Core Tables

#### `users` — Doctors & Administrators

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | Surrogate key |
| `name` | varchar | Full name |
| `email` | varchar (UNIQUE) | Login credential |
| `password` | varchar | Hashed (auto-hashed via cast) |
| `role` | varchar, default `'doctor'` | `super-admin`, `admin`, or `doctor` |
| `phone` | varchar (nullable) | Contact number |
| `specialization` | varchar (nullable) | Medical specialization |
| `code` | varchar (nullable) | Doctor code (e.g., `DR-XXXXX`) |
| `status` | varchar, default `'active'` | `active` or `suspended` |
| `address` | varchar (nullable) | Physical address |
| `last_login_at` | timestamp (nullable) | Last authentication timestamp |
| `uuid` | uuid (unique) | API identifier |
| `avatar_path` | varchar (nullable) | Storage path for profile photo |
| `preferences` | json (nullable) | UI settings (theme, locale, custom_categories) |
| `client_updated_at` | timestamp (nullable) | Sync watermark for mobile |
| `email_verified_at` | timestamp (nullable) | |
| `remember_token` | varchar (nullable) | |

**Relationships:** hasMany patients (via `primary_doctor_id`)

#### `patients` — Medical Records

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | Primary API identifier |
| `primary_doctor_id` | bigint FK → users.id | Owner doctor (CASCADE DELETE) |
| `created_by_id` | bigint FK → users.id | Creator (nullOnDelete) |
| `code` | varchar (nullable) | Auto-generated 6-digit |
| `name` | varchar | Patient full name |
| `phone` | varchar (nullable) | |
| `email` | varchar (nullable) | |
| `address` | varchar (nullable) | |
| `diagnosis` | varchar (nullable) | Initial diagnosis |
| `date_of_birth` | date (nullable) | |
| `gender` | varchar (nullable) | |
| `blood_group` | varchar (nullable) | |
| `weight` | decimal(5,2) (nullable) | |
| `height` | decimal(5,2) (nullable) | |
| `allergies` | text (nullable) | |
| `chronic_diseases` | text (nullable) | |
| `medical_status` | varchar (nullable) | |
| `medical_record_number` | varchar (nullable) | MRN |
| `client_updated_at` | timestamp (nullable) | Sync watermark |
| `deleted_at` | timestamp (nullable) | Soft delete |

**Relationships:** belongsTo primaryDoctor; hasMany visits, files, notes, shares

#### `patient_visits` — Clinical Encounters

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `patient_id` | bigint FK → patients.id | CASCADE DELETE |
| `visit_type` | varchar (nullable) | e.g., "consultation", "follow_up" |
| `visit_type_custom` | varchar (nullable) | Free-text alternate |
| `reason` | varchar (nullable) | e.g., "checkup", "pain" |
| `reason_custom` | varchar (nullable) | Free-text alternate |
| `visit_date` | date (nullable) | |
| `visit_time` | time (nullable) | |
| `session_details` | json (nullable) | Structured clinical session data |
| `diagnosis` | text (nullable) | Clinical diagnosis for this visit |
| `prescription` | text (nullable) | Treatment prescription |
| `next_visit_date` | date (nullable) | Follow-up scheduling |
| `cost` | decimal(10,2), default 0.00 | Billing |
| `client_updated_at` | timestamp (nullable) | Sync watermark |
| `deleted_at` | timestamp (nullable) | Soft delete |

#### `patient_notes` — Clinical Notes

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `patient_id` | bigint FK → patients.id | CASCADE DELETE |
| `author_id` | bigint FK → users.id | CASCADE DELETE |
| `category` | varchar, default `'general'` | e.g., "medical_history", "pre_op" |
| `content` | longText | Full note body |
| `timestamps` | | |

#### `patient_files` — Media Attachments

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | API identifier |
| `patient_id` | bigint FK → patients.id | CASCADE DELETE |
| `uploaded_by_id` | bigint FK → users.id | CASCADE DELETE |
| `title` | varchar (nullable) | Display title |
| `desc` | text (nullable) | Description |
| `notes` | text (nullable) | Contextual notes |
| `tags` | text (nullable) | Free-text tags |
| `type` | varchar, NOT NULL | `image`, `video`, `audio`, `pdf`, `document` |
| `category` | varchar (nullable) | Free-text (not FK-linked) |
| `date` | date (nullable) | Clinical event date |
| `file_name` | varchar, NOT NULL | Original filename |
| `file_path` | varchar, NOT NULL | Storage path |
| `thumbnail_path` | varchar (nullable) | Thumbnail path |
| `mime_type` | varchar | For content-type serving |
| `size` | bigint | File size in bytes |
| `upload_status` | varchar, default `'ready'` | `ready` or `failed` |
| `client_updated_at` | timestamp (nullable) | Sync watermark |
| `deleted_at` | timestamp (nullable) | Soft delete |

**Computed accessors (not DB columns):** `url`, `thumbnail_url`, `name`, `extension`  
**Observer:** On `created`, dispatches `OptimizeVideoForStreaming` for video files

#### `patient_shares` — Doctor Access Control

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `patient_id` | bigint FK → patients.id | CASCADE DELETE |
| `doctor_id` | bigint FK → users.id | Doctor receiving access |
| `shared_by_id` | bigint FK → users.id | Who performed the share (nullOnDelete) |
| `access_level` | varchar, default `'read'` | `read`, `read_write`, `full` |
| `expires_at` | timestamp (nullable) | Optional expiry — NULL = permanent |
| `timestamps` | | |

**Unique constraint:** `(patient_id, doctor_id)` — one share per doctor-patient pair

#### `file_categories` — Global Category Lookup

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `name` | varchar | e.g., "Medical History" |
| `icon` | varchar (nullable) | Icon identifier |
| `color` | varchar (nullable) | Hex color code |
| `client_updated_at` | timestamp (nullable) | Sync watermark |
| `deleted_at` | timestamp (nullable) | Soft delete |

**6 default categories (from `config/categories.php`):**

| Slug | Name (EN) | Name (AR) | Icon | Color | Order |
|------|-----------|-----------|------|-------|-------|
| `medical_history` | Medical History | التاريخ المرضي | folder | `#0d9488` (teal) | 1 |
| `pre_op_radiology` | Pre-op Radiology | أشعة ما قبل العملية | camera | `#f59e0b` (amber) | 2 |
| `post_op_radiology` | Post-op Radiology | أشعة ما بعد العملية | camera | `#8b5cf6` (purple) | 3 |
| `operation_sheet` | Operation Sheet | ورقة العملية | clipboard | `#ef4444` (red) | 4 |
| `medications` | Medications | الأدوية | pill | `#3b82f6` (blue) | 5 |
| `notes` | Other Notes | ملاحظات أخرى | document | `#6b7280` (gray) | 6 |

#### `upload_sessions` — Chunked Upload Tracking

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | API identifier |
| `patient_id` | bigint FK → patients.id | CASCADE DELETE |
| `user_id` | bigint FK → users.id | Uploader |
| `original_name` | varchar | Client filename |
| `mime_type` | varchar | |
| `extension` | varchar(20) | |
| `total_size` | unsigned bigint | Total bytes |
| `total_chunks` | unsigned int | Calculated from chunk_size |
| `chunk_size` | unsigned int | 1MB–50MB per chunk |
| `status` | varchar(20), default `'pending'` | pending → uploading → completed | failed | expired |
| `checksum_algorithm` | varchar(20), default `'sha256'` | |
| `final_checksum` | varchar(64) (nullable) | Expected SHA-256 |
| `disk` | varchar(20), default `'local'` | Storage disk |
| `metadata` | json (nullable) | Title, desc, category, date |
| `expires_at` | timestamp | 6-hour default |
| `final_path` | varchar (nullable) | Direct-write optimization |
| `received_chunk_indexes` | json (nullable) | Legacy — superseded by receipts table |
| Indexes | `(user_id, status)`, `expires_at` | |

#### `upload_chunk_receipts` — Idempotent Chunk Tracking

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `session_id` | unsigned bigint FK | → upload_sessions.id |
| `chunk_index` | unsigned int | Sequence number |
| `received_at` | timestamp | CURRENT_TIMESTAMP |

**Unique constraint:** `(session_id, chunk_index)` — prevents duplicate receipts  
**Index:** `(session_id, received_at)`

#### `sync_queue` — Offline Operation Queue

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `entity` | varchar (indexed) | `Patient`, `PatientVisit`, `PatientNote`, `PatientFile`, `PatientShare` |
| `table_name` | varchar (indexed) | Target table |
| `record_uuid` | uuid (nullable, indexed) | The record's UUID (nullable as of M24) |
| `operation` | varchar | `CREATE`, `UPDATE`, `DELETE` |
| `payload` | longText (nullable) | Full record snapshot as JSON |
| `priority` | int, default 5 | Lower = higher priority |
| `retry_count` | int, default 0 | |
| `status` | varchar (indexed), default `'pending'` | `pending`, `processing`, `synced`, `failed` |
| `last_error` | text (nullable) | |
| `last_attempt_at` | timestamp (nullable) | |
| `available_at` | timestamp (indexed) | For delayed retry scheduling |
| `timestamps` | | |

#### `pending_operations` — Legacy Offline Queue (Simpler)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `entity_type` | varchar | e.g., "Patient", "PatientVisit" |
| `action` | varchar | CREATE/UPDATE/DELETE |
| `payload` | longText (nullable) | Full entity data |
| `timestamps` | | |

#### `sync_states` — State Checkpoints

| Column | Type | Notes |
|--------|------|-------|
| `key` | varchar (unique) | State identifier |
| `value` | json (nullable) | State data |
| `timestamps` | | |

#### `sync_jobs` — Batch Sync Tracking

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `status` | varchar | |
| `total_items` | int | |
| `processed_items` | int | |
| `failed_items` | int | |
| `error_message` | text (nullable) | |
| `started_at` | timestamp (nullable) | |
| `completed_at` | timestamp (nullable) | |
| `timestamps` | | |

#### `activity_logs` — Audit Trail

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid (unique) | |
| `user_id` | bigint FK → users.id | nullOnDelete |
| `action` | varchar | e.g., "login", "patient_updated", "file_uploaded" |
| `entity_type` | varchar (nullable) | e.g., "Patient", "PatientFile" |
| `entity_uuid` | uuid (nullable) | |
| `payload` | json (nullable) | Structured event data |
| `ip_address` | varchar(45) (nullable) | |
| `user_agent` | text (nullable) | |
| `timestamps` | | |

---

## 6. Roles, Permissions & Authorization

### 6.1 Three Built-in Roles

The system defines three roles managed by spatie/laravel-permission. No custom permissions are used in route middleware — role names are checked directly.

| Role | Description | Capabilities |
|------|-------------|-------------|
| `super-admin` | Highest authority | Access to ALL admin routes. Sees all patients regardless of `DoctorIsolationScope`. Can create/manage/suspend/delete doctors. Bypasses all data isolation. |
| `admin` | Second tier | Similar to super-admin but does NOT bypass `DoctorIsolationScope` for patient data. Can manage doctors. |
| `doctor` | Standard user | Sees only their own patients + patients shared with them via `PatientShare`. Full CRUD on owned patients. Can share patients they own. Cannot manage other doctors. |

### 6.2 PermissionCheck Hierarchy

Authorization is enforced through three complementary mechanisms, applied in this order:

```
1. ROUTE MIDDLEWARE (first line of defense)
   - role:super-admin → Entire /admin/* prefix
   - auth → All authenticated routes

2. GLOBAL ELOQUENT SCOPE (data-level filtering)
   - DoctorIsolationScope → Applied to Patient, PatientVisit, PatientNote, PatientFile
   - For doctors: filters rows to primary_doctor_id = me OR active share exists
   - For admins: no filtering (admins see all)

3. GATE POLICIES (action-level authorization)
   - PatientPolicy::view() → primary doctor + active share OR admin
   - PatientPolicy::update() → primary doctor + read_write share OR admin
   - PatientPolicy::delete() → primary doctor only (shared doctors cannot delete)
   - PatientPolicy::share() → primary doctor only
```

### 6.3 DoctorIsolationScope (Global Eloquent Scope)

This is the **core data isolation mechanism**. It is applied as a global scope to four models:
- `Patient`
- `PatientVisit`
- `PatientNote`
- `PatientFile`

**Scope logic:**
1. **Skip entirely** if running in console (artisan) AND not in tests — allows migrations, seeds, and artisan commands to see all data.
2. **Skip entirely** for `super-admin` and `admin` roles — admins see everything.
3. **For `doctor` role:**
   - On `patients` table: `WHERE primary_doctor_id = auth_user.id OR EXISTS (SELECT 1 FROM patient_shares WHERE patient_shares.patient_id = patients.id AND patient_shares.doctor_id = auth_user.id AND (patient_shares.expires_at IS NULL OR patient_shares.expires_at > NOW()))`
   - On all other tables (visits, notes, files): `WHERE patient_id IN (subquery of accessible patient IDs)` — uses direct `whereIn` to avoid "nested correlated subqueries" that cause issues on SQLite.

**Important:** The scope is deliberately bypassed in some API endpoints (admin doctor stats, category file listing) via `withoutGlobalScope(DoctorIsolationScope::class)`.

### 6.4 PatientShare — Discretionary Patient Sharing

Doctors can share their patients with other doctors through the `PatientShare` table:

| Field | Purpose |
|-------|---------|
| `patient_id` | Which patient |
| `doctor_id` | Which doctor gets access |
| `shared_by_id` | Who initiated the share (audit trail) |
| `access_level` | `read` (view only) or `read_write` (view + edit) |
| `expires_at` | NULL = permanent; future date = time-limited access |

The `DoctorIsolationScope` checks for **non-expired** shares: `expires_at IS NULL OR expires_at > NOW()`.

---

## 7. Routing

### 7.1 Route Files

The project has **3 route files** (standard Laravel 11 pattern, no `RouteServiceProvider`):

| File | Purpose |
|------|---------|
| `routes/web.php` | Web routes (Inertia pages, SPA JSON API, chunk upload, native sync) |
| `routes/api.php` | API routes (Sanctum-authenticated mobile API + file streaming) |
| `routes/console.php` | Artisan `inspire` command |

### 7.2 Middleware Configuration

Configured in `bootstrap/app.php` using Laravel 11's fluent API:

**Global middleware (only when `APP_DEBUG=true`):**
- `NativePHPProfilerMiddleware` — logs request timing, memory, query count

**Web group additions:**
- `HandleInertiaRequests` — shares `auth.user.roles` to all Inertia pages
- `PreventBackHistory` — cache-busting headers

**API group additions:**
- `SyncMiddleware` — intercepts API writes for offline-first queueing

**Middleware aliases:**
- `role` → `Spatie\Permission\Middleware\RoleMiddleware`
- `permission` → `Spatie\Permission\Middleware\PermissionMiddleware`
- `role_or_permission` → `Spatie\Permission\Middleware\RoleOrPermissionMiddleware`

### 7.3 Route Inventory

#### Web Routes (`routes/web.php`) — Session-Authenticated

| # | Method | URI | Controller | Auth |
|---|--------|-----|-----------|------|
| 1 | GET | `/` | Redirect (super-admin → admin, others → dashboard) | None |
| 2 | GET | `/login` | `AuthController@showLogin` | None |
| 3 | POST | `/login` | `AuthController@login` | None |
| 4 | POST | `/logout` | `AuthController@logout` | None |
| 5 | GET | `/debug-state` | Debug state dump | None |
| 6 | GET | `/dashboard` | `DashboardController@index` | auth |
| 7 | GET | `/patients/shared` | `PatientController@shared` | auth |
| 8–15 | CRUD | `/patients*` | `PatientController` | auth |
| 16–25 | POST | `/notes` | `PatientController@storeNote` | auth |
| 26 | GET | `/workspace` | `WorkspaceController@index` | auth |
| 27–32 | CRUD | `/settings*` | `SettingsController` | auth |
| 33 | POST | `/api/v1/log/client-error` | Log client errors | auth |
| 34–45 | CRUD | `/api/v1/chunk/*` | `ChunkUploadController` | auth |
| 46–52 | CRUD | `/api/v1/patients/*/files` | `UploadController` | auth |
| 53–58 | GET/POST | `/api/v1/uploads/*` | `UploadController` | auth |
| 59–67 | CRUD | `/api/v1/files/*` | `FileAccessController` | auth |
| 68 | GET | `/api/v1/search` | `GlobalSearchController` | auth |
| 69–77 | CRUD | `/api/v1/categories*` | `CategoryController` | auth |
| 78–83 | CRUD | `/api/v1/workspace/*` | `WorkspaceController` | auth |
| 84–90 | CRUD | `/api/v1/patients/*/visits` | `VisitController` | auth |
| 91–98 | CRUD | `/api/v1/patients/*/notes` | `NoteController` | auth |
| 99–105 | CRUD | `/api/v1/patients/*/shares` | `PatientShareController` | auth |
| 106 | GET | `/api/v1/admin/doctors/{doctor}` | `DoctorController@apiShow` | auth |
| 107 | POST | `/api/native/sync` | `NativeSyncController@sync` | auth |

**Admin prefix group** (`/admin`, `role:super-admin`):
| # | Method | URI | Controller |
|---|--------|-----|-----------|
| 108 | GET | `/admin` | `AdminController@index` |
| 109–117 | CRUD + action | `/admin/doctors*` | `DoctorController` |

#### API Routes (`routes/api.php`) — Sanctum Authenticated

| # | Method | URI | Controller | Auth |
|---|--------|-----|-----------|------|
| 1 | GET | `/files/{uuid}/stream` | `FileAccessController@streamDirect` | signed (URL expiry) |
| 2 | POST | `/v1/login` | `Api\AuthController@login` | None (public) |
| 3 | POST | `/v1/logout` | `Api\AuthController@logout` | auth:sanctum |
| 4 | GET | `/v1/me` | `Api\AuthController@me` | auth:sanctum |
| 5 | GET | `/v1/patients/{uuid}/categories/{slug}/files` | `CategoryFileController` | auth:sanctum |
| 6–41 | CRUD | `/v1/mobile/*` | `Api\Mobile\*` controllers | auth:sanctum |
| 42–45 | POST/GET | `/v1/native/sync/*` | `NativeSyncController` | auth |
| 46–53 | CRUD | `/v1/uploads/*` | `Api\UploadsController` | auth:sanctum |

**Mobile API prefix** (`/v1/mobile`, all sanitized via `auth:sanctum`):
- Dashboard stats
- Patient CRUD (with extended medical fields)
- Visit CRUD (with extended fields: session_details, cost)
- Note CRUD (author-only edit/delete)
- File CRUD + streaming + thumbnails
- Doctor search + listing
- Patient sharing
- Global search
- Profile/password update

---

## 8. Backend — Controllers, Services & Repositories

### 8.1 AuthController (Web)

Implements **hybrid login** — the most authentication-heavy controller:
1. **Step 1:** Tries local SQLite `Auth::attempt()` (for offline-first mobile)
2. **Step 2:** If online and Step 1 fails, calls `ApiService::loginToRemote()` — authenticates against the production API, mirrors the remote user into local SQLite, forces local user ID to match remote ID (ensuring `DoctorIsolationScope` works correctly)
3. **Step 3:** If both fail, returns credentials error

On logout: clears API token from `ApiService`, calls `Auth::logout()`, invalidates session.

### 8.2 WorkspaceController (Web — Most Complex)

The central hub for doctor operations. Key behavior:

**Online mode:**
- Tries `ApiPatientRepository` for all data
- Syncs results into local SQLite after each fetch
- Category list merged from config defaults + user preferences

**Offline mode:**
- Falls back to `EloquentPatientRepository` (local SQLite)
- Returns `auth_error: true` if no local data exists

**Key methods:**
- `patientData(uuid)` — Full patient payload with counts (files, notes, visits, last visit, next appointment), policy checks for `can_edit`, `can_delete`, `can_share`, share metadata for shared-access view
- `storePatient/updatePatient` — Full medical field validation
- `deletePatient/forceDeletePatient/restorePatient` — Soft-delete and permanent operations
- `exportPatient(uuid)` — Streaming JSON download of all patient data
- `printPatient(uuid)` — Renders `PatientPrint.vue` via Inertia
- `downloadFiles(uuid)` — Dispatches `ExportPatientFilesJob`, returns job ID for polling
- `checkDownloadStatus(jobId)` — Polls cache for job progress

### 8.3 Repository Pattern — Three Tiers

The project implements a **three-tier repository pattern** bound via `RepositoryServiceProvider`:

| Mode | When Used | Binding |
|------|-----------|---------|
| **Api** (remote-only) | Calling production API | `ApiPatientRepository`, etc. |
| **Eloquent** (local-only) | Offline fallback | `EloquentPatientRepository`, etc. |
| **Hybrid** (offline-first) | **Production default for mobile** | `HybridPatientRepository`, etc. |

**Hybrid repositories** follow this pattern for every operation:
1. **Read:** Try API → get response → sync locally → return data. On network failure → return local data.
2. **Write (create):** Save locally first (with server-assigned UUID pre-set) → try API → sync response back → on 422, force-delete local record and re-throw → on other failure, enqueue in `SyncQueueService` for retry.
3. **Write (update/delete):** Execute locally → try API → enqueue on failure.

The binding choice is environment-dependent:
- `NATIVEPHP_RUNNING=true` → **Hybrid** repositories (mobile/offline)
- Default → **Eloquent** repositories (web, direct MySQL)

**Important inconsistency:** `HybridPatientVisitRepository` and `HybridUserRepository` use `App\Models\PendingOperation` for offline queuing, while `HybridPatientRepository`, `HybridPatientFileRepository`, and `HybridPatientNoteRepository` use `SyncQueueService` (the newer `sync_queue` table). These are two parallel offline-queue systems.

### 8.4 SyncMiddleware — Offline Write Interception

Registered as API group middleware. On every POST/PUT/PATCH/DELETE request:
- **ONLINE:** Passes through to controller, then enqueues operation in `SyncQueueService`.
- **OFFLINE:** Skips controller entirely, returns `{success: true, queued_offline: true}` so the mobile app does not retry. Operation is queued for later sync.

Entity resolution: walks URL segments from the end looking for known keywords (`patients` → `Patient`, `visits` → `PatientVisit`, etc.). Operation type derived from HTTP method (POST=create, PUT/PATCH=update, DELETE=delete).

### 8.5 NativeSyncController — Background Sync Orchestrator

Three endpoints:
- `POST /api/native/sync` — Full sync: (1) pushes `SyncQueueItem` operations to remote API, (2) pushes legacy `PendingOperation` records, (3) pulls all remote data via `FullSyncService::syncAll()`. Partial failures are logged but do not abort.
- `GET /api/native/sync/status` — Returns sync status (pending count, last sync, in-progress flag).
- `POST /api/native/sync/force` — Forces a full sync regardless of connectivity.

Push logic dispatches to entity-specific handlers based on URL entity type:
- Patients → pushPatientToRemote (JSON body for create/update; DELETE for delete)
- Visits → pushVisitToRemote
- Notes → pushNoteToRemote
- Files → pushFileToRemote (multipart binary upload via `Http::attach()`, reads binary from local disk)

---

## 9. Frontend — Vue Pages, Components & Composables

### 9.1 Routing Mechanism

The application uses **Inertia.js v3** exclusively. There is **no Vue Router**.

- Laravel handles all server-side routing
- Inertia resolves Vue page components dynamically via `resolvePageComponent('./Pages/${name}.vue', import.meta.glob('./Pages/**/*.vue'))`
- All page components live in `resources/js/Pages/`

**Navigation guards** (client-side):
- `router.beforeEach` and `router.beforeResolve` check `auth.user` from Inertia page props
- If not authenticated → redirect to `/login`
- `isAuthenticated()` and `shouldSkipRoute()` methods control access

### 9.2 Three Persistent Vue Roots

The `app.js` entry point creates **three persistent Vue applications** that survive page navigations:

```javascript
┌─ Main Inertia App (routed pages) ─┐
├─ UploadManager (separate mount) ──┤  ← survives navigation
├─ GlobalDialog (separate mount) ───┤  ← survives navigation
└─ ToastContainer (separate mount) ┘  ← survives navigation
```

This architecture allows the upload manager, dialog system, and toast notifications to persist state across Inertia page visits without being destroyed/recreated.

### 9.3 Pages

| Page | Purpose | Key Data |
|------|---------|----------|
| `Auth/Login.vue` | Email/password login | `window.axios.post('/login')`, redirect on success |
| `Dashboard/Index.vue` | Stats overview | total_patients, recent_files, active_shares, active_doctors |
| `Admin/Dashboard.vue` | Admin stats + management | Doctor CRUD, suspend/activate |
| `Admin/Doctors/Index.vue` | Doctor list | Search + paginate, suspend toggle |
| `Admin/Doctors/Create.vue` | Create doctor | Name, email, phone, specialization, address, password |
| `Admin/Doctors/Edit.vue` | Edit doctor | Pre-populated form |
| `Admin/Doctors/Show.vue` | Doctor detail | Patients tab + Files tab with pagination |
| `DoctorWorkspace.vue` | Main doctor workspace | The richest page — patient CRUD, files, notes, visits, sharing, timeline |
| `PatientPrint.vue` | Print-optimized export | Patient data + files + notes + visits, print/download buttons |
| `Settings/Partials/*` | Settings modals | Profile, password, preferences, categories, download |

### 9.4 Workspace Components (16 files)

`CategoryBlock.vue` is the **most complex component** (~1200 lines), rendering an expandable category section with:
- Server-side pagination (6 per page)
- Multi-filter: search, date range (preset + custom), time range (morning/afternoon/evening + custom)
- Sort: newest, oldest, name_asc, name_desc, largest, smallest, recently_updated
- Upload progress display
- Native camera/gallery/file picker via `useNativeBridge`
- Category CRUD (rename, change color/icon/order/visibility, delete)
- Add note/visit modals
- `FileActions` bottom sheet on mobile

### 9.5 Composables (9 files)

All composables use **module-level singleton patterns** — calling `useWorkspace()` anywhere returns the same reactive state, enabling cross-component communication without prop drilling.

| Composable | Purpose | Key State |
|-----------|---------|-----------|
| `useWorkspace.js` | Central workspace state | 30+ refs: patients list, selected patient, workspace data, loading states, permissions, UI modals |
| `useUploads.js` | Chunked upload engine | Upload jobs, progress, pause/resume, 4-parallel pool, semaphore for global concurrency, exponential backoff retry |
| `useUploadDiagnostics.js` | Upload profiler (debug) | Device info, network samples, memory snapshots, chunk metrics |
| `useDialog.js` | Global confirm/alert | Promise-based, singleton dialog state |
| `useToast.js` | Toast notifications | Module-level reactive array, auto-dismiss |
| `useTheme.js` | Light/dark/system theme | localStorage persistence, system preference listener, debounced save |
| `useLocale.js` | EN/AR locale | RTL/LTR switching, debounced save, dir attribute |
| `useNativeBridge.js` | Android bridge | Permission mapping, native file picker, camera, settings |
| `usePullToRefresh.js` | Touch PTR | Configurable threshold, haptic feedback, RAF snap-back |

### 9.6 Key Frontend-Backend API Mapping

| Frontend Component/Composable | Backend Route | Method |
|------------------------------|--------------|--------|
| `useWorkspace.selectPatient()` | `/api/v1/workspace/{uuid}` | GET |
| `useWorkspace.addPatient()` | `/api/v1/workspace/patients` | POST |
| `useWorkspace.updatePatient()` | `/api/v1/workspace/patients/{uuid}` | PUT |
| `useWorkspace.archivePatient()` | `/api/v1/workspace/patients/{uuid}` | DELETE |
| `useWorkspace.restorePatient()` | `/api/v1/workspace/patients/{uuid}/restore` | POST |
| `useWorkspace.forceDeletePatient()` | `/api/v1/workspace/patients/{uuid}/force` | DELETE |
| `useWorkspace.refreshPatientList()` | `/api/v1/workspace/patients-list` | GET |
| `useUploads` (chunked) | `/api/v1/chunk/init`, `/chunk`, `/complete`, `/{id}/cancel`, `/{id}/status` | POST/GET |
| `AddRecordModal` (file tab) | `/api/v1/patients/{uuid}/files` | POST |
| `SharePatientModal` | `/api/v1/patients/{uuid}/shares` | POST |
| `FileActions.vue` | `/api/v1/files/{uuid}` | PUT/DELETE |
| `CategoryBlock.vue` | `/api/v1/patients/{uuid}/categories/{slug}/files` | GET |
| `CategoryForm.vue` | `/api/v1/categories` | GET/PUT |
| `UnifiedMediaViewer.vue` | `/api/v1/files/{uuid}/signed-url` | GET |
| `GlobalSearch.vue` | `/api/v1/search?q=` | GET |
| `AppLayout` (sync trigger) | `/api/native/sync` | POST |
| `SettingsModal` | `/api/v1/native/sync/status` | GET |

---

## 10. Offline-First Sync Architecture

This is the most architecturally significant subsystem. The system is designed to work **fully offline** on mobile, then sync bi-directionally when connectivity is restored.

### 10.1 Two-Tier Queue System

The application has **two parallel offline-queue mechanisms**:

```
┌─────────────────────────────────────────────┐
│         TIER 1: SyncQueue (Modern)          │
│                                             │
│  sync_queue table (SyncQueueItem model)    │
│  - Used by: Patients, Notes, Files         │
│  - Features: retry_count, priority,        │
│              last_error, available_at       │
│  - Processed by: FullSyncService            │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│         TIER 2: PendingOperations (Legacy)  │
│                                             │
│  pending_operations table                   │
│  - Used by: Visits (HybridPatientVisitRepo) │
│             Users (HybridUserRepo)           │
│  - Features: simple entity_type + action    │
│  - Processed by: SyncPendingOperationsJob   │
└─────────────────────────────────────────────┘
```

### 10.2 client_updated_at — Sync Watermark

Every clinical entity (Patients, PatientVisits, PatientNotes, PatientFiles, Users, FileCategories) has a `client_updated_at` timestamp field. This is **not** a standard Laravel `updated_at` — it is specifically for the sync watermark:
- When the server returns data, `client_updated_at` records the server's current timestamp.
- On subsequent sync, only records modified **after** the last `client_updated_at` are fetched.
- This enables delta-sync rather than full table fetches.

### 10.3 Hybrid Repository Flow

```
User Action (CREATE/UPDATE/DELETE)
       │
       ▼
┌──────────────────┐
│  SyncMiddleware  │ ─── Offline? ──► Queue to sync_queue + return queued=true
│  (intercepts)    │
└────────┬─────────┘
         │ Online
         ▼
┌──────────────────┐
│  HybridRepo      │
│  .create()       │ ──► Save locally first (with server UUID pre-assigned)
│  .update()       │ ──► Update locally first
│  .delete()       │ ──► Delete locally first
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  Try Remote API  │
└────────┬─────────┘
         │
    ┌────┴────┐
    │         │
  Success   Failure
    │         │
    ▼         ▼
  Sync     Queue to
  back     sync_queue
  locally  for retry
```

### 10.4 NativePHP Startup Sync

In `AppServiceProvider::boot()`:
- If `NATIVEPHP_RUNNING=true`, runs migrations if version differs from stored version file.
- Schedules `FullSyncService::syncAll()` to run once at PHP process boot (each app open spawns a new process in NativePHP).

---

## 11. Chunked File Upload Pipeline

### 11.1 Upload Architecture

Two parallel chunked upload implementations exist:

| Controller | Path | Methods |
|-----------|------|---------|
| `ChunkUploadController` | `/api/v1/chunk/*` | init, chunk, complete, cancel, status |
| `UploadsController` | `/v1/uploads/*` | start, chunk, status, resume, finish, destroy |

`UploadsController` adds a `resume()` method that `ChunkUploadController` lacks. Both use the same service layer (`UploadSessionService`, `ChunkUploadService`, `ChunkMergeService`, `UploadValidationService`, `UploadChecksumService`).

### 11.2 Upload Session Lifecycle

```
1. CLIENT → init/start
   - Sends: file_name, file_size, mime_type, patient_id, chunk_size
   - Creates: UploadSession record (status: pending)
   - Returns: upload_id, chunk_size, total_chunks, expires_at

2. CLIENT → chunk (× N times)
   - Sends: upload_id, chunk_index, chunk binary, optional SHA-256
   - Verifies: SHA-256 if provided
   - Writes: Either direct-write (byte-offset seek) or legacy (temp numbered files)
   - Records: INSERT OR IGNORE into upload_chunk_receipts (idempotent!)
   - Returns: progress stats

3. CLIENT → complete/finish
   - Creates: PatientFile record from merged data
   - Cleans: Temp files/directories
   - Dispatches: GenerateThumbnailJob for videos
   - Returns: uuid, upload_status, url, thumbnail_url, type

4. ON FAILURE → cancel/destroy
   - Marks session: failed/cancelled
   - Cleans: Chunk files/directories
```

### 11.3 Direct-Write Optimization

The upload pipeline has **two storage paths**:

| Path | When Used | How |
|------|-----------|-----|
| **Direct-write** (fast) | When `final_path` is pre-set in session | Chunks seek to byte offset and write directly to the final file position. No temp files, no merge step needed. |
| **Legacy merge** | Fallback | Chunks stored as numbered temp files, then stream-merged into final file with 4MB buffer + SHA-256 verification |

The `final_path` is set via encrypted path with `{uuid}` placeholder replaced by the session UUID. Direct-write was introduced to solve deadlock issues from the JSON `received_chunk_indexes` column being a write contention point under concurrent uploads. The `upload_chunk_receipts` table (with `INSERT OR IGNORE`) is now the single source of truth for chunk tracking.

### 11.4 Frontend Upload Engine (`useUploads.js`)

The Vue composable implements a sophisticated upload client:
- **Chunked uploads:** Default 5MB chunks, configurable via `configureUploads()`
- **Parallel pool:** 4 concurrent chunk requests (configurable)
- **Global semaphore:** Limits total in-flight requests across all uploads to prevent browser connection pool starvation during Inertia navigation
- **Resume support:** Persists sessions to `localStorage` under key `upload_sessions`, checks `/api/v1/chunk/{id}/status` on resume
- **Cancel/Pause/Resume:** Uses `AbortController` per chunk
- **Retry logic:** Exponential backoff (500ms → 1s → 2s, capped at 4s), max 3 retries
- **Speed tracking:** Sliding 3-second window for real-time speed

---

## 12. Media Processing & Streaming

### 12.1 File Types

The system categorizes files into 5 types via MIME type detection:
- `image` — viewed inline, thumbnail generated by GD (if not stored)
- `video` — optimized for streaming (moov atom moved to front), thumbnail extracted via ffmpeg
- `audio` — streamed with Content-Type headers
- `pdf` — served for direct download/view
- `document/text` — text files served for inline viewing with highlight.js

### 12.2 File Access Controller (`FileAccessController`)

The most security-sensitive controller. Three access modes:

| Endpoint | Auth Requirement |
|----------|-----------------|
| `/api/v1/files/{uuid}` (stream) | Session auth (web) OR validated Bearer token (API) |
| `/api/v1/files/{uuid}` via signed URL | `$request->hasValidSignature()` — URL expiry (no auth required) |
| `/v1/files/{uuid}/stream` | Signed URL only (no user auth) |

**Stream response features:**
- Full HTTP Range support (206 Partial Content)
- `Accept-Ranges: bytes` header
- `Content-Range` header for partial responses
- ETag and Last-Modified for client-side caching
- `Cache-Control: public, max-age=31536000` (1-year cache for immutable files)

### 12.3 Thumbnail Generation

Two paths:
1. **Stored thumbnail:** If `thumbnail_path` exists, serve directly.
2. **On-demand generation:**
   - **Video:** FFmpeg extracts JPEG frame at 1 second, scaled to 300px height, quality 5 (120s timeout per job). Stored after generation.
   - **Image:** GD resizes to max dimension. Not stored — generated on each request.
   - **Other types:** Returns 204 No Content.

---

## 13. User Flows

### 13.1 Doctor Login Flow

```
1. User enters email/password on /login
2. AuthController::login()
   a. Try local SQLite Auth::attempt() (for offline-capable sessions)
   b. If online and local fails:
      → ApiService::loginToRemote(remote_production_url)
      → On success: mirrorRemoteUser() — creates/updates local User,
        forces local ID = remote ID (for DoctorIsolationScope)
   c. If both fail: return credentials error
3. Redirect: doctors → /workspace, super-admin → /admin/doctors
4. On logout: clear API token, Auth::logout(), regenerate session token
```

### 13.2 Doctor Workspace Flow

```
1. GET /workspace → WorkspaceController@index()
   a. ONLINE: Try ApiPatientRepository → sync results to local SQLite
   b. OFFLINE: Use EloquentPatientRepository (local SQLite)
   c. Merge: default categories from config/categories.php + user custom categories
      from user.preferences.custom_categories
   d. Render: DoctorWorkspace.vue via Inertia

2. Doctor selects a patient from sidebar
   → useWorkspace.selectPatient(uuid)
   → GET /api/v1/workspace/{uuid}
   → Full data payload: patient info, visits, files (paginated), notes (paginated)
   → Computes: can_edit, can_delete, can_share (inferred from ownership + policy)
   → Updates browser: #patient hash in URL

3. Doctor adds a file
   → useUploads composable handles chunked upload
   → POST /api/v1/chunk/init → POST /api/v1/chunk/chunk (× N) → POST /api/v1/chunk/complete
   → PatientFile created, OptimizeVideoForStreaming dispatched if video
   → Optimistic local update in workspaceData.files

4. Doctor adds a note
   → POST /api/v1/patients/{uuid}/notes
   → Optimistic update in workspaceData.notes
```

### 13.3 Patient Sharing Flow

```
1. Primary doctor clicks "Share" on patient
2. SharePatientModal opens (3-step flow):
   Step 1: Search doctors by name/email/specialization
   Step 2: Select access level (read / read_write)
   Step 3: Optional expiry date + confirm
3. POST /api/v1/patients/{uuid}/shares
   → Creates/updates PatientShare (updateOrCreate = idempotent)
   → access_level validation: must be 'read' or 'read_write'
4. Shared doctor logs in:
   → DoctorIsolationScope now includes: "this patient is in patient_shares
      with active (non-expired) share for this doctor"
   → Shared doctor sees patient in their sidebar + can view/edit per access level
```

### 13.4 Admin Doctor Management Flow

```
1. Super-admin logs in → redirected to /admin
2. Admin sidebar shows: Dashboard, Doctors management
3. Doctor list (/admin/doctors):
   - Search by name/email/code
   - Filter by status (active/suspended)
   - Suspend/activate toggle (POST /admin/doctors/{id}/suspend)
   - Create new doctor (auto-generates DR-XXXXX code)
4. Doctor detail (/admin/doctors/{id}):
   - Shows: name, email, specialization, phone, address, status
   - Patient count (bypasses DoctorIsolationScope)
   - Storage usage (sum of all patient_files.size)
   - Patients tab: searchable, paginated list of that doctor's patients
   - Files tab: searchable, type-filtered, paginated file list
5. Edit/delete: standard CRUD with validation
```

### 13.5 Offline-First Sync Flow (Mobile)

```
1. Mobile app is offline
2. User creates a new patient
   → SyncMiddleware intercepts, sees offline
   → Skips controller, returns {queued_offline: true}
   → Changes saved to local SQLite only

3. User comes online
4. App triggers: POST /api/native/sync
   → NativeSyncController::sync()
   → Step A: Push SyncQueueItem operations:
      - Iterate pending items ordered by priority
      - POST patient to /v1/mobile/patients → get server response
      - Update local patient with server UUID, timestamps
      - Mark item synced or failed
   → Step B: Push legacy PendingOperation records:
      - Same push logic for visits, notes, files
   → Step C: Pull all remote data:
      - Fetch all patients, files, notes, visits from remote
      - Upsert into local SQLite (match by UUID, update by client_updated_at)
      - Download missing file binaries and thumbnails
      - Refresh doctors list

5. Partial sync failures are logged but don't abort the entire sync
```

---

## 14. Key Business Rules

### 14.1 Data Ownership & Isolation
- Every patient belongs to exactly one **primary doctor** (`primary_doctor_id`).
- A patient's primary doctor has full access (view + edit + share + delete).
- Other doctors can only access a patient if explicitly shared via `PatientShare` with an **active (non-expired) record**.
- Shared doctors' access level determines what they can do:
  - `read`: view only
  - `read_write`: view + edit (cannot share or delete)
- **Super-admin and admin roles bypass all data isolation** — they see all patients.

### 14.2 Patient Deletion
- Patients support **soft delete** (archiving) — visible in "archived" filter.
- Primary doctor can **restore** soft-deleted patients.
- Primary doctor can **permanently force-delete** (irreversible, bypasses soft-delete).
- Shared doctors **cannot delete** patients they don't own.

### 14.3 Note Editing
- Any doctor can **create** notes on a patient they can access.
- **Only the note's author** can edit or delete their own notes.
- Non-author doctors who want to edit/delete must have `update` policy on the patient (via `Gate::authorize('update', $note->patient)`).

### 14.4 Code Generation
- New patients get an auto-generated **6-digit random code** on creation (in `PatientController::store()`).
- New doctors (via admin) get a **DR-XXXXX** code on creation (in `DoctorController::store()`).

### 14.5 File Categorization
- Patients' medical files are organized into **6 fixed categories** defined in `config/categories.php`.
- Categories support: `name` (bilingual), `icon`, `color`, `order`, `is_visible` flag.
- Super-admin can modify categories for **all users**; regular doctors can only modify their own.
- Custom user categories are stored in the `preferences.custom_categories` JSON field on the User model.
- Categories are merged on every load: defaults first, then custom overrides (respecting `is_visible`).

### 14.6 Upload Limits
- Maximum file size: **500MB** per file (512000 KB in validation)
- Maximum chunk size: **50MB** per chunk (minimum 1MB)
- Chunked upload expiry: **6 hours**
- Files are validated for safe extensions; unknown extensions fall back to `.bin`

### 14.7 Activity Logging
- Key actions are logged to `activity_logs`: login, logout, patient created/updated/deleted, file uploaded/deleted, patient shared, profile/password updated.
- Each log entry captures: `user_id`, `action`, `entity_type`, `entity_uuid`, `payload` (JSON), `ip_address`, `user_agent`.
- `ActivityLogger` is used in API controllers (especially Mobile API) and service layers (SharingService).

---

## 15. Dual API Layer

The project exposes **two distinct API surfaces**:

### 15.1 Session API (`routes/web.php` → `/api/v1/*`)

| Property | Value |
|----------|-------|
| Auth | Laravel session cookies |
| Clients | Web browser (Vue + Inertia) |
| Token | Session ID cookie |
| Purpose | Internal SPA communication |

Endpoints: chunk/init, chunk/chunk, chunk/complete, uploads, files, search, categories, workspace, visits, notes, shares.

### 15.2 Sanctum API (`routes/api.php` → `/v1/*`)

| Property | Value |
|----------|-------|
| Auth | Bearer token (Laravel Sanctum) |
| Clients | Mobile app (NativePHP) + external API consumers |
| Token | `Authorization: Bearer <token>` |
| Purpose | Mobile/client applications |

Endpoints: `/v1/login`, `/v1/me`, `/v1/mobile/*`, `/v1/uploads/*`, `/v1/native/sync/*`

### 15.3 Signed URL API (`routes/api.php` → `/files/{uuid}/stream`)

| Property | Value |
|----------|-------|
| Auth | Signed URL (time-limited, no user auth) |
| Clients | Any system with the signed URL |
| Token | URL signature (`_signature`, `expires` params) |
| Purpose | Temporary file access without authentication |

### 15.4 Overlapping Endpoints

Some endpoints exist in **both** API layers with different auth:
- `files/{uuid}/stream` — session auth (web) vs signed URL (API)
- `patients/{uuid}/categories/{slug}/files` — session auth vs Sanctum

The `Api/Mobile/` endpoints are more feature-complete (extended visit fields, activity logging, proper JSON Resources).

---

## 16. NativePHP Mobile Integration

### 16.1 NativePHP Configuration

| Setting | Value |
|---------|-------|
| Runtime mode | `classic` (full PHP init per request, ~200-300ms) |
| Android compile SDK | 35 |
| Android min SDK | 26 |
| Android target SDK | 35 |
| R8/ProGuard | Minify ON, Obfuscate OFF |
| iOS orientation | Portrait only |
| Android orientation | Portrait + landscape left/right |

### 16.2 Native Service Provider

`NativeServiceProvider.php` is registered only when `NATIVEPHP_APP_ID` is set. It **whitelists exactly 5 NativePHP plugins** for security:
1. `CameraServiceProvider` — Camera access
2. `FileServiceProvider` — File system access
3. `NetworkServiceProvider` — Network status detection
4. `DialogServiceProvider` — Native dialogs
5. `ShareServiceProvider` — Native sharing (Android)

No other plugins are included in the compiled binary.

### 16.3 UseNativeBridge.js

The composable maps JS permission aliases to Android permissions:
- `camera` → `android.permission.CAMERA`
- `files` → `android.permission.READ_EXTERNAL_STORAGE` + `WRITE_EXTERNAL_STORAGE`
- `audio` → `android.permission.RECORD_AUDIO`
- `notifications` → `android.permission.POST_NOTIFICATIONS`
- `location` → `android.permission.ACCESS_FINE_LOCATION`
- `storage` → `android.permission.READ_EXTERNAL_STORAGE`

### 16.4 Mobile Cache

`storage/app/mobile-cache/` stores downloaded file binaries and a JSON index for offline file access. `FileCacheService` manages this with a simple key→path index.

### 16.5 SQLite Caching

The mobile app runs a local SQLite database (same schema as production MySQL) that serves as:
- **Read cache** for offline patient/visit/note/file data
- **Write queue** for offline mutations (enqueued in `sync_queue`)
- **Conflict resolution** via `client_updated_at` timestamps

---

## 17. Internationalization (i18n)

### 17.1 Supported Languages

| Language | Code | Direction | Lines |
|----------|------|-----------|-------|
| English | `en` | LTR | 601 |
| Arabic | `ar` | RTL | 601 |

### 17.2 Translation Coverage

The `en.json` and `ar.json` locale files contain translations for all major UI namespaces:
- `nav` — navigation labels
- `dashboard` — admin and user dashboard stats
- `auth` — login form labels
- `patients` — patient names, placeholders, sharing, medical fields
- `settings` — profile, password, preferences, categories, download
- `doctors` — admin doctor management
- `shared` — shared patient views
- `show` / `doctors_show` — detail views
- `category` — filtering, sorting, date ranges
- `workspace` — workspace actions, timeline, visits
- `files` / `upload_manager` — upload status and UI
- `file_preview` / `file_actions` — file viewer actions
- `pull_to_refresh` / `video_player` — component-specific
- `patient_summary` / `patient_print` — print and summary sections

### 17.3 RTL Support

- Tailwind CSS v4 RTL utilities: `space-x-reverse`, `rtl:` variants
- `document.documentElement.dir` set reactively by `useLocale.js`
- CSS custom property `--direction` (0 for LTR, 1 for RTL)
- Arabic layout requires mirrored spacing, floating, and direction

### 17.4 Locale Preference Flow

1. On load: `localStorage.getItem('locale')` → fallback to server prop`auth.user.preferences.locale`
2. User changes locale → debounced PUT to `/settings/preferences` (300ms)
3. Preference saved to server → reflected in user's JSON preferences field

---

## 18. Observability & Logging

### 18.1 Request Profiling (Dev Only)

`NativePHPProfilerMiddleware` (only when `APP_DEBUG=true`):
- Logs `REQUEST_START` with URL, method, memory
- Hooks into `DB::listen` to count queries and accumulate execution time
- Logs `REQUEST_FINISHED` with: route name, controller action, total execution time (ms), memory before/after/peak, SQL query count/time, response size
- Catches and logs exceptions before re-throwing

### 18.2 Upload Diagnostics (`useUploadDiagnostics.js`)

Only active when `localStorage.upload_debug` is set or `?debug=1` URL param. Tracks:
- Device info, network samples, memory snapshots
- Chunk-level metrics: blob slice time, queue wait, upload duration, retries, speed
- Pool monitoring, sequential upload detection
- Generates JSON/CSV exportable reports

### 18.3 Error Reporting

- Client errors → `/api/v1/log/client-error` (POST, caught by global `captureError` handler in `app.js`)
- Server errors → `Log::channel('single')` (production)
- Sync errors → `last_error` field on `SyncQueueItem` + `error_message` on `sync_jobs`

### 18.4 Database Query Logging

Production: standard Laravel query logging disabled.  
Development: `DB::listen` callback in profiling middleware.

---

## 19. Notable Architectural Inconsistencies & Technical Debt

### 19.1 Dual Offline Queue Systems

| Queue | Table | Used By | Service |
|-------|-------|---------|---------|
| **Modern** | `sync_queue` (SyncQueueItem) | Patients, Notes, Files | `SyncQueueService` |
| **Legacy** | `pending_operations` (PendingOperation) | Visits, Users | `SyncPendingOperationsJob` |

Both systems log the same intent (offline mutation replay) but use different tables, different models, different job classes, and different processing code. This creates:
- Duplicate data for the same conceptual operation
- Two separate retry mechanisms
- Potential race conditions (an item queued in both systems)
- Inconsistent monitoring (only `sync_queue` has `retry_count`, `last_error`, `priority`)

### 19.2 Parallel Chunked Upload Implementations

`ChunkUploadController` and `UploadsController` implement nearly identical chunked upload pipelines:
- Same services, same validation rules, same models
- Method naming differs (`complete` vs `finish`, `cancel` vs `destroy`)
- `UploadsController` adds a `resume()` method that `ChunkUploadController` lacks

This duplication suggests a migration in progress or two teams working on the same feature.

### 19.3 Free-Text Category Column

`patient_files.category` is a **free-text column** (not a foreign key to `file_categories`). This means:
- No referential integrity at the database level
- Renaming a category doesn't update existing files
- Typos in category names create "ghost" categories
- The `CategoryController` works with user preferences (JSON), not the DB column directly

### 19.4 Disabled `ApiProxy`

`app/Services/ApiProxy.php` always returns `false` (`isEnabled()` hardcoded). `FullSyncService` references `ApiProxy::get()` for binary file/thumbnail downloads, but those code paths are **dead code** — they will never execute.

### 19.5 No Form Request Classes

All validation is done **inline in controllers**. There are zero Form Request classes (`app/Http/Requests/` directory does not exist). This means:
- Validation rules are scattered across 20+ controllers
- No reusable validation logic
- Cannot share validation between web and API endpoints
- Cannot use Form Request `authorize()` for automatic policy checks

### 19.6 Password Cast with Spatie Permissions

The `User` model uses `'password' => 'hashed'` cast, which auto-hashes on set. However, Spatie's `HasRoles` trait also uses `$user->password` in some internal operations. This works because the cast only activates on `setAttribute`, not on `getAttribute`.

### 19.7 `record_uuid` Became Nullable

Migration `2026_07_18_150000_make_sync_queue_record_uuid_nullable` made `sync_queue.record_uuid` nullable. This was needed because client-side pre-synced entries don't have server-assigned UUIDs yet. However, this breaks the assumption that every sync item maps to a specific server record.

---

## 20. Glossary

| Term | Definition |
|------|-----------|
| **DoctorIsolationScope** | Global Eloquent scope that limits doctor-role users to see only their own patients and shared patients |
| **DoctorWorkspace** | The main doctor UI panel (patient list + selected patient detail with files/notes/visits) |
| **HybridRepository** | Repository that tries API first, falls back to local SQLite, queues on failure |
| **SyncQueueItem** | Database record in `sync_queue` table representing a pending offline operation |
| **PendingOperation** | Legacy offline queue entry in `pending_operations` table |
| **UploadSession** | Database record tracking a chunked file upload's state machine |
| **Direct-write** | Optimization where upload chunks seek to byte offsets in the final file (no merge needed) |
| **Legacy merge** | Traditional chunked upload where pieces are stored separately then merged |
| **client_updated_at** | Sync watermark timestamp — records last-known server state per record |
| **Chunk receipt** | Idempotent `INSERT OR IGNORE` record confirming a chunk was received |
| **Signed URL** | Time-limited URL for file access without authentication |
| **NativePHP** | Desktop/mobile runtime that wraps a Laravel app as a native application |
| **Inertia.js** | Server-driven SPA framework — Laravel returns Vue components instead of HTML |
| **Share** | `PatientShare` record granting a doctor access to another doctor's patient |
| **access_level** | Permission granularity on a share: `read`, `read_write`, or `full` |
| **ActivityLog** | Audit trail entry recording who did what to which entity |
| **FileCategory** | Named category for organizing patient files (Medical History, Radiology, etc.) |
| **CategoryBlock** | The most complex UI component, rendering an expandable file category with pagination, filtering, sorting |
| **Profile** | User profile page — name, email, phone, avatar, password, preferences |
| **Settings** | Modal/drawer UI for profile management, preferences, category customization, app download |
| **Hybrid** | The binding mode of `RepositoryServiceProvider` — uses `Hybrid*Repository` for mobile |
| **Eloquent** | The binding mode — uses `Eloquent*Repository` for direct database access |
| **Api** | The binding mode — uses `Api*Repository` for remote API calls only |

---

*End of architecture documentation. This document describes the project as it exists in the codebase. No behavior has been inferred, assumed, or invented.*
