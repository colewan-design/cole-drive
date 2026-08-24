<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'UITPH Drive') }}</title>

        {{-- Runs before the stylesheet paints, so a dark-mode reload never
             flashes white. Deliberately inline and dependency-free: anything
             deferred to the bundle would land after the first paint.
             The storage key must match resources/js/composables/useTheme.js. --}}
        <script>
            (function () {
                var prefersDark = window.matchMedia
                    && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var choice = null;

                try {
                    choice = localStorage.getItem('uitph-drive-theme');
                } catch (e) {
                    // Blocked site data throws rather than returning null.
                }

                var dark = choice === 'dark' || (choice !== 'light' && prefersDark);
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        <!-- Favicon -->
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" href="/icon-32.png" sizes="32x32">
        <link rel="icon" type="image/png" href="/icon-512.png" sizes="512x512">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
        @inertia
    </body>
</html>
