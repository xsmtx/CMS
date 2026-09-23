@extends('storefront::layout')

@section('title', __('support.announcements.title') . ' — ' . $brand)

@section('content')
    <h1 class="text-4xl leading-[1.05] font-semibold tracking-tighter text-balance sm:text-5xl">
        {{ __('support.announcements.title') }}
    </h1>

    @forelse ($announcements as $announcement)
        <article class="mt-10 max-w-[70ch] border-t border-line pt-6 first:border-t-0">
            <h2 class="text-lg font-semibold tracking-tight">
                {{ $announcement['title'] }}
            </h2>
            <p class="mt-1 text-xs text-content-subtle">
                {{ \Illuminate\Support\Carbon::parse($announcement['publishedAt'])->toFormattedDateString() }}
            </p>

            <div class="prose-article mt-4 text-base leading-relaxed">
                {!! $announcement['body'] !!}
            </div>
        </article>
    @empty
        <p class="mt-10 text-sm text-content-muted">{{ __('support.announcements.none_public') }}</p>
    @endforelse
@endsection
