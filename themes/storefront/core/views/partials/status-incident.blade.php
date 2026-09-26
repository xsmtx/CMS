{{--
    One incident on the status page: what it is called, when it ran, and every
    update somebody chose to publish.

    Newest update first, which is what a customer refreshing the page wants —
    the latest sentence at the top and the story underneath it. A timeline in
    the order it was written makes them scroll to find out whether anything
    has changed since they last looked.
--}}
<article class="mt-6 border-t border-line pt-6 first:mt-4">
    {{--
        No state beside the title. It would say what the newest update
        underneath already says, word for word — `note()` moves both at once,
        so the two can never differ — and the section heading above has
        already said whether this is happening now or is over. The state that
        earns its place is the one on each update, because that is the part
        that changed.
    --}}
    <h3 class="text-title font-semibold">{{ $incident['title'] }}</h3>

    <p class="mt-1 text-chrome text-content-subtle">{{ $incident['when'] }}</p>

    @if (! empty($incident['updates']))
        <ol class="mt-4 space-y-4">
            @foreach ($incident['updates'] as $update)
                <li>
                    <p class="text-chrome text-content-subtle">
                        <span class="font-medium text-content-muted">{{ $update['stateLabel'] }}</span>
                        · {{ $update['writtenAt'] }}
                    </p>
                    <p class="mt-1 max-w-[70ch] text-body whitespace-pre-line">{{ $update['body'] }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</article>
