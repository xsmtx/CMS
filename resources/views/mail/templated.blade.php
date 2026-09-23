{{-- The one mail layout. Every message this platform sends is a
     template row rendered into this. --}}
<x-mail::message>
{!! nl2br(e($body)) !!}

@if ($actionUrl && $actionLabel)
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

{{ __('notifications.mail.signature', ['brand' => config('app.name')]) }}
</x-mail::message>
