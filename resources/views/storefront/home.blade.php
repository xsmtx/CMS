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
    <div class="grid gap-14 lg:grid-cols-12 lg:gap-16">
        <div class="lg:col-span-7">
            <h1 class="text-4xl leading-[1.05] font-semibold tracking-tighter text-balance sm:text-5xl lg:text-6xl">
                @if ($hasCatalog)
                    {{ __('catalog.storefront.title') }}
                @else
                    {{ __('storefront.headline', ['brand' => $brand]) }}
                @endif
            </h1>

            <p class="mt-6 max-w-[52ch] text-base leading-relaxed text-content-muted sm:text-lg">
                @if ($hasCatalog)
                    {{ __('catalog.storefront.subtitle') }}
                @else
                    {{ __('storefront.subheadline') }}
                @endif
            </p>

            <div class="mt-10 flex flex-wrap items-center gap-3">
                @if ($hasCatalog)
                    <a
                        href="{{ route('storefront.catalog') }}"
                        class="pressable inline-flex items-center rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised) transition-colors duration-(--duration-fast) hover:bg-accent-hover"
                    >
                        {{ __('storefront.plans') }}
                    </a>
                    <a
                        href="{{ url('/client') }}"
                        class="pressable inline-flex items-center rounded-[var(--radius-sm)] border border-line-strong px-5 py-2.5 text-sm font-semibold text-content transition-colors duration-(--duration-fast) hover:bg-surface-sunken"
                    >
                        {{ __('storefront.client_area') }}
                    </a>
                @else
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
                @endif
            </div>
        </div>

        @unless ($hasCatalog)
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
        @endunless
    </div>
@endsection
