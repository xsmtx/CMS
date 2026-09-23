{{-- The one mail layout. Every message this platform sends is a
     template row rendered into this.

     Signed with the brand the message goes out under, so a reseller's
     customer is never signed off by the provider behind it. --}}
<x-mail::message>
{!! nl2br(e($body)) !!}

@if ($actionUrl && $actionLabel)
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

{{ __('notifications.mail.signature', ['brand' => $brand?->name ?? config('app.name')]) }}

@if ($brand?->emailFooter)
<small>{{ $brand->emailFooter }}</small>
@endif
</x-mail::message>
