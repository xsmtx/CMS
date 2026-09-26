<!DOCTYPE html>
@php
    /*
     * The operator's theme, before the first paint.
     *
     * This used to be an inline script reading `localStorage`, and it never
     * ran once: the CSP is `script-src 'self'` with no exceptions, so every
     * load refused it and every dark-theme operator got the white flash the
     * script existed to prevent. The switch writes a cookie as well now, and
     * the server renders the attribute - no script, no exception.
     */
    $theme = request()->cookie('infracms_theme');
    $theme = in_array($theme, ['light', 'dark'], true) ? $theme : null;
@endphp
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="h-full"
    @if ($theme) data-theme="{{ $theme }}" @endif
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">

    <title inertia>{{ config('app.name') }}</title>

    {{-- Rendered once per document rather than shared on every Inertia
         navigation: the locale does not change between two clicks. --}}
    <script type="application/json" id="translations">
        @json(app(\App\Support\View\FrontEndTranslations::class)->forLocale(app()->getLocale()))
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="h-full bg-background text-content antialiased">
    @inertia
</body>
</html>
