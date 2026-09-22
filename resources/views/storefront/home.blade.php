{{--
    Core fallback storefront template.

    This installation has no catalog yet, so the page states exactly that and
    routes the two audiences who can act on it. It deliberately shows no
    version, dependency or environment detail: this page is public, and an
    unauthenticated visitor learning the PHP version is reconnaissance.

    Themes override this file through the precedence chain introduced in
    Phase 11. Nothing here should be edited by an installation.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">

    <title>{{ $brand }}</title>
    <meta name="description" content="{{ __('storefront.meta_description') }}">

    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <a
        href="#main"
        class="sr-only rounded-[var(--radius-sm)] focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-10 focus:bg-surface-raised focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
        {{ __('storefront.skip_to_content') }}
    </a>

    <div class="flex min-h-full flex-col">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
            <span class="text-sm font-semibold tracking-tight">{{ $brand }}</span>

            <nav aria-label="{{ __('storefront.primary_navigation') }}" class="flex items-center gap-1">
                <a
                    href="{{ url('/client') }}"
                    class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-sm font-medium text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                >
                    {{ __('storefront.client_area') }}
                </a>
                <a
                    href="{{ url('/admin') }}"
                    class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-sm font-medium text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                >
                    {{ __('storefront.admin') }}
                </a>
            </nav>
        </header>

        <main id="main" class="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center px-6 py-16 sm:py-20">
            <div class="grid gap-14 lg:grid-cols-12 lg:gap-16">
                <div class="lg:col-span-7">
                    <h1 class="text-4xl leading-[1.05] font-semibold tracking-tighter text-balance sm:text-5xl lg:text-6xl">
                        {{ __('storefront.headline', ['brand' => $brand]) }}
                    </h1>

                    <p class="mt-6 max-w-[52ch] text-base leading-relaxed text-content-muted sm:text-lg">
                        {{ __('storefront.subheadline') }}
                    </p>

                    <div class="mt-10 flex flex-wrap items-center gap-3">
                        <a
                            href="{{ url('/client') }}"
                            class="pressable inline-flex items-center rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised) transition-colors duration-(--duration-fast) hover:bg-accent-hover"
                        >
                            {{ __('storefront.client_area') }}
                        </a>
                        <a
                            href="{{ url('/admin') }}"
                            class="pressable inline-flex items-center rounded-[var(--radius-sm)] border border-line-strong px-5 py-2.5 text-sm font-semibold text-content transition-colors duration-(--duration-fast) hover:bg-surface-sunken"
                        >
                            {{ __('storefront.admin') }}
                        </a>
                    </div>
                </div>

                <div class="lg:col-span-5">
                    <div class="rounded-[var(--radius-lg)] border border-line bg-surface-raised p-6 shadow-(--shadow-panel) sm:p-7">
                        <h2 class="text-sm font-semibold">{{ __('storefront.next_steps_title') }}</h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-content-muted">
                            {{ __('storefront.next_steps_body') }}
                        </p>

                        <ol class="mt-6 space-y-5">
                            @foreach (__('storefront.next_steps') as $index => $step)
                                <li class="flex gap-4">
                                    <span
                                        class="mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-surface-sunken font-mono text-[11px] font-medium text-content-muted"
                                        aria-hidden="true"
                                    >{{ $index + 1 }}</span>
                                    <span class="text-sm leading-relaxed">
                                        <span class="font-medium">{{ $step['title'] }}</span>
                                        <span class="mt-0.5 block text-content-muted">{{ $step['body'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
            </div>
        </main>

        <footer class="border-t border-line">
            <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-6 text-xs text-content-subtle sm:flex-row sm:items-center sm:justify-between">
                <span>&copy; {{ date('Y') }} {{ $brand }}</span>
                <span>{{ __('storefront.footer_note') }}</span>
            </div>
        </footer>
    </div>
</body>
</html>
