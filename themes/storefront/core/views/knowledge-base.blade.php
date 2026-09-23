@extends('storefront::layout')

@section('title', __('support.kb.title') . ' — ' . $brand)
@section('description', __('support.kb.subtitle'))

@section('content')
    <div class="max-w-[46ch]">
        <h1 class="text-4xl leading-[1.05] font-semibold tracking-tighter text-balance sm:text-5xl">
            {{ __('support.kb.title') }}
        </h1>
        <p class="mt-5 text-base leading-relaxed text-content-muted">
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
                class="min-w-0 flex-1 rounded-[var(--radius-sm)] border border-line bg-surface-primary px-3.5 py-2.5 text-base"
            >
            <button
                type="submit"
                class="pressable shrink-0 rounded-[var(--radius-sm)] bg-brand px-5 py-2.5 text-sm font-semibold text-content-inverse shadow-(--shadow-raised)"
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
                        <h2 class="text-base font-semibold tracking-tight underline-offset-4 group-hover:underline">
                            {{ $article['title'] }}
                        </h2>
                        @if ($article['category'])
                            <p class="mt-1 text-xs text-content-subtle">{{ $article['category'] }}</p>
                        @endif
                        <p class="mt-2 text-sm leading-relaxed text-content-muted">{{ $article['excerpt'] }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    @elseif ($query !== '')
        <div class="mt-10 max-w-xl">
            <p class="text-base font-semibold">{{ __('support.kb.no_results', ['query' => $query]) }}</p>
            <p class="mt-2 text-sm leading-relaxed text-content-muted">{{ __('support.kb.no_results_hint') }}</p>
        </div>
    @else
        <p class="mt-10 text-sm text-content-muted">{{ __('support.kb.empty') }}</p>
    @endif
@endsection
