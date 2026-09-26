@extends('storefront::layout')

@section('title', $product['name'] . ' — ' . $brand)
@section('description', $product['tagline'] ?? __('catalog.storefront.subtitle'))

@section('content')
    <nav aria-label="{{ __('storefront.primary_navigation') }}" class="text-chrome text-content-subtle">
        <a href="{{ route('storefront.catalog') }}" class="underline underline-offset-4 hover:text-content-muted">
            {{ __('catalog.storefront.title') }}
        </a>
        @if ($product['group'])
            <span aria-hidden="true" class="mx-1.5">/</span>
            <span>{{ $product['group'] }}</span>
        @endif
    </nav>

    <div class="mt-4 grid gap-12 lg:grid-cols-12 lg:gap-16">
        <div class="lg:col-span-7">
            <h1 class="text-page leading-[1.1] font-semibold tracking-tighter text-balance sm:text-display">
                {{ $product['name'] }}
            </h1>

            @if ($product['tagline'])
                <p class="mt-4 max-w-[56ch] text-title leading-relaxed text-content-muted">
                    {{ $product['tagline'] }}
                </p>
            @endif

            @if ($product['description'])
                <div class="mt-6 max-w-[64ch] text-body leading-relaxed whitespace-pre-line">
                    {{ $product['description'] }}
                </div>
            @endif

            @if (! empty($product['features']))
                <section class="mt-10">
                    <h2 class="text-body font-semibold">{{ __('catalog.storefront.features') }}</h2>
                    <ul class="mt-3 grid gap-2 text-body text-content-muted sm:grid-cols-2">
                        @foreach ($product['features'] as $feature)
                            <li class="flex gap-2">
                                <span aria-hidden="true" class="text-content-subtle">&middot;</span>
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @foreach ($product['optionGroups'] as $group)
                <section class="mt-10">
                    <h2 class="text-body font-semibold">
                        {{ $group['name'] }}
                        @if ($group['isRequired'])
                            <span class="ml-1.5 text-chrome font-normal text-content-subtle">
                                {{ __('catalog.options.required') }}
                            </span>
                        @endif
                    </h2>

                    @if ($group['description'])
                        <p class="mt-1 text-body leading-relaxed text-content-muted">{{ $group['description'] }}</p>
                    @endif

                    <ul class="mt-3 divide-y divide-line rounded-[var(--radius-md)] border border-line">
                        @foreach ($group['options'] as $option)
                            <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-body">
                                <span>
                                    {{ $option['label'] }}
                                    @if ($option['isDefault'])
                                        <span class="ml-1.5 text-chrome text-content-subtle">
                                            {{ __('catalog.options.default') }}
                                        </span>
                                    @endif
                                </span>
                                <span class="text-content-muted tabular-nums">{{ $option['delta'] ?? '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            @if (! empty($product['addons']))
                <section class="mt-10">
                    <h2 class="text-body font-semibold">{{ __('catalog.addons.title') }}</h2>
                    <ul class="mt-3 divide-y divide-line rounded-[var(--radius-md)] border border-line">
                        @foreach ($product['addons'] as $addon)
                            <li class="flex items-center justify-between gap-4 px-4 py-3 text-body">
                                <span>
                                    {{ $addon['name'] }}
                                    @if ($addon['description'])
                                        <span class="mt-0.5 block text-chrome text-content-muted">{{ $addon['description'] }}</span>
                                    @endif
                                </span>
                                <span class="whitespace-nowrap text-content-muted tabular-nums">{{ $addon['price'] ?? '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        {{-- The price panel. Sticky on a wide screen so the number an
             operator set stays next to the description it belongs to. --}}
        <div class="lg:col-span-5">
            <div class="rounded-[var(--radius-xl)] border border-line bg-surface-primary p-6 lg:sticky lg:top-8">
                <h2 class="text-body font-semibold">{{ __('catalog.pricing.title') }}</h2>

                <ul class="mt-4 divide-y divide-line">
                    @foreach ($product['cycles'] as $cycle)
                        <li class="flex items-baseline justify-between gap-4 py-3">
                            <span class="text-body text-content-muted">{{ $cycle['cycleLabel'] }}</span>
                            <span class="text-right">
                                <span class="text-title font-semibold tabular-nums">{{ $cycle['amount'] }}</span>
                                @if ($cycle['setup'])
                                    <span class="mt-0.5 block text-chrome text-content-subtle">
                                        {{ __('catalog.storefront.setup_fee', ['amount' => $cycle['setup']]) }}
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($product['requiresDomain'])
                    <p class="mt-4 text-chrome text-content-subtle">
                        {{ __('catalog.storefront.includes_domain') }}
                    </p>
                @endif

                <div class="mt-6">
                    @if ($product['soldOut'])
                        <span class="inline-flex w-full items-center justify-center rounded-[var(--radius-sm)] border border-line px-5 py-2.5 text-body font-semibold text-content-subtle">
                            {{ __('catalog.products.sold_out') }}
                        </span>
                    @else
                        <a
                            href="{{ route('storefront.configure', $product['slug']) }}"
                            class="pressable inline-flex w-full items-center justify-center rounded-[var(--radius-sm)] bg-brand px-5 py-2.5 text-body font-semibold text-content-inverse transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                        >
                            {{ __('catalog.storefront.order_now') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
