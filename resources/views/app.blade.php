<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'Medical Plus') }}</title>

        <!-- Theme Color (Android Task Switcher + Status Bar) -->
        <meta name="theme-color" content="#0d9488" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#030712" media="(prefers-color-scheme: dark)">
        <meta name="color-scheme" content="light dark">

        <!-- Full-screen / Edge-to-Edge on Android -->
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

        <link rel="dns-prefetch" href="{{ url('/') }}">
        <link rel="preconnect" href="{{ url('/') }}">

        <script>
          (function() {
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
    </head>
    <body class="font-sans antialiased text-slate-900">
        @inertia
    </body>
</html>
