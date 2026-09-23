@extends('storefront::layout')

@section('title', $article['title'] . ' — ' . $brand)

@section('content')
    <nav class="text-xs text-content-subtle" aria-label="Breadcrumb">
        <a href="{{ route('storefront.kb') }}" class="underline-offset-4 hover:underline">
            {{ __('support.kb.title') }}
        </a>
        @if ($article['category'])
            <span class="mx-1">/</span>{{ $article['category'] }}
        @endif
    </nav>

    <h1 class="mt-3 max-w-[30ch] text-3xl leading-[1.1] font-semibold tracking-tighter text-balance sm:text-4xl">
        {{ $article['title'] }}
    </h1>

    {{-- Escaped first, rendered second. Being written by a colleague is
         not a security property. --}}
    <div class="prose-article mt-8 max-w-[70ch] text-base leading-relaxed">
        {!! $article['body'] !!}
    </div>

    <div class="mt-12 max-w-[70ch] border-t border-line pt-6">
        <p class="text-sm font-semibold">{{ __('support.kb.helpful') }}</p>

        @if (session('status'))
            <p class="mt-2 text-sm text-content-muted">{{ session('status') }}</p>
        @else
            <div class="mt-3 flex gap-2">
                <form method="POST" action="{{ route('storefront.kb.rate', $article['slug']) }}">
                    @csrf
                    <input type="hidden" name="helpful" value="1">
                    <button type="submit" class="pressable rounded-[var(--radius-sm)] border border-line-strong px-4 py-2 text-sm font-semibold">
                        {{ __('support.kb.helpful_yes') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('storefront.kb.rate', $article['slug']) }}">
                    @csrf
                    <input type="hidden" name="helpful" value="0">
                    <button type="submit" class="pressable rounded-[var(--radius-sm)] border border-line-strong px-4 py-2 text-sm font-semibold">
                        {{ __('support.kb.helpful_no') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
@endsection
