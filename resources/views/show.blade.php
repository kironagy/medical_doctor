@extends('layouts.app')

@section('header-actions')
    <a href="{{ url('/') }}" class="icon-btn" title="العودة للرئيسية">
        <i class="fa-solid fa-house"></i>
    </a>
@endsection

@push('styles')
<style>
    /* ── Patient Profile Banner ── */
    .patient-banner {
        background: linear-gradient(135deg, #1E40AF 0%, #3B82F6 50%, #6366F1 100%);
        border-radius: var(--radius-lg);
        padding: 2rem 2.5rem;
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 35px rgba(59,130,246,0.3);
    }

    .patient-banner::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -40px;
        width: 220px;
        height: 220px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
    }
    .patient-banner::after {
        content: '';
        position: absolute;
        bottom: -80px;
        left: 20%;
        width: 180px;
        height: 180px;
        background: rgba(255,255,255,0.05);
        border-radius: 50%;
    }

    .banner-profile {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        position: relative;
        z-index: 1;
    }

    .banner-avatar {
        width: 80px;
        height: 80px;
        background: rgba(255,255,255,0.2);
        border: 3px solid rgba(255,255,255,0.4);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 800;
        color: white;
        flex-shrink: 0;
        backdrop-filter: blur(4px);
    }

    .banner-info h1 {
        font-size: 1.8rem;
        font-weight: 800;
        color: white;
        margin-bottom: 0.4rem;
    }

    .banner-info p {
        color: rgba(255,255,255,0.8);
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.2rem;
    }

    .banner-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }

    .banner-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.65rem 1.25rem;
        border-radius: var(--radius-sm);
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        border: none;
        font-family: inherit;
        transition: var(--transition);
        text-decoration: none;
    }

    .banner-btn-glass {
        background: rgba(255,255,255,0.15);
        color: white;
        border: 1px solid rgba(255,255,255,0.3);
        backdrop-filter: blur(4px);
    }
    .banner-btn-glass:hover {
        background: rgba(255,255,255,0.25);
        transform: translateY(-2px);
    }

    .banner-btn-white {
        background: white;
        color: #1E40AF;
    }
    .banner-btn-white:hover {
        background: rgba(255,255,255,0.9);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    @media (max-width: 768px) {
        .patient-banner {
            flex-direction: column;
            align-items: flex-start;
            padding: 1.5rem;
        }
        .banner-info h1 { font-size: 1.4rem; }
    }

    /* ════════════════════════════════════════════════
       OVERVIEW CARDS
    ════════════════════════════════════════════════ */
    .overview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .section-card {
        background: var(--surface);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .section-card:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    }

    .section-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(to left, var(--surface), var(--surface-2));
    }

    .section-card-header-left {
        display: flex;
        align-items: center;
        gap: 0.875rem;
    }

    .section-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.1rem;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    }

    .section-title {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--text-main);
        margin: 0;
    }

    .section-count {
        font-size: 0.75rem;
        color: var(--text-muted);
        font-weight: 600;
        background: var(--surface-2);
        padding: 0.2rem 0.6rem;
        border-radius: 50px;
    }

    .section-card-body {
        padding: 1rem 1.5rem;
        flex: 1;
        min-height: 0;
    }

    .section-card-footer {
        padding: 0.875rem 1.5rem;
        border-top: 1px solid var(--border);
        text-align: center;
    }

    .btn-show-more {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.55rem 1.25rem;
        border-radius: var(--radius-sm);
        border: 1.5px solid var(--primary);
        background: transparent;
        color: var(--primary);
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        font-family: inherit;
        transition: var(--transition);
        text-decoration: none;
    }
    .btn-show-more:hover {
        background: var(--primary);
        color: white;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(59,130,246,0.25);
    }

    /* ── Mini List Items ── */
    .mini-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .mini-item {
        display: flex;
        align-items: flex-start;
        gap: 0.875rem;
        padding: 0.875rem;
        background: var(--surface-2);
        border-radius: var(--radius-md);
        border: 1px solid transparent;
        transition: border-color 0.2s ease;
    }
    .mini-item:hover {
        border-color: var(--border);
    }

    .mini-item-thumb {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        object-fit: cover;
        flex-shrink: 0;
        background: var(--background);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: var(--text-muted);
    }

    .mini-item-content {
        flex: 1;
        min-width: 0;
    }

    .mini-item-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 0.2rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .mini-item-meta {
        font-size: 0.8rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    .mini-item-badge {
        font-size: 0.7rem;
        background: rgba(59,130,246,0.08);
        color: var(--primary);
        border: 1px solid rgba(59,130,246,0.15);
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
        white-space: nowrap;
    }

    .mini-empty {
        text-align: center;
        padding: 2rem 1rem;
        color: var(--text-muted);
    }
    .mini-empty i {
        font-size: 2.5rem;
        margin-bottom: 0.75rem;
        display: block;
        opacity: 0.5;
    }

    /* ════════════════════════════════════════════════
       DEDICATED VIEW
    ════════════════════════════════════════════════ */
    .dedicated-view {
        display: none;
        animation: fadeIn 0.35s ease;
    }
    .dedicated-view.active {
        display: block;
    }

    .dedicated-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border);
    }

    .btn-back {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1.1rem;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-main);
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        font-family: inherit;
        transition: var(--transition);
    }
    .btn-back:hover {
        background: var(--surface-2);
        border-color: var(--primary);
        color: var(--primary);
    }

    .dedicated-title {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--text-main);
        margin: 0;
        flex: 1;
    }

    .dedicated-count {
        font-size: 0.85rem;
        color: var(--text-muted);
        font-weight: 600;
    }

    /* ── Pagination ── */
    .pagination-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
        flex-wrap: wrap;
    }

    .page-btn {
        min-width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 0.75rem;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-main);
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        font-family: inherit;
        transition: var(--transition);
    }
    .page-btn:hover:not(:disabled) {
        border-color: var(--primary);
        color: var(--primary);
    }
    .page-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
    .page-btn.active {
        background: var(--primary);
        border-color: var(--primary);
        color: white;
        box-shadow: 0 4px 10px rgba(59,130,246,0.25);
    }

    /* ── Grid for files in dedicated view ── */
    .dedicated-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1.25rem;
    }

    .file-card-dedicated {
        background: var(--surface);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: flex;
        flex-direction: column;
    }
    .file-card-dedicated:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }

    .file-card-thumb {
        width: 100%;
        aspect-ratio: 4/3;
        object-fit: cover;
        background: var(--surface-2);
        display: block;
    }

    .file-card-placeholder {
        width: 100%;
        aspect-ratio: 4/3;
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        color: var(--text-muted);
    }

    .file-card-body {
        padding: 1rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .file-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 0.35rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .file-card-meta {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-bottom: 0.75rem;
    }

    .file-card-actions {
        display: flex;
        gap: 0.4rem;
        margin-top: auto;
    }

    /* ── Visits Table in dedicated view ── */
    .visits-table-modern {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        background: var(--surface);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        overflow: hidden;
    }
    .visits-table-modern th,
    .visits-table-modern td {
        padding: 1rem 1.25rem;
        text-align: right;
        border-bottom: 1px solid var(--border);
    }
    .visits-table-modern th {
        background: var(--surface-2);
        font-size: 0.82rem;
        font-weight: 800;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .visits-table-modern tbody tr:last-child td {
        border-bottom: none;
    }
    .visits-table-modern tbody tr:hover td {
        background: rgba(59,130,246,0.03);
    }

    /* Visit type badge */
    .visit-type-badge {
        display: inline-block;
        padding: 0.28rem 0.75rem;
        border-radius: 50px;
        font-size: 0.78rem;
        font-weight: 700;
    }
    .badge-kshf   { background: #EFF6FF; color: #1D4ED8; }
    .badge-mtab   { background: #F0FDF4; color: #15803D; }
    .badge-aml    { background: #FFF7ED; color: #C2410C; }
    .badge-tor    { background: #FEF2F2; color: #B91C1C; }
    .badge-other  { background: var(--surface-2); color: var(--text-muted); }

    .session-tags { display: flex; flex-wrap: wrap; gap: 0.35rem; }
    .session-tag {
        font-size: 0.72rem;
        background: rgba(59,130,246,0.08);
        color: var(--primary);
        border: 1px solid rgba(59,130,246,0.2);
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
        white-space: nowrap;
    }

    .cost-badge {
        font-weight: 700;
        color: #059669;
        font-family: 'Inter', sans-serif;
    }

    .next-date-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.82rem;
        color: #7C3AED;
        font-weight: 600;
    }

    /* ── Loader ── */
    .section-loader {
        text-align: center;
        padding: 2.5rem 1rem;
        color: var(--text-muted);
    }
    .section-loader i {
        font-size: 2rem;
        margin-bottom: 0.75rem;
        display: block;
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
        .overview-grid {
            grid-template-columns: 1fr;
        }
        .dedicated-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .visits-table-modern th,
        .visits-table-modern td {
            padding: 0.75rem;
            font-size: 0.85rem;
        }
    }
    @media (max-width: 480px) {
        .dedicated-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Chips for session details in modal */
    .session-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    .chip-label {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 0.85rem;
        border: 1.5px solid var(--border);
        border-radius: 50px;
        font-size: 0.88rem;
        cursor: pointer;
        transition: all 0.2s ease;
        background: var(--surface);
        color: var(--text-muted);
        user-select: none;
    }
    .chip-label:hover { border-color: var(--primary); color: var(--primary); }
    .chip-label input[type="checkbox"] { display: none; }
    .chip-label.checked {
        background: var(--primary);
        border-color: var(--primary);
        color: white;
    }

    /* Modal grid */
    .visit-modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem 1.5rem;
    }
    .modal-section-title {
        grid-column: 1 / -1;
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--primary);
        border-bottom: 2px solid var(--surface-2);
        padding-bottom: 0.5rem;
        margin-top: 0.5rem;
        margin-bottom: -0.5rem;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>
@endpush

@section('content')
    <!-- ════════════════════════════════════════════════
         PATIENT BANNER
    ════════════════════════════════════════════════ -->
    <div id="patientProfile">
        <div class="patient-banner">
            <div class="section-loader">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                <p>جاري التحميل...</p>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════
         MAIN OVERVIEW
    ════════════════════════════════════════════════ -->
    <div id="mainOverview">
        <div class="overview-grid" id="overviewGrid">
            <!-- Injected dynamically -->
        </div>
    </div>

    <!-- ════════════════════════════════════════════════
         DEDICATED VIEWS (Hidden by default)
    ════════════════════════════════════════════════ -->

    <!-- Visits Dedicated View -->
    <div class="dedicated-view" id="view-visits">
        <div class="dedicated-header">
            <button class="btn-back" onclick="showOverview()">
                <i class="fa-solid fa-arrow-right"></i>
                <span>العودة للملخص</span>
            </button>
            <h2 class="dedicated-title"><i class="fa-solid fa-calendar-check" style="color:#10B981;margin-left:0.5rem;"></i> سجل الزيارات</h2>
            <span class="dedicated-count" id="visitsDedicatedCount"></span>
        </div>
        <div class="table-responsive">
            <table class="visits-table-modern">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>نوع الزيارة</th>
                        <th>السبب</th>
                        <th>تفاصيل الجلسة</th>
                        <th>التشخيص</th>
                        <th>التكلفة</th>
                        <th>الزيارة القادمة</th>
                        <th style="text-align:center;">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="dedicatedVisitsList">
                    <tr><td colspan="8" class="section-loader"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination-bar" id="visitsPagination"></div>
    </div>

    <!-- Files Dedicated View -->
    <div class="dedicated-view" id="view-files">
        <div class="dedicated-header">
            <button class="btn-back" onclick="showOverview()">
                <i class="fa-solid fa-arrow-right"></i>
                <span>العودة للملخص</span>
            </button>
            <h2 class="dedicated-title"><i class="fa-solid fa-folder-open" style="color:#3B82F6;margin-left:0.5rem;"></i> ملفات المريض</h2>
            <span class="dedicated-count" id="filesDedicatedCount"></span>
        </div>
        <div class="dedicated-grid" id="dedicatedFilesList">
            <div class="section-loader" style="grid-column:1/-1;"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></div>
        </div>
        <div class="pagination-bar" id="filesPagination"></div>
    </div>

    <!-- Category Dedicated Views (dynamic) -->
    <!-- Category Dedicated Views Container -->
    <div id="dedicatedCategoryViews"></div>

    <!-- Single Category Dedicated View (reused for all categories) -->
    <div class="dedicated-view" id="view-category">
        <div class="dedicated-header">
            <button class="btn-back" onclick="showOverview()">
                <i class="fa-solid fa-arrow-right"></i>
                <span>العودة للملخص</span>
            </button>
            <h2 class="dedicated-title" id="categoryViewTitle">
                <i class="fa-solid fa-folder" style="color:#3B82F6;margin-left:0.5rem;"></i> 
                <span id="categoryViewName"></span>
            </h2>
            <span class="dedicated-count" id="categoryViewCount"></span>
        </div>
        <!-- Search Bar -->
        <div class="category-search-bar" style="margin-bottom:1.5rem; display:flex; gap:0.75rem; align-items:center;">
            <div class="filter-search" style="flex:1; position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; right:1rem; top:50%; transform:translateY(-50%); color:var(--text-muted);"></i>
                <input type="text" id="categorySearchInput" placeholder="البحث في الملفات..." 
                    style="width:100%; padding:0.7rem 2.5rem 0.7rem 1rem; border:1px solid var(--border); border-radius:var(--radius-sm); font-family:inherit; font-size:0.95rem; background:var(--input-bg); color:var(--text-main);"
                    oninput="handleCategorySearch()">
            </div>
            <button class="upload-fab" onclick="openUploadModal()">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span>إضافة ملف</span>
            </button>
        </div>
        <div class="dedicated-grid" id="categoryViewGrid">
            <div class="section-loader" style="grid-column:1/-1;"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></div>
        </div>
        <div class="pagination-bar" id="categoryViewPagination"></div>
    </div>
@endsection

@section('modals')
    <!-- Upload Modal -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal" style="max-width: 540px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #3B82F6, #6366F1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.1rem; flex-shrink: 0;">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 1.2rem; margin: 0;">رفع ملف جديد</h2>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0;">إضافة تقرير، صورة أشعة، أو فيديو</p>
                    </div>
                </div>
                <button class="close-btn" type="button" onclick="closeUploadModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="uploadForm" onsubmit="handleUploadFile(event)" style="padding: 0.25rem 0;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>عنوان الملف</label>
                        <input type="text" id="fileTitle" class="form-control" required placeholder="مثال: أشعة مقطعية على الصدر">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>القسم / التصنيف</label>
                        <select id="fileCategory" class="form-control" required>
                            @foreach($categories as $category)
                            <option value="{{ $category->name }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>التشخيص / الوصف (اختياري)</label>
                        <textarea id="fileDesc" class="form-control" rows="2" placeholder="ملاحظات الطبيب والوصف..." style="resize: vertical;"></textarea>
                    </div>
                    <div class="form-group">
                        <label>تاريخ الملف</label>
                        <input type="date" id="fileDate" class="form-control" required>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <div id="fileNameDisplay" style="width: 100%; padding: 0.65rem 1rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-md); font-size: 0.85rem; color: var(--primary); font-weight: 600; min-height: 46px; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-paperclip" style="color: var(--text-muted);"></i>
                            <span id="fileNameText" style="color: var(--text-muted);">لم يُختر ملف بعد</span>
                        </div>
                    </div>
                </div>

                <!-- Drop Zone -->
                <div class="file-drop-zone" id="fileDropZone" style="margin-top: 0.5rem;">
                    <div class="drop-icons">
                        <i class="fa-solid fa-image" style="font-size: 2rem;"></i>
                        <i class="fa-solid fa-file-pdf" style="font-size: 2rem;"></i>
                        <i class="fa-solid fa-film" style="font-size: 2rem;"></i>
                        <i class="fa-solid fa-file-word" style="font-size: 2rem;"></i>
                    </div>
                    <p style="font-size: 1rem; font-weight: 700; color: var(--text-main);">اسحب الملف هنا أو انقر للاختيار</p>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.3rem;">صور · فيديو · PDF · Word &nbsp;|&nbsp; حتى 50 MB</p>
                    <input type="file" id="fileInput" accept="image/*,video/*,.pdf,.doc,.docx" required onchange="handleFileSelect(event)" style="position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;">
                </div>

                <!-- Progress -->
                <div id="uploadProgressContainer" style="display: none; margin-top: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.88rem; font-weight: 700; color: var(--text-main);">
                        <span><i class="fa-solid fa-circle-notch fa-spin" style="color: var(--primary); margin-left: 0.4rem;"></i> جاري الرفع...</span>
                        <span id="uploadPercentage" style="color: var(--primary);">0%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div id="uploadProgressBar" class="progress-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <div class="d-flex justify-between align-center" style="margin-top: 1.75rem; gap: 1rem;">
                    <button type="button" class="btn btn-outline" onclick="closeUploadModal()" style="flex: 1;">إلغاء</button>
                    <button type="submit" id="uploadSubmitBtn" class="btn btn-primary" style="flex: 2; justify-content: center;">
                        <i class="fa-solid fa-cloud-arrow-up" style="margin-left: 0.5rem;"></i>
                        <span class="btn-text">رفع الملف</span>
                        <i class="fa-solid fa-circle-notch fa-spin spinner-icon" style="display: none; margin-right: 0.5rem;"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- File Viewer Modal -->
    <div class="modal-overlay" id="viewerModal" style="z-index: 2000;">
        <div class="modal" style="max-width: 90%; width: 1000px; height: 85vh; display: flex; flex-direction: column; padding: 1.5rem; background: var(--background);">
            <div class="modal-header" style="margin-bottom: 1rem;">
                <h2 id="viewerTitle" style="font-size: 1.35rem;">عرض الملف</h2>
                <div class="d-flex" style="gap: 0.5rem;">
                    <button class="btn btn-outline" id="zoomInBtn" style="display: none; width: 40px; height: 40px; padding: 0; justify-content: center;" onclick="zoomImage(0.2)"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                    <button class="btn btn-outline" id="zoomOutBtn" style="display: none; width: 40px; height: 40px; padding: 0; justify-content: center;" onclick="zoomImage(-0.2)"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                    <button class="btn btn-outline" id="fullscreenBtn" style="display: none; width: 40px; height: 40px; padding: 0; justify-content: center;" onclick="toggleFullscreen()"><i class="fa-solid fa-expand"></i></button>
                    <button class="close-btn" type="button" onclick="closeViewerModal()"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <div id="viewerContent" style="flex-grow: 1; overflow: auto; display: flex; justify-content: center; align-items: center; background: #0F172A; border-radius: var(--radius-md); position: relative;">
                <!-- Content injected here -->
            </div>
        </div>
    </div>

    <!-- ══ Visit Add/Edit Modal ══ -->
    <div class="modal-overlay" id="visitModal">
        <div class="modal" style="max-width:720px; max-height:90vh; overflow-y:auto; padding: 2rem;">
            <div class="modal-header" style="margin-bottom: 1.5rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <div style="width:48px;height:48px;background:linear-gradient(135deg,#10B981,#059669);border-radius:14px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.3rem;box-shadow:0 4px 12px rgba(16,185,129,0.3);">
                        <i class="fa-solid fa-notes-medical"></i>
                    </div>
                    <div>
                        <h2 style="font-size:1.3rem;font-weight:800;color:var(--text-main);margin:0 0 0.2rem 0;" id="visitModalTitle">تسجيل زيارة جديدة</h2>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;">قم بإدخال بيانات زيارة المريض وتفاصيل الجلسة</p>
                    </div>
                </div>
                <button class="close-btn" onclick="closeVisitModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form id="visitForm" onsubmit="handleSaveVisit(event)">
                <input type="hidden" id="visitId">

                <div class="visit-modal-grid">

                    <div class="modal-section-title"><i class="fa-solid fa-circle-info"></i> البيانات الأساسية</div>

                    <!-- Visit Type -->
                    <div class="form-group">
                        <label>نوع الزيارة <span style="color:var(--danger)">*</span></label>
                        <select id="visitType" class="form-control" required onchange="toggleCustomField('visitType','visitTypeCustomWrap')">
                            <option value="">-- اختر نوع الزيارة --</option>
                            <option value="كشف">كشف</option>
                            <option value="متابعة">متابعة</option>
                            <option value="عملية">عملية</option>
                            <option value="طوارئ">طوارئ</option>
                            <option value="استشارة">استشارة</option>
                            <option value="غيره">غيره (اكتب)</option>
                        </select>
                        <input type="text" id="visitTypeCustom" class="form-control" placeholder="اكتب نوع الزيارة..." style="margin-top:0.5rem;display:none;">
                        <span id="visitTypeCustomWrap" style="display:none;"></span>
                    </div>

                    <!-- Reason -->
                    <div class="form-group">
                        <label>السبب / الشكوى <span style="color:var(--danger)">*</span></label>
                        <select id="visitReason" class="form-control" required onchange="toggleCustomField('visitReason','visitReasonCustomWrap')">
                            <option value="">-- اختر السبب --</option>
                            <option value="ألم">ألم</option>
                            <option value="مراجعة نتائج">مراجعة نتائج</option>
                            <option value="تجديد دواء">تجديد دواء</option>
                            <option value="متابعة عملية">متابعة عملية</option>
                            <option value="فحص دوري">فحص دوري</option>
                            <option value="استشارة">استشارة</option>
                            <option value="طوارئ">طوارئ</option>
                            <option value="غيره">غيره (اكتب)</option>
                        </select>
                        <input type="text" id="visitReasonCustom" class="form-control" placeholder="اكتب السبب..." style="margin-top:0.5rem;display:none;">
                        <span id="visitReasonCustomWrap" style="display:none;"></span>
                    </div>

                    <!-- Date -->
                    <div class="form-group">
                        <label>تاريخ الزيارة <span style="color:var(--danger)">*</span></label>
                        <input type="date" id="visitDate" class="form-control" required>
                    </div>

                    <!-- Time -->
                    <div class="form-group">
                        <label>وقت الزيارة</label>
                        <input type="time" id="visitTime" class="form-control">
                    </div>

                    <div class="modal-section-title"><i class="fa-solid fa-stethoscope"></i> الإجراءات والتشخيص</div>

                    <!-- Session Details checkboxes -->
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>تفاصيل الجلسة (ما تم تنفيذه أو طلبه)</label>
                        <div class="session-chips" id="sessionChips">
                            <label class="chip-label"><input type="checkbox" value="أشعة سينية"> أشعة سينية</label>
                            <label class="chip-label"><input type="checkbox" value="أشعة مقطعية CT"> أشعة مقطعية CT</label>
                            <label class="chip-label"><input type="checkbox" value="رنين مغناطيسي"> رنين مغناطيسي</label>
                            <label class="chip-label"><input type="checkbox" value="تحاليل دم"> تحاليل دم</label>
                            <label class="chip-label"><input type="checkbox" value="تحاليل بول"> تحاليل بول</label>
                            <label class="chip-label"><input type="checkbox" value="سونار"> سونار</label>
                            <label class="chip-label"><input type="checkbox" value="رسم قلب"> رسم قلب</label>
                            <label class="chip-label"><input type="checkbox" value="عملية"> عملية</label>
                            <label class="chip-label"><input type="checkbox" value="تخدير"> تخدير</label>
                            <label class="chip-label"><input type="checkbox" value="روشتة دواء"> روشتة دواء</label>
                            <label class="chip-label"><input type="checkbox" value="حقن"> حقن</label>
                            <label class="chip-label"><input type="checkbox" value="جبيرة"> جبيرة</label>
                        </div>
                    </div>

                    <!-- Diagnosis -->
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>التشخيص الطبي / ملاحظات</label>
                        <textarea id="visitDiagnosis" class="form-control" rows="3" placeholder="اكتب التشخيص التفصيلي والملاحظات السريرية..."></textarea>
                    </div>

                    <!-- Prescription -->
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>الروشتة / العلاج الموصوف</label>
                        <textarea id="visitPrescription" class="form-control" rows="2" placeholder="أسماء الأدوية، الجرعات، التوجيهات..."></textarea>
                    </div>

                    <div class="modal-section-title"><i class="fa-solid fa-money-bill-wave"></i> الحسابات والمتابعة</div>

                    <!-- Cost -->
                    <div class="form-group">
                        <label>تكلفة الكشف (الرسوم)</label>
                        <div style="position:relative;">
                            <input type="number" id="visitCost" class="form-control" placeholder="0" min="0" step="0.5" style="padding-left:3.5rem;" dir="ltr">
                            <span style="position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-weight:700;">EGP</span>
                        </div>
                    </div>

                    <!-- Next Visit -->
                    <div class="form-group">
                        <label>موعد الزيارة القادمة (إن وجد)</label>
                        <input type="date" id="visitNextDate" class="form-control">
                    </div>
                </div>

                <div class="d-flex justify-between align-center" style="margin-top:2rem;gap:1rem;border-top:1px solid var(--border);padding-top:1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeVisitModal()" style="flex:1;">إلغاء</button>
                    <button type="submit" id="visitSubmitBtn" class="btn btn-primary" style="flex:2;justify-content:center;">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span class="btn-text">حفظ بيانات الزيارة</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ Visit Delete Confirm Modal ══ -->
    <div class="modal-overlay" id="deleteVisitModal">
        <div class="modal" style="max-width:380px;text-align:center;padding:2rem;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:3rem;color:#EF4444;margin-bottom:1rem;"></i>
            <h2 style="font-weight:800;margin-bottom:0.5rem;">حذف الزيارة</h2>
            <p style="color:var(--text-muted);margin-bottom:2rem;">هل أنت متأكد من حذف هذه الزيارة؟ لا يمكن التراجع.</p>
            <input type="hidden" id="deleteVisitId">
            <div class="d-flex justify-center" style="gap:1rem;">
                <button class="btn btn-outline" onclick="closeDeleteVisitModal()">إلغاء</button>
                <button class="btn" style="background:#EF4444;color:white;" onclick="confirmDeleteVisit()">
                    <i class="fa-solid fa-trash"></i> حذف
                </button>
            </div>
        </div>
    </div>

    <!-- ══ View Visit Details Modal ══ -->
    <div class="modal-overlay" id="viewVisitModal">
        <div class="modal" style="max-width:700px; max-height:90vh; overflow-y:auto; padding: 2rem;">
            <div class="modal-header" style="margin-bottom: 1.5rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <div style="width:48px;height:48px;background:linear-gradient(135deg,#3B82F6,#2563EB);border-radius:14px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.3rem;box-shadow:0 4px 12px rgba(59,130,246,0.3);">
                        <i class="fa-solid fa-file-medical"></i>
                    </div>
                    <div>
                        <h2 style="font-size:1.3rem;font-weight:800;color:var(--text-main);margin:0 0 0.2rem 0;">تفاصيل الاستشارة / الزيارة</h2>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;" id="viewVisitDateInfo"></p>
                    </div>
                </div>
                <button class="close-btn" onclick="closeViewVisitModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="visit-modal-grid" style="gap: 1.5rem;">

                <div class="modal-section-title"><i class="fa-solid fa-circle-info"></i> البيانات الأساسية</div>

                <div style="background:var(--surface-2); padding: 1rem; border-radius: 8px;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.2rem;">نوع الزيارة</span>
                    <strong id="viewVisitType" style="color:var(--text-main); font-size:1rem;"></strong>
                </div>

                <div style="background:var(--surface-2); padding: 1rem; border-radius: 8px;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.2rem;">السبب / الشكوى</span>
                    <strong id="viewVisitReason" style="color:var(--text-main); font-size:1rem;"></strong>
                </div>

                <div class="modal-section-title"><i class="fa-solid fa-stethoscope"></i> الإجراءات والتشخيص</div>

                <div style="grid-column: 1 / -1; background:var(--surface-2); padding: 1rem; border-radius: 8px;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.5rem;">تفاصيل الجلسة (الإجراءات)</span>
                    <div id="viewVisitSessionDetails" style="display:flex; flex-wrap:wrap; gap:0.5rem;"></div>
                </div>

                <div style="grid-column: 1 / -1; background:var(--surface-2); padding: 1rem; border-radius: 8px;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.2rem;">التشخيص الطبي</span>
                    <p id="viewVisitDiagnosis" style="color:var(--text-main); margin:0; line-height:1.6; white-space:pre-wrap;"></p>
                </div>

                <div style="grid-column: 1 / -1; background:var(--surface-2); padding: 1rem; border-radius: 8px;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.2rem;">الروشتة / العلاج الموصوف</span>
                    <p id="viewVisitPrescription" style="color:var(--text-main); margin:0; line-height:1.6; white-space:pre-wrap; font-weight:700;"></p>
                </div>

                <div class="modal-section-title"><i class="fa-solid fa-money-bill-wave"></i> الحسابات والمتابعة</div>

                <div style="background:var(--surface-2); padding: 1rem; border-radius: 8px; border-right: 4px solid #10B981;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.2rem;">تكلفة الكشف</span>
                    <strong id="viewVisitCost" style="color:#10B981; font-size:1.1rem; font-family:'Inter', sans-serif;"></strong>
                </div>

                <div style="background:var(--surface-2); padding: 1rem; border-radius: 8px; border-right: 4px solid #7C3AED;">
                    <span style="display:block; color:var(--text-muted); font-size:0.8rem; margin-bottom:0.2rem;">موعد الزيارة القادمة</span>
                    <strong id="viewVisitNextDate" style="color:#7C3AED; font-size:1.1rem;"></strong>
                </div>

            </div>

            <div class="d-flex justify-center" style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--border);">
                <button class="btn btn-primary" onclick="closeViewVisitModal()" style="min-width: 200px; justify-content:center;">إغلاق</button>
            </div>
        </div>
    </div>

    <!-- ══ Delete Patient Confirm Modal ══ -->
    <div class="modal-overlay" id="deletePatientModal">
        <div class="modal" style="max-width:380px;text-align:center;padding:2rem;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:3rem;color:#EF4444;margin-bottom:1rem;"></i>
            <h2 style="font-weight:800;margin-bottom:0.5rem;">حذف المريض</h2>
            <p style="color:var(--text-muted);margin-bottom:2rem;">هل أنت متأكد من حذف هذا المريض وكل ملفاته؟ لا يمكن التراجع.</p>
            <div class="d-flex justify-center" style="gap:1rem;">
                <button class="btn btn-outline" onclick="closeDeletePatientModal()">إلغاء</button>
                <button class="btn" style="background:#EF4444;color:white;" onclick="confirmDeletePatient()">
                    <i class="fa-solid fa-trash"></i> حذف
                </button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
/* ═══════════════════════════════════════════════════════
   PATIENT SHOW PAGE — SPA-LIKE ARCHITECTURE
   Overview + Dedicated Views (No routes, no reload)
═══════════════════════════════════════════════════════ */

const patientId = getPatientIdFromUrl();
let overviewData = null;
let allVisits = []; // cached for modals
let allFiles = [];  // cached for viewer

// ── State ──
let currentView = 'overview'; // 'overview' | 'visits' | 'files' | 'category-{name}'
let visitsPagination = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
let filesPagination = { current_page: 1, last_page: 1, per_page: 12, total: 0 };
let categoryPagination = { current_page: 1, last_page: 1, per_page: 12, total: 0 };
let currentCategoryFilter = 'all';

// ── Section Config ──
const sectionConfig = {
    visits: {
        title: 'سجل الزيارات',
        icon: 'fa-calendar-check',
        color: 'linear-gradient(135deg, #10B981, #059669)',
        countKey: 'visits',
    },
    files: {
        title: 'ملفات المريض',
        icon: 'fa-folder-open',
        color: 'linear-gradient(135deg, #3B82F6, #6366F1)',
        countKey: 'files',
    },
};

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
    initApp();
    if (patientId) {
        loadOverview();
    }
});

/* ═══════════════════════════════════════════════════════
   OVERVIEW
═══════════════════════════════════════════════════════ */

async function loadOverview() {
    try {
        const res = await fetch(`/api/patients/${patientId}/overview`);
        if (!res.ok) { window.location.href = '/'; return; }
        overviewData = await res.json();
        renderBanner(overviewData.patient);
        renderOverview(overviewData);
    } catch (err) {
        console.error(err);
    }
}

function renderBanner(patient) {
    const el = document.getElementById('patientProfile');
    if (!el || !patient) return;
    el.innerHTML = `
        <div class="patient-banner">
            <div class="banner-profile">
                <div class="banner-avatar">${getInitials(patient.name)}</div>
                <div class="banner-info">
                    <h1>${escapeHtml(patient.name)}</h1>
                    <div style="display:flex; flex-wrap:wrap; gap:1.5rem; margin-top:0.5rem;">
                        <p style="margin:0;"><i class="fa-solid fa-phone" style="color:rgba(255,255,255,0.7);"></i> ${escapeHtml(patient.phone)}</p>
                        <p style="margin:0;"><i class="fa-solid fa-location-dot" style="color:rgba(255,255,255,0.7);"></i> ${escapeHtml(patient.address)}</p>
                        ${patient.diagnosis ? `<p style="margin:0; font-weight:700;"><i class="fa-solid fa-stethoscope" style="color:#FBBF24;"></i> ${escapeHtml(patient.diagnosis)}</p>` : ''}
                    </div>
                </div>
            </div>
            <div class="banner-actions">
                <button class="banner-btn banner-btn-glass" onclick="mockDownload('pdf')">
                    <i class="fa-solid fa-file-pdf"></i>
                    <span>تنزيل PDF</span>
                </button>
                <button class="banner-btn banner-btn-white" onclick="mockDownload('zip')">
                    <i class="fa-solid fa-file-zipper"></i>
                    <span>تنزيل ZIP</span>
                </button>
                <button class="banner-btn banner-btn-glass" onclick="openDeletePatientModal()" style="background:rgba(239,68,68,0.2);border-color:rgba(239,68,68,0.4);color:#FECACA;">
                    <i class="fa-solid fa-trash"></i>
                    <span>حذف المريض</span>
                </button>
            </div>
        </div>
    `;
}

function renderOverview(data) {
    const grid = document.getElementById('overviewGrid');
    if (!grid) return;

    const counts = data.counts || {};
    const categoryCounts = counts.categories || {};
    const categories = Object.keys(data.category_previews || {});

    let html = '';

    // 1. Visits Card
    html += buildSectionCard('visits', counts.visits || 0, renderMiniVisits(data.recent_visits || []));

    // 2. All Files Card
    html += buildSectionCard('files', counts.files || 0, renderMiniFiles(data.recent_files || []));

    // 3. Per-category cards (Radiology, Labs, etc.)
    const categoryIcons = {
        'أشعة': 'fa-x-ray',
        'أشعة سينية': 'fa-x-ray',
        'أشعة مقطعية': 'fa-x-ray',
        'رنين مغناطيسي': 'fa-x-ray',
        'تحاليل': 'fa-vial',
        'تحاليل دم': 'fa-vial',
        'تحاليل بول': 'fa-vial',
        'سونار': 'fa-wave-square',
        'رسم قلب': 'fa-heart-pulse',
        'عملية': 'fa-scalpel',
        'روشتة': 'fa-prescription-bottle-medical',
        'روشتة دواء': 'fa-prescription-bottle-medical',
        'حقن': 'fa-syringe',
        'جبيرة': 'fa-bandage',
        'تخدير': 'fa-mask-face',
    };
    const categoryColors = {
        'أشعة': 'linear-gradient(135deg, #F59E0B, #D97706)',
        'أشعة سينية': 'linear-gradient(135deg, #F59E0B, #D97706)',
        'أشعة مقطعية': 'linear-gradient(135deg, #F59E0B, #D97706)',
        'رنين مغناطيسي': 'linear-gradient(135deg, #F59E0B, #D97706)',
        'تحاليل': 'linear-gradient(135deg, #8B5CF6, #7C3AED)',
        'تحاليل دم': 'linear-gradient(135deg, #8B5CF6, #7C3AED)',
        'تحاليل بول': 'linear-gradient(135deg, #8B5CF6, #7C3AED)',
        'سونار': 'linear-gradient(135deg, #06B6D4, #0891B2)',
        'رسم قلب': 'linear-gradient(135deg, #EF4444, #DC2626)',
        'عملية': 'linear-gradient(135deg, #EC4899, #DB2777)',
        'روشتة': 'linear-gradient(135deg, #14B8A6, #0D9488)',
        'روشتة دواء': 'linear-gradient(135deg, #14B8A6, #0D9488)',
        'حقن': 'linear-gradient(135deg, #84CC16, #65A30D)',
        'جبيرة': 'linear-gradient(135deg, #64748B, #475569)',
        'تخدير': 'linear-gradient(135deg, #6366F1, #4F46E5)',
    };

    categories.forEach(cat => {
        const previewFiles = data.category_previews[cat] || [];
        const count = categoryCounts[cat] || 0;
        const icon = categoryIcons[cat] || 'fa-folder';
        const color = categoryColors[cat] || 'linear-gradient(135deg, #3B82F6, #6366F1)';
        html += buildCategoryCard(cat, icon, color, count, renderMiniFiles(previewFiles));
    });

    grid.innerHTML = html;
}

function buildSectionCard(key, count, itemsHtml) {
    const cfg = sectionConfig[key];
    return `
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-icon" style="background: ${cfg.color};">
                        <i class="fa-solid ${cfg.icon}"></i>
                    </div>
                    <div>
                        <h3 class="section-title">${cfg.title}</h3>
                    </div>
                </div>
                <span class="section-count">${count} عنصر</span>
            </div>
            <div class="section-card-body">
                ${itemsHtml}
            </div>
            <div class="section-card-footer">
                <button class="btn-show-more" onclick="showDedicatedView('${key}')">
                    <span>عرض المزيد</span>
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
            </div>
        </div>
    `;
}

function buildCategoryCard(category, icon, color, count, itemsHtml) {
    const catId = 'cat-' + category.replace(/[^a-zA-Z0-9\u0600-\u06FF]/g, '-');
    return `
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-header-left">
                    <div class="section-icon" style="background: ${color};">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div>
                        <h3 class="section-title">${escapeHtml(category)}</h3>
                    </div>
                </div>
                <span class="section-count">${count} عنصر</span>
            </div>
            <div class="section-card-body">
                ${itemsHtml}
            </div>
            <div class="section-card-footer">
                <button class="btn-show-more" onclick="showCategoryView('${escapeHtml(category)}')">
                    <span>عرض المزيد</span>
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
            </div>
        </div>
    `;
}

function renderMiniVisits(visits) {
    if (!visits.length) {
        return `<div class="mini-empty"><i class="fa-solid fa-calendar-xmark"></i><p>لا توجد زيارات مسجلة</p></div>`;
    }
    return `<div class="mini-list">` + visits.map(v => {
        const tags = (v.session_details || []).slice(0, 2).map(t => `<span class="mini-item-badge">${escapeHtml(t)}</span>`).join(' ');
        const more = (v.session_details || []).length > 2 ? `<span class="mini-item-badge">+${v.session_details.length - 2}</span>` : '';
        return `
            <div class="mini-item">
                <div class="mini-item-content">
                    <div class="mini-item-title">${visitTypeBadge(v.visit_type_label || v.visit_type)} ${escapeHtml(v.reason_label || v.reason)}</div>
                    <div class="mini-item-meta">
                        <i class="fa-regular fa-calendar"></i> ${v.visit_date || ''}
                        ${v.visit_time ? ` | <i class="fa-regular fa-clock"></i> ${v.visit_time}` : ''}
                    </div>
                    <div style="margin-top:0.35rem;">${tags} ${more}</div>
                </div>
            </div>
        `;
    }).join('') + `</div>`;
}

function renderMiniFiles(files) {
    if (!files.length) {
        return `<div class="mini-empty"><i class="fa-solid fa-folder-open"></i><p>لا توجد ملفات</p></div>`;
    }
    return `<div class="mini-list">` + files.map(f => {
        const src = f.file_path || f.data || null;
        const isImage = f.type && f.type.includes('image');
        const thumb = isImage && src
            ? `<img src="${src}" class="mini-item-thumb" loading="lazy" alt="">`
            : `<div class="mini-item-thumb"><i class="fa-solid ${getFileIcon(f.type)}"></i></div>`;
        return `
            <div class="mini-item" style="cursor:pointer;" onclick="viewFileById(${f.id})">
                ${thumb}
                <div class="mini-item-content">
                    <div class="mini-item-title">${escapeHtml(f.title)}</div>
                    <div class="mini-item-meta">
                        <i class="fa-regular fa-calendar"></i> ${f.date || ''}
                        ${f.category ? ` · <span class="mini-item-badge">${escapeHtml(f.category)}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('') + `</div>`;
}

/* ═══════════════════════════════════════════════════════
   VIEW SWITCHING
═══════════════════════════════════════════════════════ */

function showOverview() {
    currentView = 'overview';
    document.getElementById('mainOverview').style.display = 'block';
    document.querySelectorAll('.dedicated-view').forEach(el => el.classList.remove('active'));
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function hideOverview() {
    document.getElementById('mainOverview').style.display = 'none';
    document.querySelectorAll('.dedicated-view').forEach(el => el.classList.remove('active'));
}

function showDedicatedView(viewName) {
    hideOverview();
    currentView = viewName;
    document.getElementById(`view-${viewName}`).classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });

    if (viewName === 'visits') {
        loadDedicatedVisits(1);
    } else if (viewName === 'files') {
        loadDedicatedFiles(1);
    }
}

function showCategoryView(category) {
    hideOverview();
    currentView = 'category';
    currentCategoryFilter = category;

    // Show the single reused category view
    const viewEl = document.getElementById('view-category');
    if (viewEl) {
        viewEl.classList.add('active');
    }

    // Update title
    document.getElementById('categoryViewName').textContent = category;
    document.getElementById('categorySearchInput').value = '';

    window.scrollTo({ top: 0, behavior: 'smooth' });
    loadCategoryViewData(category, 1);
}

async function loadCategoryViewData(category, page) {
    const grid = document.getElementById('categoryViewGrid');
    const pagEl = document.getElementById('categoryViewPagination');
    const countEl = document.getElementById('categoryViewCount');
    const searchQuery = document.getElementById('categorySearchInput').value.trim();

    if (!grid) return;

    grid.innerHTML = `<div class="section-loader" style="grid-column:1/-1;"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></div>`;

    try {
        let url = `/api/patients/${patientId}/files/paginated?page=${page}&per_page=12`;
        if (category && category !== 'all') {
            url += `&category=${encodeURIComponent(category)}`;
        }
        if (searchQuery) {
            url += `&q=${encodeURIComponent(searchQuery)}`;
        }

        const res = await fetch(url);
        const data = await res.json();
        const files = data.data || [];
        categoryPagination = {
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
        };

        if (countEl) countEl.textContent = `${data.total} ملف`;

        if (!files.length) {
            grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:4rem; color:var(--text-muted);"><i class="fa-solid fa-folder-open" style="font-size:4rem; margin-bottom:1rem; display:block; opacity:0.5;"></i><p>${searchQuery ? 'لا توجد نتائج مطابقة للبحث' : 'لا توجد ملفات في هذا القسم'}</p></div>`;
            if (pagEl) pagEl.innerHTML = '';
            return;
        }

        grid.innerHTML = files.map(f => {
            const src = f.file_path || f.data || null;
            const isImage = f.type && f.type.includes('image');
            const thumb = isImage && src
                ? `<img src="${src}" class="file-card-thumb" loading="lazy" alt="${escapeHtml(f.title)}">`
                : `<div class="file-card-placeholder"><i class="fa-solid ${getFileIcon(f.type)}"></i></div>`;
            const displayName = f.file_name || f.fileName || 'file';
            return `
                <div class="file-card-dedicated">
                    ${thumb}
                    <div class="file-card-body">
                        <div class="file-card-title">${escapeHtml(f.title)}</div>
                        <div class="file-card-meta">
                            <i class="fa-regular fa-calendar"></i> ${f.date || ''}
                            ${f.category ? ` · ${escapeHtml(f.category)}` : ''}
                        </div>
                        <div class="file-card-actions">
                            <button class="btn btn-outline" onclick="deleteFile(${f.id}, ${patientId})" style="width:36px;height:36px;padding:0;justify-content:center;color:#EF4444;border-color:#FEE2E2;" title="حذف">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            ${src ? `<a href="${src}" download="${escapeHtml(displayName)}" class="btn btn-outline" style="width:36px;height:36px;padding:0;justify-content:center;" title="تحميل">
                                <i class="fa-solid fa-download"></i>
                            </a>` : ''}
                            <button class="btn btn-primary" onclick="viewFileById(${f.id})" style="flex:1;justify-content:center;gap:0.4rem;height:36px;font-size:0.85rem;">
                                <i class="fa-solid fa-eye"></i> عرض
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        renderPagination(pagEl, categoryPagination, (p) => loadCategoryViewData(currentCategoryFilter, p));
    } catch (e) {
        console.error(e);
        grid.innerHTML = `<div class="section-loader" style="grid-column:1/-1;"><p>حدث خطأ أثناء التحميل</p></div>`;
    }
}

function handleCategorySearch() {
    // Debounce search
    if (window.__categorySearchTimeout) {
        clearTimeout(window.__categorySearchTimeout);
    }
    window.__categorySearchTimeout = setTimeout(() => {
        if (currentCategoryFilter) {
            loadCategoryViewData(currentCategoryFilter, 1);
        }
    }, 300);
}

/* ═══════════════════════════════════════════════════════
   DEDICATED VISITS (Paginated)
═══════════════════════════════════════════════════════ */

async function loadDedicatedVisits(page) {
    const tbody = document.getElementById('dedicatedVisitsList');
    const pagEl = document.getElementById('visitsPagination');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="8" class="section-loader"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></td></tr>`;

    try {
        const res = await fetch(`/api/patients/${patientId}/visits/paginated?page=${page}&per_page=10`);
        const data = await res.json();
        allVisits = data.data || [];
        visitsPagination = {
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
        };

        document.getElementById('visitsDedicatedCount').textContent = `${data.total} زيارة`;
        renderDedicatedVisitsTable(allVisits);
        renderPagination(pagEl, visitsPagination, loadDedicatedVisits);
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="8" class="section-loader"><p>حدث خطأ أثناء التحميل</p></td></tr>`;
    }
}

function renderDedicatedVisitsTable(visits) {
    const tbody = document.getElementById('dedicatedVisitsList');
    if (!tbody) return;

    if (!visits.length) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--text-muted);"><i class="fa-solid fa-calendar-xmark" style="font-size:3rem;margin-bottom:0.75rem;display:block;"></i>لا توجد زيارات مسجلة بعد</td></tr>`;
        return;
    }

    tbody.innerHTML = visits.map(v => {
        const tags = (v.session_details || []).map(t => `<span class="session-tag">${escapeHtml(t)}</span>`).join('');
        const cost = v.cost ? `<span class="cost-badge">${Number(v.cost).toLocaleString()} ج</span>` : `<span style="color:var(--text-muted)">—</span>`;
        const nextDate = v.next_visit_date ? `<span class="next-date-badge"><i class="fa-solid fa-calendar-days"></i>${v.next_visit_date}</span>` : `<span style="color:var(--text-muted)">—</span>`;
        const diag = v.diagnosis ? `<span style="font-size:0.85rem;color:var(--text-main);">${escapeHtml(v.diagnosis.substring(0,60))}${v.diagnosis.length>60?'…':''}</span>` : `<span style="color:var(--text-muted)">—</span>`;
        return `<tr>
            <td><strong style="font-size:0.9rem;">${v.visit_date || ''}</strong>
                ${v.visit_time ? `<br><small style="color:var(--text-muted)">${v.visit_time}</small>` : ''}</td>
            <td>${visitTypeBadge(v.visit_type_label || v.visit_type)}</td>
            <td style="font-size:0.9rem;">${escapeHtml(v.reason_label || v.reason)}</td>
            <td><div class="session-tags">${tags || '<span style="color:var(--text-muted)">—</span>'}</div></td>
            <td>${diag}</td>
            <td>${cost}</td>
            <td>${nextDate}</td>
            <td>
                <div class="table-actions" style="opacity:1;transform:none;">
                    <button class="btn btn-outline" onclick="openViewVisitModal(${v.id})"
                        style="width:38px;height:38px;padding:0;justify-content:center;color:#3B82F6;border-color:transparent;" title="عرض التفاصيل">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    <button class="btn btn-outline" onclick="openEditVisitModal(${v.id})"
                        style="width:38px;height:38px;padding:0;justify-content:center;color:#F59E0B;border-color:transparent;" title="تعديل">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="btn btn-outline" onclick="openDeleteVisitModal(${v.id})"
                        style="width:38px;height:38px;padding:0;justify-content:center;color:#EF4444;border-color:transparent;" title="حذف">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

/* ═══════════════════════════════════════════════════════
   DEDICATED FILES (Paginated)
═══════════════════════════════════════════════════════ */

async function loadDedicatedFiles(page) {
    const grid = document.getElementById('dedicatedFilesList');
    const pagEl = document.getElementById('filesPagination');
    if (!grid) return;

    grid.innerHTML = `<div class="section-loader" style="grid-column:1/-1;"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></div>`;

    try {
        const res = await fetch(`/api/patients/${patientId}/files/paginated?page=${page}&per_page=12`);
        const data = await res.json();
        allFiles = data.data || [];
        filesPagination = {
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
        };

        document.getElementById('filesDedicatedCount').textContent = `${data.total} ملف`;
        renderDedicatedFilesGrid(allFiles);
        renderPagination(pagEl, filesPagination, loadDedicatedFiles);
    } catch (e) {
        console.error(e);
        grid.innerHTML = `<div class="section-loader" style="grid-column:1/-1;"><p>حدث خطأ أثناء التحميل</p></div>`;
    }
}

function renderDedicatedFilesGrid(files) {
    const grid = document.getElementById('dedicatedFilesList');
    if (!grid) return;

    if (!files.length) {
        grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:4rem; color:var(--text-muted);"><i class="fa-solid fa-folder-open" style="font-size:4rem; margin-bottom:1rem; display:block; opacity:0.5;"></i><p>لا توجد ملفات</p></div>`;
        return;
    }

    grid.innerHTML = files.map(f => {
        const src = f.file_path || f.data || null;
        const isImage = f.type && f.type.includes('image');
        const thumb = isImage && src
            ? `<img src="${src}" class="file-card-thumb" loading="lazy" alt="${escapeHtml(f.title)}">`
            : `<div class="file-card-placeholder"><i class="fa-solid ${getFileIcon(f.type)}"></i></div>`;
        const displayName = f.file_name || f.fileName || 'file';
        return `
            <div class="file-card-dedicated">
                ${thumb}
                <div class="file-card-body">
                    <div class="file-card-title">${escapeHtml(f.title)}</div>
                    <div class="file-card-meta">
                        <i class="fa-regular fa-calendar"></i> ${f.date || ''}
                        ${f.category ? ` · ${escapeHtml(f.category)}` : ''}
                    </div>
                    <div class="file-card-actions">
                        <button class="btn btn-outline" onclick="deleteFile(${f.id}, ${patientId})" style="width:36px;height:36px;padding:0;justify-content:center;color:#EF4444;border-color:#FEE2E2;" title="حذف">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                        ${src ? `<a href="${src}" download="${escapeHtml(displayName)}" class="btn btn-outline" style="width:36px;height:36px;padding:0;justify-content:center;" title="تحميل">
                            <i class="fa-solid fa-download"></i>
                        </a>` : ''}
                        <button class="btn btn-primary" onclick="viewFileById(${f.id})" style="flex:1;justify-content:center;gap:0.4rem;height:36px;font-size:0.85rem;">
                            <i class="fa-solid fa-eye"></i> عرض
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

/* ═══════════════════════════════════════════════════════
   CATEGORY FILES (Paginated) - REUSED SINGLE VIEW
═══════════════════════════════════════════════════════ */

async function loadCategoryViewData(category, page) {
    const grid = document.getElementById('categoryViewGrid');
    const pagEl = document.getElementById('categoryViewPagination');
    const countEl = document.getElementById('categoryViewCount');
    const searchQuery = document.getElementById('categorySearchInput').value.trim();

    if (!grid) return;

    grid.innerHTML = `<div class="section-loader" style="grid-column:1/-1;"><i class="fa-solid fa-circle-notch fa-spin"></i><p>جاري التحميل...</p></div>`;

    try {
        let url = `/api/patients/${patientId}/files/paginated?page=${page}&per_page=12`;
        if (category && category !== 'all') {
            url += `&category=${encodeURIComponent(category)}`;
        }
        if (searchQuery) {
            url += `&q=${encodeURIComponent(searchQuery)}`;
        }

        const res = await fetch(url);
        const data = await res.json();
        const files = data.data || [];
        categoryPagination = {
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
        };

        if (countEl) countEl.textContent = `${data.total} ملف`;

        if (!files.length) {
            grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:4rem; color:var(--text-muted);"><i class="fa-solid fa-folder-open" style="font-size:4rem; margin-bottom:1rem; display:block; opacity:0.5;"></i><p>${searchQuery ? 'لا توجد نتائج مطابقة للبحث' : 'لا توجد ملفات في هذا القسم'}</p></div>`;
            if (pagEl) pagEl.innerHTML = '';
            return;
        }

        grid.innerHTML = files.map(f => {
            const src = f.file_path || f.data || null;
            const isImage = f.type && f.type.includes('image');
            const thumb = isImage && src
                ? `<img src="${src}" class="file-card-thumb" loading="lazy" alt="${escapeHtml(f.title)}">`
                : `<div class="file-card-placeholder"><i class="fa-solid ${getFileIcon(f.type)}"></i></div>`;
            const displayName = f.file_name || f.fileName || 'file';
            return `
                <div class="file-card-dedicated">
                    ${thumb}
                    <div class="file-card-body">
                        <div class="file-card-title">${escapeHtml(f.title)}</div>
                        <div class="file-card-meta">
                            <i class="fa-regular fa-calendar"></i> ${f.date || ''}
                            ${f.category ? ` · ${escapeHtml(f.category)}` : ''}
                        </div>
                        <div class="file-card-actions">
                            <button class="btn btn-outline" onclick="deleteFile(${f.id}, ${patientId})" style="width:36px;height:36px;padding:0;justify-content:center;color:#EF4444;border-color:#FEE2E2;" title="حذف">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            ${src ? `<a href="${src}" download="${escapeHtml(displayName)}" class="btn btn-outline" style="width:36px;height:36px;padding:0;justify-content:center;" title="تحميل">
                                <i class="fa-solid fa-download"></i>
                            </a>` : ''}
                            <button class="btn btn-primary" onclick="viewFileById(${f.id})" style="flex:1;justify-content:center;gap:0.4rem;height:36px;font-size:0.85rem;">
                                <i class="fa-solid fa-eye"></i> عرض
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        renderPagination(pagEl, categoryPagination, (p) => loadCategoryViewData(currentCategoryFilter, p));
    } catch (e) {
        console.error(e);
        grid.innerHTML = `<div class="section-loader" style="grid-column:1/-1;"><p>حدث خطأ أثناء التحميل</p></div>`;
    }
}

function handleCategorySearch() {
    // Debounce search
    if (window.__categorySearchTimeout) {
        clearTimeout(window.__categorySearchTimeout);
    }
    window.__categorySearchTimeout = setTimeout(() => {
        if (currentCategoryFilter) {
            loadCategoryViewData(currentCategoryFilter, 1);
        }
    }, 300);
}

/* ═══════════════════════════════════════════════════════
   PAGINATION COMPONENT
═══════════════════════════════════════════════════════ */

function renderPagination(container, pagination, onPageChange) {
    if (!container) return;
    const { current_page, last_page } = pagination;
    if (last_page <= 1) { container.innerHTML = ''; return; }

    let html = '';
    html += `<button class="page-btn" ${current_page === 1 ? 'disabled' : ''} onclick="${buildPageClick(onPageChange, current_page - 1)}"><i class="fa-solid fa-chevron-right"></i></button>`;

    const maxVisible = 5;
    let start = Math.max(1, current_page - Math.floor(maxVisible / 2));
    let end = Math.min(last_page, start + maxVisible - 1);
    if (end - start + 1 < maxVisible) start = Math.max(1, end - maxVisible + 1);

    if (start > 1) {
        html += `<button class="page-btn" onclick="${buildPageClick(onPageChange, 1)}">1</button>`;
        if (start > 2) html += `<span style="padding:0 0.5rem;color:var(--text-muted);">…</span>`;
    }

    for (let i = start; i <= end; i++) {
        html += `<button class="page-btn ${i === current_page ? 'active' : ''}" onclick="${buildPageClick(onPageChange, i)}">${i}</button>`;
    }

    if (end < last_page) {
        if (end < last_page - 1) html += `<span style="padding:0 0.5rem;color:var(--text-muted);">…</span>`;
        html += `<button class="page-btn" onclick="${buildPageClick(onPageChange, last_page)}">${last_page}</button>`;
    }

    html += `<button class="page-btn" ${current_page === last_page ? 'disabled' : ''} onclick="${buildPageClick(onPageChange, current_page + 1)}"><i class="fa-solid fa-chevron-left"></i></button>`;
    container.innerHTML = html;
}

function buildPageClick(fn, page) {
    // We can't easily pass functions via inline onclick with closures,
    // so we use a global callback registry.
    const id = 'pg_' + Math.random().toString(36).slice(2, 9);
    window.__pageCallbacks = window.__pageCallbacks || {};
    window.__pageCallbacks[id] = () => fn(page);
    return `window.__pageCallbacks['${id}']()`;
}

/* ═══════════════════════════════════════════════════════
   VISITS CRUD (Reuses existing modal logic)
═══════════════════════════════════════════════════════ */

let visitsData = [];

// Chip toggle
document.querySelectorAll('.chip-label').forEach(chip => {
    chip.addEventListener('click', () => chip.classList.toggle('checked'));
});

function getCheckedChips() {
    return [...document.querySelectorAll('.chip-label.checked')]
        .map(c => c.querySelector('input').value);
}

function setCheckedChips(values = []) {
    document.querySelectorAll('.chip-label').forEach(chip => {
        const val = chip.querySelector('input').value;
        chip.classList.toggle('checked', values.includes(val));
    });
}

function toggleCustomField(selectId, wrapId) {
    const sel = document.getElementById(selectId);
    const customInputId = selectId + 'Custom';
    const input = document.getElementById(customInputId);
    if (!input) return;
    const isOther = sel.value === 'غيره';
    input.style.display = isOther ? 'block' : 'none';
    input.required = isOther;
    if (!isOther) input.value = '';
}

function visitTypeBadge(label) {
    const map = {
        'كشف': 'badge-kshf', 'متابعة': 'badge-mtab',
        'عملية': 'badge-aml', 'طوارئ': 'badge-tor'
    };
    const cls = map[label] || 'badge-other';
    return `<span class="visit-type-badge ${cls}">${escapeHtml(label)}</span>`;
}

// Fetch all visits (for modals) + initial load
async function fetchVisits() {
    try {
        const res = await fetch(`/api/patients/${patientId}/visits`);
        visitsData = await res.json();
    } catch(e) { console.error(e); }
}

// Open Add Modal
function openVisitModal() {
    document.getElementById('visitModalTitle').textContent = 'تسجيل زيارة جديدة';
    document.getElementById('visitForm').reset();
    document.getElementById('visitId').value = '';
    setCheckedChips([]);
    document.getElementById('visitTypeCustom').style.display = 'none';
    document.getElementById('visitReasonCustom').style.display = 'none';
    document.getElementById('visitDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('visitModal').classList.add('active');
}

// Open Edit Modal
function openEditVisitModal(id) {
    const v = visitsData.find(x => x.id === id) || allVisits.find(x => x.id === id);
    if (!v) return;
    document.getElementById('visitModalTitle').textContent = 'تعديل الزيارة';
    document.getElementById('visitId').value = v.id;
    document.getElementById('visitDate').value = v.visit_date || '';
    document.getElementById('visitTime').value = v.visit_time || '';
    document.getElementById('visitDiagnosis').value = v.diagnosis || '';
    document.getElementById('visitPrescription').value = v.prescription || '';
    document.getElementById('visitCost').value = v.cost || '';
    document.getElementById('visitNextDate').value = v.next_visit_date || '';

    const typeSelect = document.getElementById('visitType');
    const typeCustom = document.getElementById('visitTypeCustom');
    if (['كشف','متابعة','عملية','طوارئ','استشارة'].includes(v.visit_type)) {
        typeSelect.value = v.visit_type;
        typeCustom.style.display = 'none';
    } else {
        typeSelect.value = 'غيره';
        typeCustom.style.display = 'block';
        typeCustom.value = v.visit_type_custom || v.visit_type;
    }

    const reasons = ['ألم','مراجعة نتائج','تجديد دواء','متابعة عملية','فحص دوري','استشارة','طوارئ'];
    const reasonSelect = document.getElementById('visitReason');
    const reasonCustom = document.getElementById('visitReasonCustom');
    if (reasons.includes(v.reason)) {
        reasonSelect.value = v.reason;
        reasonCustom.style.display = 'none';
    } else {
        reasonSelect.value = 'غيره';
        reasonCustom.style.display = 'block';
        reasonCustom.value = v.reason_custom || v.reason;
    }

    setCheckedChips(v.session_details || []);
    document.getElementById('visitModal').classList.add('active');
}

function closeVisitModal() {
    document.getElementById('visitModal').classList.remove('active');
}

async function handleSaveVisit(e) {
    e.preventDefault();
    const visitId = document.getElementById('visitId').value;
    const btn = document.getElementById('visitSubmitBtn');

    const typeVal = document.getElementById('visitType').value;
    const reasonVal = document.getElementById('visitReason').value;

    const payload = {
        visit_type:        typeVal === 'غيره' ? 'غيره' : typeVal,
        visit_type_custom: typeVal === 'غيره' ? document.getElementById('visitTypeCustom').value : null,
        reason:            reasonVal === 'غيره' ? 'غيره' : reasonVal,
        reason_custom:     reasonVal === 'غيره' ? document.getElementById('visitReasonCustom').value : null,
        visit_date:        document.getElementById('visitDate').value,
        visit_time:        document.getElementById('visitTime').value || null,
        session_details:   getCheckedChips(),
        diagnosis:         document.getElementById('visitDiagnosis').value || null,
        prescription:      document.getElementById('visitPrescription').value || null,
        cost:              document.getElementById('visitCost').value || null,
        next_visit_date:   document.getElementById('visitNextDate').value || null,
    };

    btn.disabled = true;
    btn.querySelector('.btn-text').textContent = 'جاري الحفظ...';

    try {
        const url    = visitId ? `/api/patients/${patientId}/visits/${visitId}` : `/api/patients/${patientId}/visits`;
        const method = visitId ? 'PUT' : 'POST';
        const res    = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (res.ok) {
            const saved = await res.json();
            if (visitId) {
                visitsData = visitsData.map(x => x.id == visitId ? saved : x);
                allVisits = allVisits.map(x => x.id == visitId ? saved : x);
            } else {
                visitsData.unshift(saved);
                allVisits.unshift(saved);
            }
            // Refresh current view
            if (currentView === 'visits') {
                loadDedicatedVisits(visitsPagination.current_page);
            } else {
                loadOverview();
            }
            closeVisitModal();
        } else {
            const err = await res.json();
            alert('خطأ: ' + (err.message || JSON.stringify(err.errors)));
        }
    } catch(err) { console.error(err); alert('خطأ في الاتصال'); }
    finally {
        btn.disabled = false;
        btn.querySelector('.btn-text').textContent = 'حفظ بيانات الزيارة';
    }
}

function openDeleteVisitModal(id) {
    document.getElementById('deleteVisitId').value = id;
    document.getElementById('deleteVisitModal').classList.add('active');
}
function closeDeleteVisitModal() {
    document.getElementById('deleteVisitModal').classList.remove('active');
}
async function confirmDeleteVisit() {
    const id = document.getElementById('deleteVisitId').value;
    try {
        const res = await fetch(`/api/patients/${patientId}/visits/${id}`, { method: 'DELETE' });
        if (res.ok) {
            visitsData = visitsData.filter(v => v.id != id);
            allVisits = allVisits.filter(v => v.id != id);
            if (currentView === 'visits') {
                loadDedicatedVisits(visitsPagination.current_page);
            } else {
                loadOverview();
            }
            closeDeleteVisitModal();
        }
    } catch(e) { console.error(e); }
}

function openViewVisitModal(id) {
    const v = visitsData.find(x => x.id === id) || allVisits.find(x => x.id === id);
    if (!v) return;

    document.getElementById('viewVisitDateInfo').textContent = `${v.visit_date} ${v.visit_time ? '| ' + v.visit_time : ''}`;
    document.getElementById('viewVisitType').innerHTML = visitTypeBadge(v.visit_type_label || v.visit_type);
    document.getElementById('viewVisitReason').textContent = v.reason_label || v.reason;

    const tags = (v.session_details || []).map(t => `<span class="session-tag" style="font-size:0.85rem; padding:0.3rem 0.6rem;">${escapeHtml(t)}</span>`).join('');
    document.getElementById('viewVisitSessionDetails').innerHTML = tags || '<span style="color:var(--text-muted)">لا توجد إجراءات مسجلة</span>';

    document.getElementById('viewVisitDiagnosis').textContent = v.diagnosis || 'لا يوجد تشخيص مسجل';
    document.getElementById('viewVisitPrescription').textContent = v.prescription || 'لا يوجد علاج موصوف';

    document.getElementById('viewVisitCost').textContent = v.cost ? `${Number(v.cost).toLocaleString()} EGP` : '—';
    document.getElementById('viewVisitNextDate').textContent = v.next_visit_date || 'غير محدد';

    document.getElementById('viewVisitModal').classList.add('active');
}
function closeViewVisitModal() {
    document.getElementById('viewVisitModal').classList.remove('active');
}

/* ═══════════════════════════════════════════════════════
   FILES (Viewer & Delete)
═══════════════════════════════════════════════════════ */

function viewFileById(id) {
    const file = allFiles.find(f => f.id === id) || (overviewData?.recent_files || []).find(f => f.id === id);
    if (!file) return;
    // Temporarily inject into filesData so existing viewFile works
    if (!filesData.find(f => f.id === id)) filesData.push(file);
    viewFile(id);
}

/* ═══════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════ */

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Preload visits for modal operations in background
if (patientId) fetchVisits();

/* ═══════════════════════════════════════════════════════
   DELETE PATIENT
═══════════════════════════════════════════════════════ */
function openDeletePatientModal() {
    document.getElementById('deletePatientModal').classList.add('active');
}
function closeDeletePatientModal() {
    document.getElementById('deletePatientModal').classList.remove('active');
}
async function confirmDeletePatient() {
    try {
        const res = await fetch(`/api/patients/${patientId}`, { method: 'DELETE' });
        if (res.ok) {
            window.location.href = '/';
        } else {
            alert('حدث خطأ أثناء حذف المريض');
        }
    } catch(e) {
        console.error(e);
        alert('حدث خطأ في الاتصال');
    }
}
</script>
@endpush
