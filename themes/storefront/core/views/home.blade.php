{{--
    Core fallback storefront home.

    Two states, because an installation has two: one with nothing for sale
    yet, which says so and routes the people who can fix it, and one with a
    catalog, which points at it.

    Themes override this file through the precedence chain. Nothing here
    should be edited by an installation.
--}}
@extends('storefront::layout')

@section('title', $brand)

{{-- An installation with nothing for sale has nothing worth indexing. --}}
@section('robots', $hasCatalog ? 'index,follow' : 'noindex')

@section('content')
    {{--
        A hero tile and, where there is nothing for sale yet, a second tile
        that says what to do about it.

        Centred, because DESIGN.md's tiles are centred stacks - the product
        is the composition and the copy frames it. The console is
        left-aligned for the opposite reason: there, the eye starts at a
        column of data.
    --}}
    {{--
        "Each tile occupies roughly one viewport." A hero that stops at its
        own copy leaves the rest of the screen as an accident; one that fills
        the viewport makes the same emptiness the composition.

        `dvh`, not `vh`: on a phone the address bar moves and `vh` does not.
    --}}
    <section class="on-chrome flex min-h-[72dvh] items-center bg-surface-chrome px-6 py-20 text-center sm:py-24">
        <div class="mx-auto flex max-w-[680px] flex-col items-center">
            <h1 class="text-display text-balance font-semibold sm:text-hero">
                @if ($hasCatalog)
                    {{ __('catalog.storefront.title') }}
                @else
                    {{ __('storefront.headline', ['brand' => $brand]) }}
                @endif
            </h1>

            <p class="mt-4 max-w-[46ch] text-title font-normal text-content-muted">
                @if ($hasCatalog)
                    {{ __('catalog.storefront.subtitle') }}
                @else
                    {{ __('storefront.subheadline') }}
                @endif
            </p>

            <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                @if ($hasCatalog)
                    <a
                        href="{{ route('storefront.catalog') }}"
                        class="pressable inline-flex items-center rounded-full bg-brand px-6 py-3 text-title font-normal text-content-inverse transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                    >
                        {{ __('storefront.plans') }}
                    </a>
                    <a
                        href="{{ url('/client') }}"
                        class="pressable inline-flex items-center rounded-full border border-brand px-6 py-3 text-title font-normal text-brand transition-colors duration-(--duration-fast) hover:bg-brand hover:text-content-inverse"
                    >
                        {{ __('storefront.client_area') }}
                    </a>
                @else
                    <a
                        href="{{ url('/client') }}"
                        class="pressable inline-flex items-center rounded-full bg-brand px-6 py-3 text-title font-normal text-content-inverse transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                    >
                        {{ __('storefront.client_area') }}
                    </a>
                    <a
                        href="{{ url('/admin') }}"
                        class="pressable inline-flex items-center rounded-full border border-brand px-6 py-3 text-title font-normal text-brand transition-colors duration-(--duration-fast) hover:bg-brand hover:text-content-inverse"
                    >
                        {{ __('storefront.admin') }}
                    </a>
                @endif
            </div>

            {{--
                The product, resting on the tile, carrying the one shadow this
                system has. Absent until somebody puts a file in
                `public/storefront/`, and the tile is composed to read as a
                typographic hero when it is - a picture of a missing picture
                is worse than no picture.
            --}}
            @if (! empty($heroImage))
                <img
                    src="{{ $heroImage }}"
                    alt=""
                    width="2000"
                    height="1200"
                    fetchpriority="high"
                    class="product-shadow mt-14 w-full max-w-[52rem] object-contain"
                >
            @endif
        </div>
    </section>

    @unless ($hasCatalog)
        {{-- The second tile, on parchment: the alternation is the divider. --}}
        <section class="bg-background px-6 py-20 sm:py-24">
            <div class="mx-auto max-w-[680px] text-center">
                <h2 class="text-page font-semibold">{{ __('storefront.next_steps_title') }}</h2>
                <p class="mx-auto mt-3 max-w-[52ch] text-title font-normal text-content-muted">
                    {{ __('storefront.next_steps_body') }}
                </p>

                <ol class="mt-10 grid gap-4 text-left sm:grid-cols-3">
                    @foreach (__('storefront.next_steps') as $step)
                        {{-- `store-utility-card`: canvas, hairline, 18px, 24px padding. --}}
                        <li class="rounded-[var(--radius-xl)] border border-line bg-surface-primary p-6">
                            <span class="block text-title font-semibold">{{ $step['title'] }}</span>
                            <span class="mt-2 block text-body text-content-muted">{{ $step['body'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>
    @endunless

@endsection
