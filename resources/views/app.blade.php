<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">

    <title inertia>{{ config('app.name') }}</title>

    {{-- Applied before the first paint, so a dark-theme operator never
         sees a white flash on the way in. --}}
    <script>
        try {
            var theme = localStorage.getItem('infracms.theme');
            if (theme === 'light' || theme === 'dark') {
                document.documentElement.dataset.theme = theme;
            }
        } catch (error) {
            // Site data blocked. The media query still applies.
        }
    </script>

    {{-- Rendered once per document rather than shared on every Inertia
         navigation: the locale does not change between two clicks. --}}
    <script type="application/json" id="translations">
        @json(app(\App\Support\View\FrontEndTranslations::class)->forLocale(app()->getLocale()))
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="h-full bg-surface text-content antialiased">
    @inertia
</body>
</html>
