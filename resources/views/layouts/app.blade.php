<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title data-i18n="title">نظام أرشفة المرضى</title>
    @php
        $mobileApiMeta = '/api/v1';
    @endphp
    <meta name="mobile-api-url" content="{{ $mobileApiMeta }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <style>
        /* ── Global Page Loader ── */
        #page-loader {
            position: fixed;
            inset: 0;
            background: var(--background, #F8FAFC);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.4s ease, visibility 0.4s ease;
        }
        #page-loader.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .loader-ring {
            width: 64px;
            height: 64px;
            border: 5px solid #E2E8F0;
            border-top-color: var(--primary, #3B82F6);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-bottom: 1.5rem;
        }
        .loader-text {
            font-size: 1rem;
            color: var(--text-muted, #94A3B8);
            font-weight: 600;
            letter-spacing: 0.5px;
            animation: pulse-text 1.5s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @keyframes pulse-text {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* ── Upload Modal Improvements ── */
        .upload-modal-body { padding: 0.5rem 0; }
        .file-drop-zone {
            border: 2.5px dashed var(--border);
            border-radius: var(--radius-lg);
            padding: 2.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            background: var(--background);
            position: relative;
        }
        .file-drop-zone:hover, .file-drop-zone.dragover {
            border-color: var(--primary);
            background: rgba(59,130,246,0.04);
        }
        .file-drop-zone .drop-icons {
            display: flex;
            justify-content: center;
            gap: 1.2rem;
            margin-bottom: 1rem;
            color: #CBD5E1;
            transition: color 0.2s;
        }
        .file-drop-zone:hover .drop-icons { color: var(--primary); }
        .file-drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        #uploadProgressContainer {
            background: var(--background);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem 1.2rem;
            margin-top: 1rem;
            animation: fadeIn 0.3s ease;
        }
        .progress-bar-bg {
            width: 100%;
            height: 8px;
            background: #E2E8F0;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3B82F6, #6366F1);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Spinner inside button ── */
        .fa-spin { animation: spin 0.8s linear infinite; }
    </style>
    <style>
        /* Mobile Safe Area Insets */
        body {
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        header {
            padding-top: env(safe-area-inset-top);
        }
    </style>
    @stack('styles')
</head>
<body>
    <!-- Page Loader -->
    <div id="page-loader">
        <div class="loader-ring"></div>
        <span class="loader-text">جاري التحميل...</span>
    </div>

    <header>
        <div class="nav-container">
            <a href="{{ url('/') }}" class="logo">
                <div class="logo-icon-wrap">
                    <i class="fa-solid fa-notes-medical"></i>
                </div>
                <div class="logo-text-wrap">
                    <span class="logo-title" data-i18n="title">نظام أرشفة المرضى</span>
                    <span class="logo-sub">Medical Archive System</span>
                </div>
            </a>
            <div class="actions">
                <button class="icon-btn" onclick="toggleTheme()" title="تغيير المظهر">
                    <i class="fa-solid fa-moon theme-icon"></i>
                </button>
                <button class="icon-btn lang-btn" onclick="toggleLanguage()" title="Change Language">
                    <i class="fa-solid fa-globe"></i>
                    <span id="langText">EN</span>
                </button>
                @hasSection('header-actions')
                    @yield('header-actions')
                @endif
                <button type="button" class="icon-btn logout-btn" title="تسجيل خروج" onclick="openLogoutModal()">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                </button>
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <!-- Logout Confirm Modal -->
    <div class="modal-overlay" id="logoutModalOverlay">
        <div class="modal" style="max-width:380px;text-align:center;padding:2rem;">
            <i class="fa-solid fa-arrow-right-from-bracket" style="font-size:3rem;color:var(--primary);margin-bottom:1rem;"></i>
            <h2 style="font-weight:800;margin-bottom:0.5rem;">تسجيل الخروج</h2>
            <p style="color:var(--text-muted);margin-bottom:2rem;">هل تريد تسجيل الخروج؟</p>
            <div style="display:flex;justify-content:center;gap:1rem;">
                <button class="btn btn-outline" onclick="closeLogoutModal()" style="padding:0.65rem 1.5rem;">لا</button>
                <button class="btn btn-primary" onclick="confirmLogout()" style="padding:0.65rem 1.5rem;">نعم</button>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('logout') }}" id="logoutForm" style="display:none;">
        @csrf
    </form>

    @yield('modals')

    <script src="{{ asset('assets/js/script.js') }}"></script>
    <script>
        window.MOBILE_API_BASE = document.querySelector('meta[name="mobile-api-url"]')?.content || '/api/v1';
        window.apiHeaders = window.apiHeaders || function(extra = {}) {
            const token = localStorage.getItem('api_token');
            return token ? { ...extra, 'Authorization': `Bearer ${token}` } : extra;
        };
        window.apiUrl = window.apiUrl || function(path) {
            if (path.startsWith('http://') || path.startsWith('https://')) return path;
            const normalizedPath = path.startsWith('/') ? path : `/${path}`;
            const normalizedBase = window.MOBILE_API_BASE.replace(/\/+$|\/$/, '');
            if (normalizedPath.startsWith(normalizedBase)) return normalizedPath;
            try {
                const baseUrl = new URL(normalizedBase, window.location.origin);
                if (baseUrl.pathname && normalizedPath.startsWith(baseUrl.pathname)) return normalizedPath;
            } catch (err) {
                // ignore invalid base
            }
            return `${normalizedBase}${normalizedPath}`;
        };

        // Hide loader once DOM + first paint complete
        window.addEventListener('load', () => {
            const loader = document.getElementById('page-loader');
            if (loader) {
                setTimeout(() => loader.classList.add('hidden'), 300);
            }
        });

        function openLogoutModal() {
            document.getElementById('logoutModalOverlay').classList.add('active');
        }
        function closeLogoutModal() {
            document.getElementById('logoutModalOverlay').classList.remove('active');
        }
        function confirmLogout() {
            document.getElementById('logoutForm').submit();
        }
    </script>
    @stack('scripts')
</body>
</html>
