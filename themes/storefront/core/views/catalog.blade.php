@extends('storefront::layout')

@section('title', __('catalog.storefront.title') . ' — ' . $brand)
@section('description', __('catalog.storefront.subtitle'))

@section('content')
    <div class="max-w-[46ch]">
        <h1 class="text-4xl leading-[1.05] font-semibold tracking-tighter text-balance sm:text-5xl">
            {{ __('catalog.storefront.title') }}
        </h1>
        <p class="mt-5 text-base leading-relaxed text-content-muted">
            {{ __('catalog.storefront.subtitle') }}
        </p>
    </div>

    @forelse ($groups as $group)
        <section class="mt-14" aria-labelledby="group-{{ $group['slug'] }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="group-{{ $group['slug'] }}" class="text-lg font-semibold tracking-tight">
                    {{ $group['name'] }}
                </h2>
                @if ($group['description'])
                    <p class="text-sm text-content-muted">{{ $group['description'] }}</p>
                @endif
            </div>

            {{-- Grid, not flex percentages: cards keep equal height and wrap
                 without arithmetic. --}}
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($group['products'] as $product)
                    <article class="flex flex-col rounded-[var(--radius-lg)] border border-line bg-surface-primary p-5 shadow-(--shadow-raised)">
                        <h3 class="text-base font-semibold tracking-tight">{{ $product['name'] }}</h3>

                        @if ($product['tagline'])
                            <p class="mt-1 text-sm leading-relaxed text-content-muted">{{ $product['tagline'] }}</p>
                        @endif

                        @if ($product['startingPrice'])
                            <p class="mt-5 flex items-baseline gap-1.5">
                                <span class="text-content-subtle text-xs">
                                    {{ __('catalog.storefront.starting_at') }}
                                </span>
                                <span class="text-2xl font-semibold tracking-tight tabular-nums">
                                    {{ $product['startingPrice']['amount'] }}
                                </span>
                                <span class="text-content-muted text-sm">
                                    {{ $product['startingPrice']['suffix'] }}
                                </span>
                            </p>
                        @endif

                        @if (! empty($product['features']))
                            <ul class="mt-4 space-y-1.5 text-sm text-content-muted">
                                @foreach (array_slice($product['features'], 0, 5) as $feature)
                                    <li class="flex gap-2">
                                        <span aria-hidden="true" class="text-content-subtle">&middot;</span>
                                        <span>{{ $feature }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-6 pt-1">
                            @if ($product['soldOut'])
                                <span class="inline-flex items-center rounded-[var(--radius-sm)] border border-line px-4 py-2 text-sm font-semibold text-content-subtle">
                                    {{ __('catalog.products.sold_out') }}
                                </span>
                            @else
                                <a
                                    href="{{ route('storefront.product', $product['slug']) }}"
                                    class="pressable inline-flex items-center rounded-[var(--radius-sm)] bg-brand px-4 py-2 text-sm font-semibold text-content-inverse shadow-(--shadow-raised) transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                                >
                                    {{ __('catalog.storefront.configure') }}
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <div class="mt-14 rounded-[var(--radius-lg)] border border-line bg-surface-primary p-6">
            <p class="text-sm font-medium">{{ __('catalog.storefront.empty') }}</p>
            <p class="mt-1 max-w-[52ch] text-sm leading-relaxed text-content-muted">
                {{ __('storefront.next_steps_body') }}
            </p>
        </div>
    @endforelse
@endsection
