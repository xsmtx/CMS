{{--
    The public status page (§15).

    A banner, what is open, and the ninety days behind it. Nothing else: no
    impact figure, no hostnames, no operator's name. `PublicStatus` decides
    what may be published and the controller decides how it reads, so this
    file only prints — which is what a theme template is for.

    `noindex` on purpose. A status page that ranks for the company's own name
    puts "major outage" at the top of a search result for months after the
    outage ended, and a customer looking for the shop finds the worst day of
    the year instead.
--}}
@extends('storefront::layout')

@section('title', __('reliability.status_page.title') . ' — ' . $brand)
@section('robots', 'noindex,follow')

@section('content')
    <div class="mx-auto w-full max-w-[52rem] px-6 py-16">
        <h1 class="text-display font-semibold text-balance">
            {{ __('reliability.status_page.title') }}
        </h1>

        {{--
            The banner: a dot **and** a sentence, never a colour on its own.
            The same rule `AppStatus` enforces in the console, and it matters
            more here — a customer reading this on a phone in sunlight is the
            hardest reading condition this product has.
        --}}
        <div class="mt-8 flex items-center gap-3 border-t border-line pt-8">
            <span class="size-3 shrink-0 rounded-full {{ $level['dot'] }}" aria-hidden="true"></span>
            <p class="text-title font-semibold {{ $level['text'] }}">{{ $level['label'] }}</p>
        </div>

        <p class="mt-2 text-body text-content-muted">{{ $checkedAt }}</p>

        {{--
            Planned work, above the incidents. Somebody who has just noticed
            their site is slow wants to know whether it was announced before
            they read about what broke — and a status page that buried the
            answer under the history is one they ask support instead.
        --}}
        @if (! empty($maintenance))
            <section class="mt-14">
                <h2 class="text-title font-semibold">
                    {{ __('reliability.status_page.maintenance') }}
                </h2>

                @foreach ($maintenance as $window)
                    <article class="mt-6 border-t border-line pt-6 first:mt-4">
                        <h3 class="text-title font-semibold">{{ $window['title'] }}</h3>
                        <p class="mt-1 text-chrome text-content-subtle">
                            {{ $window['note'] }} · {{ $window['when'] }}
                        </p>
                        @if ($window['body'])
                            <p class="mt-3 max-w-[70ch] text-body whitespace-pre-line">{{ $window['body'] }}</p>
                        @endif
                    </article>
                @endforeach
            </section>
        @endif

        @if (! empty($open))
            <section class="mt-14">
                <h2 class="text-title font-semibold">
                    {{ __('reliability.status_page.happening_now') }}
                </h2>

                @foreach ($open as $incident)
                    @include('storefront::partials.status-incident', ['incident' => $incident])
                @endforeach
            </section>
        @endif

        <section class="mt-14">
            <h2 class="text-title font-semibold">
                {{ __('reliability.status_page.history') }}
            </h2>

            @forelse ($history as $incident)
                @include('storefront::partials.status-incident', ['incident' => $incident])
            @empty
                {{--
                    An empty history is a good ninety days, and it says so. A
                    blank space under a heading reads as a page that failed to
                    load the part that mattered.
                --}}
                <p class="mt-4 text-body text-content-muted">{{ $noHistory }}</p>
            @endforelse
        </section>
    </div>
@endsection
