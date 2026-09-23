@extends('storefront::layout')

@section('title', __('domains.search.title') . ' — ' . $brand)
@section('description', __('domains.search.description'))

@section('content')
    <div class="max-w-[46ch]">
        <h1 class="text-4xl leading-[1.05] font-semibold tracking-tighter text-balance sm:text-5xl">
            {{ __('domains.search.title') }}
        </h1>
        <p class="mt-5 text-base leading-relaxed text-content-muted">
            {{ __('domains.search.description') }}
        </p>
    </div>

    <form method="GET" action="{{ route('storefront.domains') }}" class="mt-10 max-w-xl">
        <div class="flex gap-2">
            <label for="q" class="sr-only">{{ __('domains.search.title') }}</label>
            <input
                id="q"
                name="q"
                type="text"
                value="{{ $query }}"
                placeholder="{{ __('domains.search.placeholder') }}"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                class="min-w-0 flex-1 rounded-[var(--radius-sm)] border border-line bg-surface-raised px-3.5 py-2.5 text-base"
            >
            <button
                type="submit"
                class="pressable shrink-0 rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised)"
            >
                {{ __('domains.search.submit') }}
            </button>
        </div>

        @if ($extensions)
            <p class="mt-3 text-xs text-content-subtle">{{ implode(' · ', $extensions) }}</p>
        @else
            <p class="mt-3 text-xs text-content-muted">{{ __('domains.search.none') }}</p>
        @endif
    </form>

    @if ($error)
        <p class="mt-6 max-w-xl rounded-[var(--radius-sm)] border border-line bg-surface-sunken px-3 py-2 text-sm">
            {{ $error }}
        </p>
    @endif

    @if (session('error'))
        <p class="mt-6 max-w-xl rounded-[var(--radius-sm)] border border-danger/30 bg-surface-sunken px-3 py-2 text-sm text-danger" role="alert">
            {{ session('error') }}
        </p>
    @endif

    @foreach ($offers as $offer)
        <div class="mt-8 max-w-xl rounded-[var(--radius-lg)] border border-line bg-surface-raised p-5 shadow-(--shadow-raised)">
            @if ($offer['available'])
                <p class="text-base font-semibold tracking-tight">
                    {{ __('domains.search.available', ['name' => $offer['name']]) }}
                </p>

                <div class="mt-4 flex flex-wrap items-baseline justify-between gap-3">
                    <p class="text-2xl font-semibold tracking-tight tabular-nums">
                        {{ $offer['price'] }}
                        <span class="text-sm font-normal text-content-muted">
                            {{ __('domains.search.per_years', ['years' => $offer['years']]) }}
                        </span>
                    </p>

                    <form method="POST" action="{{ route('storefront.domains.add') }}">
                        @csrf
                        <input type="hidden" name="domain" value="{{ $offer['name'] }}">
                        <input type="hidden" name="years" value="{{ $offer['years'] }}">
                        <button
                            type="submit"
                            class="pressable rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised)"
                        >
                            {{ __('domains.search.add') }}
                        </button>
                    </form>
                </div>

            {{-- Kept as its own answer all the way to the page. A customer
                 told "we could not check" tries again; one told "taken"
                 goes somewhere else. --}}
            @elseif ($offer['unknown'])
                <p class="text-base font-semibold tracking-tight">
                    {{ __('domains.search.unknown', ['name' => $offer['name']]) }}
                </p>
                <p class="mt-2 text-sm leading-relaxed text-content-muted">
                    {{ __('domains.search.unknown_hint') }}
                </p>

            @elseif (! $offer['sold'])
                <p class="text-base font-semibold tracking-tight">
                    {{ __('domains.search.not_sold', ['tld' => $offer['tld']]) }}
                </p>

            @else
                <p class="text-base font-semibold tracking-tight">
                    {{ __('domains.search.taken', ['name' => $offer['name']]) }}
                </p>

                @if ($offer['transferable'])
                    <p class="mt-2 text-sm leading-relaxed text-content-muted">
                        {{ __('domains.search.transfer_offer', ['price' => $offer['transferPrice']]) }}
                    </p>
                @endif
            @endif
        </div>
    @endforeach

    @if ($suggestions)
        <section class="mt-10 max-w-xl" aria-labelledby="suggestions">
            <h2 id="suggestions" class="text-sm font-semibold">{{ __('domains.search.suggestions') }}</h2>

            <ul class="mt-3 divide-y divide-line rounded-[var(--radius-lg)] border border-line bg-surface-raised">
                @foreach ($suggestions as $suggestion)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <span class="text-sm font-medium">
                            {{ $suggestion['name'] }}
                            @if ($suggestion['premium'])
                                <span class="ml-1.5 text-xs text-content-muted">{{ __('domains.search.premium') }}</span>
                            @endif
                        </span>

                        <span class="flex items-center gap-3">
                            <span class="text-sm tabular-nums">{{ $suggestion['price'] }}</span>
                            <form method="POST" action="{{ route('storefront.domains.add') }}">
                                @csrf
                                <input type="hidden" name="domain" value="{{ $suggestion['name'] }}">
                                <input type="hidden" name="years" value="{{ $suggestion['years'] }}">
                                <button
                                    type="submit"
                                    class="pressable rounded-[var(--radius-sm)] border border-line-strong px-3 py-1.5 text-xs font-semibold"
                                >
                                    {{ __('domains.search.add') }}
                                </button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
