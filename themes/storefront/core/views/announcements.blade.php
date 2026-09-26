@extends('storefront::layout')

@section('title', __('support.announcements.title') . ' — ' . $brand)

@section('content')
    <h1 class="text-display font-semibold text-balance">
        {{ __('support.announcements.title') }}
    </h1>

    @forelse ($announcements as $announcement)
        <article class="mt-10 max-w-[70ch] border-t border-line pt-6 first:border-t-0">
            <h2 class="text-title font-semibold">
                {{ $announcement['title'] }}
            </h2>
            <p class="mt-1 text-chrome text-content-subtle">
                {{ \Illuminate\Support\Carbon::parse($announcement['publishedAt'])->toFormattedDateString() }}
            </p>

            <div class="prose-article mt-4 text-title leading-relaxed">
                {!! $announcement['body'] !!}
            </div>
        </article>
    @empty
        <p class="mt-10 text-body text-content-muted">{{ __('support.announcements.none_public') }}</p>
    @endforelse
@endsection
