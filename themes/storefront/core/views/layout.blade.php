{{--
    Core storefront shell.

    Every public page extends this. Themes override the whole file through
    the precedence chain, which is why the chrome lives here rather than
    being repeated in each template.

    No version, dependency or environment detail appears on a public page: an
    unauthenticated visitor learning the PHP version is reconnaissance.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="@yield('robots', 'index,follow')">

    <title>@yield('title', $brand)</title>
    <meta name="description" content="@yield('description', __('storefront.meta_description'))">

    @if ($branding->faviconUrl)
        <link rel="icon" href="{{ $branding->faviconUrl }}">
    @endif

    @vite(['resources/css/app.css'])

    {{--
        The brand's colours as custom properties on the document.

        The design system is already built on these tokens, so a brand
        overrides three of them and every button, focus ring, badge and link
        follows — with no stylesheet regenerated and no build step between
        an operator picking a colour and seeing it.
    --}}
    @if ($branding->cssVariables() !== [])
        <style>
            :root {
                @foreach ($branding->cssVariables() as $property => $value)
                    {{ $property }}: {{ $value }};
                @endforeach
            }
        </style>
    @endif</head>
<body class="storefront h-full bg-surface-primary">
    <a
        href="#main"
        class="sr-only rounded-[var(--radius-sm)] focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-10 focus:bg-surface-primary focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
        {{ __('storefront.skip_to_content') }}
    </a>

    <div class="flex min-h-full flex-col">
{{--
            `global-nav`: 44px, 12px links, and **parchment rather than
            black**. The reference is unambiguous about this - apple.com's bar
            is #f5f5f7 with dark links in light appearance and black in dark,
            which is what `--background` already resolves to. A black bar over
            a white page is the one thing that made this read as a dark SaaS
            product rather than as a shop.

            Translucent, because the bar never scrolls away and a tile passing
            under it should show through.
        --}}
        <header class="bg-background/80 sticky top-0 z-30 backdrop-blur-xl">
            <div class="mx-auto flex h-11 w-full max-w-[1024px] items-center justify-between gap-4 px-6">
                <a href="{{ url('/') }}" class="pressable flex items-center gap-2 rounded-[var(--radius-sm)] text-chrome font-semibold tracking-tight">
                    @if ($branding->logoUrl)
                        <img src="{{ $branding->logoUrl }}" alt="{{ $brand }}" class="h-5 w-auto max-w-[10rem] object-contain">
                    @else
                        {{ $brand }}
                    @endif
                </a>

                <div class="flex items-center gap-1">
                    @if (! empty($currencies ?? []) && count($currencies) > 1)
                        {{-- A POST, because choosing a currency changes what
                             the next page says and a crawler following a link
                             should not be able to change anyone's session. --}}
                        <form method="POST" action="{{ route('storefront.currency') }}" class="mr-2">
                            @csrf
                            <label class="sr-only" for="currency">{{ __('storefront.currency') }}</label>
                            <select
                                id="currency"
                                name="currency"
                                onchange="this.form.submit()"
                                class="rounded-[var(--radius-sm)] border border-line bg-surface-primary px-2 py-1 text-chrome font-medium text-content-muted"
                            >
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($code === ($currency ?? null))>{{ $code }}</option>
                                @endforeach
                            </select>
                            <noscript>
                                <button type="submit" class="ml-1 text-xs underline underline-offset-4">
                                    {{ __('storefront.apply') }}
                                </button>
                            </noscript>
                        </form>
                    @endif

                    <a
                        href="{{ url('/store') }}"
                        class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-chrome text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                    >
                        {{ __('storefront.plans') }}
                    </a>

                    {{-- Only what has something behind it. A link to "no
                         extensions are on sale yet" teaches a visitor that
                         the navigation lies. --}}
                    @if ($sections['domains'] ?? false)
                        <a
                            href="{{ route('storefront.domains') }}"
                            class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-chrome text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                        >
                            {{ __('domains.search.title') }}
                        </a>
                    @endif

                    @if ($sections['help'] ?? false)
                        <a
                            href="{{ route('storefront.kb') }}"
                            class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-chrome text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                        >
                            {{ __('support.kb.title') }}
                        </a>
                    @endif

                    @if ($sections['announcements'] ?? false)
                        <a
                            href="{{ route('storefront.announcements') }}"
                            class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-chrome text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                        >
                            {{ __('support.announcements.title') }}
                        </a>
                    @endif
                    <a
                        href="{{ route('storefront.cart') }}"
                        class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-chrome text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                    >
                        {{ __('ordering.cart.title') }}
                    </a>
                    <a
                        href="{{ url('/client') }}"
                        class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-chrome text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                    >
                        {{ __('storefront.client_area') }}
                    </a>
                </div>
            </div>
        </header>

        {{--
            No max-width here: a tile is full-bleed and sets its own column,
            because the edge-to-edge alternation between canvas and parchment
            is what divides the page. A border would be a second divider
            saying the same thing.
        --}}
        <main id="main" class="flex-1">
            @yield('content')
        </main>

        {{-- Parchment, not a rule: the colour change is the divider. --}}
        <footer class="bg-background">
            <div class="mx-auto flex w-full max-w-[1024px] flex-col gap-3 px-6 py-8 text-chrome text-content-subtle sm:flex-row sm:items-center sm:justify-between">
                <span>&copy; {{ date('Y') }} {{ $branding->documentName() }}</span>

                @if ($branding->legalLinks !== [])
                    <nav class="flex flex-wrap gap-x-4 gap-y-1" aria-label="{{ __('branding.legal') }}">
                        @foreach ($branding->legalLinks as $link)
                            <a href="{{ $link['url'] }}" class="underline-offset-4 hover:underline">{{ $link['label'] }}</a>
                        @endforeach
                    </nav>
                @endif

                <span class="flex flex-wrap items-center gap-x-3">
                    @if ($branding->supportEmail)
                        <a href="mailto:{{ $branding->supportEmail }}" class="underline-offset-4 hover:underline">{{ $branding->supportEmail }}</a>
                    @endif

                    {{--
                        The vendor's mark, removable only where the licence
                        allows it. Rendered from a resolved value rather than
                        from a licence check in the template, so that a theme
                        cannot decide the answer for itself.
                    --}}
                    @if ($vendorMark)
                        <a href="{{ $vendorUrl }}" rel="noopener" class="underline-offset-4 hover:underline">{{ $vendorMark }}</a>
                    @endif
                </span>
            </div>
        </footer>
    </div>
</body>
</html>
