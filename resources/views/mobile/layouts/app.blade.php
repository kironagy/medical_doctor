<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>@yield('title', 'MedicalPlus')</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -webkit-tap-highlight-color: transparent; }
        body { background: #f3f4f6; color: #111827; padding-top: env(safe-area-inset-top, 0px); padding-bottom: env(safe-area-inset-bottom, 0px); }

        .safe-top { padding-top: env(safe-area-inset-top, 0px); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0px); }

        .topbar { position: sticky; top: 0; z-index: 40; background: #fff; border-bottom: 1px solid #e5e7eb; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; }
        .topbar-title { font-size: 18px; font-weight: 600; color: #111827; }
        .topbar-back { background: none; border: none; padding: 4px; margin-right: 12px; cursor: pointer; color: #4b5563; display: flex; align-items: center; }

        .page { padding: 16px; max-width: 640px; margin: 0 auto; }

        .card { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-clickable { cursor: pointer; transition: background 0.15s; }
        .card-clickable:active { background: #f9fafb; }

        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-value { font-size: 28px; font-weight: 700; color: #14b8a6; }
        .stat-label { font-size: 12px; color: #6b7280; margin-top: 4px; }

        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px; border-radius: 10px; font-size: 15px; font-weight: 500; border: none; cursor: pointer; transition: opacity 0.15s; text-decoration: none; }
        .btn:active { opacity: 0.8; }
        .btn-primary { background: #14b8a6; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #374151; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 8px; }
        .btn-block { width: 100%; }
        .btn-icon { padding: 8px; background: none; border: none; cursor: pointer; color: #6b7280; }

        .input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 14px; font-size: 15px; font-family: inherit; background: #fff; outline: none; transition: border-color 0.15s; }
        .input:focus { border-color: #14b8a6; box-shadow: 0 0 0 2px rgba(20,184,166,0.15); }
        .input-group { margin-bottom: 16px; }
        .input-label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        textarea.input { min-height: 80px; resize: vertical; }

        .search-bar { position: relative; margin-bottom: 16px; }
        .search-bar input { padding-left: 40px; }
        .search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }

        .list-item { display: flex; align-items: center; padding: 14px 16px; background: #fff; border-radius: 12px; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); cursor: pointer; text-decoration: none; color: inherit; transition: background 0.15s; }
        .list-item:active { background: #f9fafb; }
        .list-item-content { flex: 1; min-width: 0; }
        .list-item-title { font-size: 15px; font-weight: 500; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .list-item-subtitle { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .list-item-arrow { color: #9ca3af; margin-left: 8px; flex-shrink: 0; }

        .tabs { display: flex; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
        .tab { flex: 1; text-align: center; padding: 10px; font-size: 14px; font-weight: 500; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; background: none; border-top: none; border-left: none; border-right: none; }
        .tab.active { color: #14b8a6; border-bottom-color: #14b8a6; }

        .nav-bottom { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #e5e7eb; padding-bottom: env(safe-area-inset-bottom, 0px); z-index: 50; }
        .nav-inner { display: flex; justify-content: space-around; max-width: 640px; margin: 0 auto; }
        .nav-item { display: flex; flex-direction: column; align-items: center; padding: 8px 16px; text-decoration: none; color: #9ca3af; font-size: 11px; transition: color 0.15s; }
        .nav-item.active, .nav-item:active { color: #14b8a6; }
        .nav-item svg { margin-bottom: 2px; }

        .nav-add { position: relative; top: -16px; }
        .nav-add-circle { width: 48px; height: 48px; background: #14b8a6; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(20,184,166,0.3); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: flex; align-items: flex-end; }
        .modal-content { background: #fff; border-radius: 16px 16px 0 0; width: 100%; max-height: 85vh; overflow-y: auto; padding: 20px; }
        .modal-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; }

        .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 12px; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }

        .empty-state { text-align: center; padding: 40px 20px; color: #9ca3af; font-size: 14px; }

        .flex-row { display: flex; gap: 8px; }
        .flex-1 { flex: 1; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mt-4 { margin-top: 16px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-4 { margin-bottom: 16px; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }

        .avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; flex-shrink: 0; }
        .avatar-sm { width: 32px; height: 32px; font-size: 13px; }
        .avatar-lg { width: 64px; height: 64px; font-size: 24px; }

        .file-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
        .file-icon img { width: 100%; height: 100%; object-fit: cover; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; }
        .badge-gray { background: #f3f4f6; color: #6b7280; }

        a { color: inherit; text-decoration: none; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.2s ease-out; }

        .page-content { padding-bottom: 80px; }
    </style>
    @stack('styles')
</head>
<body>
    @yield('content')

    @if(session('success'))
    <div style="position: fixed; bottom: 80px; left: 16px; right: 16px; z-index: 200; animation: fadeIn 0.2s ease-out;">
        <div class="alert alert-success text-center">{{ session('success') }}</div>
    </div>
    @endif

    @if($errors->any())
    <div style="position: fixed; bottom: 80px; left: 16px; right: 16px; z-index: 200; animation: fadeIn 0.2s ease-out;">
        <div class="alert alert-error text-center">{{ $errors->first() }}</div>
    </div>
    @endif

    <script src="{{ asset('vendor/mobile/js/alpine.min.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
