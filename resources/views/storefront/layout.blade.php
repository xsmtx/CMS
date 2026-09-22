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
        <header class="border-b border-line">
            <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-5">
                <a href="{{ url('/') }}" class="pressable rounded-[var(--radius-sm)] text-sm font-semibold tracking-tight">
                    {{ $brand }}
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
                                class="rounded-[var(--radius-sm)] border border-line bg-surface-raised px-2 py-1.5 text-xs font-medium text-content-muted"
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
                        class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-sm font-medium text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                    >
                        {{ __('storefront.plans') }}
                    </a>
                    <a
                        href="{{ route('storefront.cart') }}"
                        class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-sm font-medium text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                    >
                        {{ __('ordering.cart.title') }}
                    </a>
                    <a
                        href="{{ url('/client') }}"
                        class="pressable rounded-[var(--radius-sm)] px-3 py-2 text-sm font-medium text-content-muted transition-colors duration-(--duration-fast) hover:text-content"
                    >
                        {{ __('storefront.client_area') }}
                    </a>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-6 py-14 sm:py-16">
            @yield('content')
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
