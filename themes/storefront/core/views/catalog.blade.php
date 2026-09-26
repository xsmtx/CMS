@extends('storefront::layout')

@section('title', __('catalog.storefront.title') . ' — ' . $brand)
@section('description', __('catalog.storefront.subtitle'))

@section('content')
    {{--
        The store grid.

        A group is a tile and the tiles alternate canvas and parchment, which
        is what divides them: DESIGN.md draws no rule between sections and
        wraps no section in a frame, because the colour change already says
        where one ends.

        A product is a `store-utility-card` - canvas, one hairline, 18px
        corner, 24px padding - and the price is the one figure that gets the
        display size.
    --}}
    <section class="bg-surface-primary px-6 py-20 text-center sm:py-24">
        <div class="mx-auto max-w-[680px]">
            <h1 class="text-display text-balance font-semibold">
                {{ __('catalog.storefront.title') }}
            </h1>
            <p class="mt-4 text-title font-normal text-content-muted">
                {{ __('catalog.storefront.subtitle') }}
            </p>
        </div>
    </section>

    @forelse ($groups as $index => $group)
        <section
            class="{{ $index % 2 === 0 ? 'bg-background' : 'bg-surface-primary' }} px-6 py-16 sm:py-20"
            aria-labelledby="group-{{ $group['slug'] }}"
        >
            <div class="mx-auto w-full max-w-[1024px]">
                <div class="text-center">
                    <h2 id="group-{{ $group['slug'] }}" class="text-page font-semibold">
                        {{ $group['name'] }}
                    </h2>
                    @if ($group['description'])
                        <p class="mx-auto mt-2 max-w-[52ch] text-body text-content-muted">
                            {{ $group['description'] }}
                        </p>
                    @endif
                </div>

                {{--
                    `auto-fit` rather than a fixed column count, and centred:
                    a group with one product in a three-column grid puts that
                    product in the first column and leaves two thirds of the
                    tile empty, which reads as a page that failed to load
                    rather than as a shop with one plan.
                --}}
                <div class="mt-10 grid justify-center gap-5 [grid-template-columns:repeat(auto-fit,minmax(17rem,21rem))]">
                    @foreach ($group['products'] as $product)
                        {{-- Centred, like every tile on the page: the card is
                             a small composition rather than a row of facts. --}}
                        <article class="flex flex-col rounded-[var(--radius-xl)] border border-line bg-surface-primary p-8 text-center">
                            {{-- A square crop at the top of the card, when the
                                 installation has one. `public/storefront/products/<slug>.png`. --}}
                            @if (! empty($product['image']))
                                <img
                                    src="{{ $product['image'] }}"
                                    alt=""
                                    width="800"
                                    height="800"
                                    loading="lazy"
                                    class="mb-6 aspect-square w-full rounded-[var(--radius-control)] object-contain"
                                >
                            @endif

                            <h3 class="text-title font-semibold">{{ $product['name'] }}</h3>

                            @if ($product['tagline'])
                                <p class="mt-1.5 text-body text-content-muted">{{ $product['tagline'] }}</p>
                            @endif

                            @if ($product['startingPrice'])
                                <p class="mt-6 flex items-baseline justify-center gap-1.5">
                                    <span class="text-content-subtle text-chrome">
                                        {{ __('catalog.storefront.starting_at') }}
                                    </span>
                                    <span class="text-page font-semibold tabular-nums">
                                        {{ $product['startingPrice']['amount'] }}
                                    </span>
                                    <span class="text-content-muted text-body">
                                        {{ $product['startingPrice']['suffix'] }}
                                    </span>
                                </p>
                            @endif

                            @if (! empty($product['features']))
                                {{-- No bullet marks: a centred list with
                                     leading dots reads as ragged on both
                                     edges at once. --}}
                                <ul class="mt-6 space-y-1.5 text-body text-content-muted">
                                    @foreach (array_slice($product['features'], 0, 5) as $feature)
                                        <li>{{ $feature }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Pushed to the bottom so every card in a row
                                 puts its action on the same line, whatever
                                 the copy above it did. --}}
                            <div class="mt-auto pt-8">
                                @if ($product['soldOut'])
                                    <span class="inline-flex items-center rounded-full border border-line px-5 py-2.5 text-body text-content-subtle">
                                        {{ __('catalog.products.sold_out') }}
                                    </span>
                                @else
                                    <a
                                        href="{{ route('storefront.product', $product['slug']) }}"
                                        class="pressable inline-flex items-center rounded-full bg-brand px-5 py-2.5 text-body text-content-inverse transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                                    >
                                        {{ __('catalog.storefront.configure') }}
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @empty
        <section class="bg-background px-6 py-20 text-center sm:py-24">
            <div class="mx-auto max-w-[52ch]">
                <p class="text-title font-semibold">{{ __('catalog.storefront.empty') }}</p>
                <p class="mt-2 text-body text-content-muted">
                    {{ __('storefront.next_steps_body') }}
                </p>
            </div>
        </section>
    @endforelse
@endsection
