@extends('storefront::layout')

@section('title', __('support.kb.title') . ' — ' . $brand)
@section('description', __('support.kb.subtitle'))

@section('content')
    <div class="max-w-[46ch]">
        <h1 class="text-display font-semibold text-balance">
            {{ __('support.kb.title') }}
        </h1>
        <p class="mt-5 text-title leading-relaxed text-content-muted">
            {{ __('support.kb.subtitle') }}
        </p>
    </div>

    <form method="GET" action="{{ route('storefront.kb') }}" class="mt-10 max-w-xl">
        <label for="q" class="sr-only">{{ __('support.kb.search') }}</label>
        <div class="flex gap-2">
            <input
                id="q"
                name="q"
                type="search"
                value="{{ $query }}"
                placeholder="{{ __('support.kb.search_placeholder') }}"
                class="min-w-0 flex-1 rounded-[var(--radius-sm)] border border-line bg-surface-primary px-3.5 py-2.5 text-title"
            >
            <button
                type="submit"
                class="pressable shrink-0 rounded-[var(--radius-sm)] bg-brand px-5 py-2.5 text-body font-semibold text-content-inverse"
            >
                {{ __('support.kb.search') }}
            </button>
        </div>
    </form>

    @if ($articles)
        <ul class="mt-10 divide-y divide-line border-y border-line">
            @foreach ($articles as $article)
                <li class="py-5">
                    <a href="{{ route('storefront.kb.article', $article['slug']) }}" class="group block">
                        <h2 class="text-title font-semibold tracking-tight underline-offset-4 group-hover:underline">
                            {{ $article['title'] }}
                        </h2>
                        @if ($article['category'])
                            <p class="mt-1 text-chrome text-content-subtle">{{ $article['category'] }}</p>
                        @endif
                        <p class="mt-2 text-body leading-relaxed text-content-muted">{{ $article['excerpt'] }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    @elseif ($query !== '')
        <div class="mt-10 max-w-xl">
            <p class="text-title font-semibold">{{ __('support.kb.no_results', ['query' => $query]) }}</p>
            <p class="mt-2 text-body leading-relaxed text-content-muted">{{ __('support.kb.no_results_hint') }}</p>
        </div>
    @else
        <p class="mt-10 text-body text-content-muted">{{ __('support.kb.empty') }}</p>
    @endif
@endsection
