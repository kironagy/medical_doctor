<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'prof hosam fekry ortho team') }}</title>

        <!-- Theme Color (Android Task Switcher + Status Bar) -->
        <meta name="theme-color" content="#0d9488" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#030712" media="(prefers-color-scheme: dark)">
        <meta name="color-scheme" content="light dark">

        <!-- Full-screen / Edge-to-Edge on Android -->
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        <!-- Local Fonts (offline-ready) -->
        <style>
@font-face { font-family: 'Cairo'; font-style: normal; font-weight: 300; font-display: swap; src: url('/fonts/cairo-300.woff2') format('woff2'); }
@font-face { font-family: 'Cairo'; font-style: normal; font-weight: 400; font-display: swap; src: url('/fonts/cairo-400.woff2') format('woff2'); }
@font-face { font-family: 'Cairo'; font-style: normal; font-weight: 500; font-display: swap; src: url('/fonts/cairo-500.woff2') format('woff2'); }
@font-face { font-family: 'Cairo'; font-style: normal; font-weight: 600; font-display: swap; src: url('/fonts/cairo-600.woff2') format('woff2'); }
@font-face { font-family: 'Cairo'; font-style: normal; font-weight: 700; font-display: swap; src: url('/fonts/cairo-700.woff2') format('woff2'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 300; font-display: swap; src: url('/fonts/inter-300.woff2') format('woff2'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 400; font-display: swap; src: url('/fonts/inter-400.woff2') format('woff2'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 500; font-display: swap; src: url('/fonts/inter-500.woff2') format('woff2'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 600; font-display: swap; src: url('/fonts/inter-600.woff2') format('woff2'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 700; font-display: swap; src: url('/fonts/inter-700.woff2') format('woff2'); }
        </style>

        <link rel="dns-prefetch" href="{{ url('/') }}">
        <link rel="preconnect" href="{{ url('/') }}">

        <script>
          (function() {
            try {
              var persist = localStorage.getItem('np_persist_login');
              if (persist === '1') {
                localStorage.removeItem('np_persist_login');
                if (window.location.pathname === '/login') {
                  window.location.href = '/';
                  return;
                }
              }
            } catch(e) {}

            try {
              var theme = localStorage.getItem('theme');
              if (!theme) {
                var prefs = JSON.parse(localStorage.getItem('user_preferences') || '{}');
                theme = prefs.theme || 'system';
              }
              var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
              if (isDark) {
                document.documentElement.classList.add('dark');
              }
              var locale = localStorage.getItem('locale');
              if (locale === 'ar') {
                document.documentElement.lang = 'ar';
                document.documentElement.dir = 'rtl';
              }
            } catch(e) {}
          })();
        </script>

        <style>
          /* Prevent white flash on app start */
          html, body { margin: 0; padding: 0; }
          body { background-color: #f8fafc; }
          html.dark body { background-color: #030712; }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead

        @auth
            @php
                try {
                    $tokenRow = Illuminate\Support\Facades\DB::table('sync_states')
                        ->where('key', 'api_token')
                        ->first();
                    $apiToken = $tokenRow ? json_decode($tokenRow->value, true)['plain'] ?? null : null;
                } catch (\Throwable $e) {
                    $apiToken = null;
                }
            @endphp
            @if($apiToken)
                <meta name="api-token" content="{{ $apiToken }}">
            @endif
        @endauth
    </head>
    <body class="font-sans antialiased text-slate-900">
        @inertia
    </body>
</html>
