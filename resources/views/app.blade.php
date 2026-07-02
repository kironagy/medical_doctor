<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">

        <title inertia>{{ config('app.name', 'Medical Plus') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

        <!-- Preconnect to app domain for faster video/asset loading -->
        <link rel="dns-prefetch" href="{{ url('/') }}">
        <link rel="preconnect" href="{{ url('/') }}">

        <script>
          // Prevent dark mode flash — apply before Vue mounts
          (function() {
            try {
              var theme = localStorage.getItem('theme');
              if (!theme) {
                var prefs = JSON.parse(localStorage.getItem('user_preferences') || '{}');
                theme = prefs.theme || 'system';
              }
              if (theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
              }
            } catch(e) {}
          })();
        </script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-900">
        @inertia
    </body>
</html>
