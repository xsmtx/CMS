{{--
    Where a gateway sends the customer back to.

    It confirms nothing. Whatever the query string claims, this page shows
    only what a verified webhook has already recorded — a redirect is not
    proof that money moved.
--}}
@extends('storefront::layout')

@section('title', __('billing.invoices.number') . ' ' . $invoice['number'] . ' — ' . $brand)
@section('robots', 'noindex')

@section('content')
    <div class="max-w-[52ch]">
        <h1 class="text-3xl leading-[1.1] font-semibold tracking-tighter sm:text-4xl">
            {{ __('billing.invoices.number') }} {{ $invoice['number'] }}
        </h1>

        @if ($invoice['isOwed'])
            <p class="mt-6 rounded-[var(--radius-sm)] border border-line bg-surface-sunken px-3 py-2 text-sm leading-relaxed">
                {{ __('billing.payments.checking') }}
            </p>
        @else
            <p class="mt-6 text-base leading-relaxed text-content-muted">
                {{ $invoice['status'] }} — {{ $invoice['total'] }}
            </p>
        @endif

        <dl class="mt-8 divide-y divide-line rounded-[var(--radius-lg)] border border-line bg-surface-raised px-5">
            <div class="flex justify-between gap-4 py-3 text-sm">
                <dt class="text-content-muted">{{ __('billing.invoices.total') }}</dt>
                <dd class="tabular-nums">{{ $invoice['total'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3 text-sm">
                <dt class="text-content-muted">{{ __('billing.invoices.paid') }}</dt>
                <dd class="tabular-nums">{{ $invoice['paid'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3 text-sm font-semibold">
                <dt>{{ __('billing.invoices.balance') }}</dt>
                <dd class="tabular-nums">{{ $invoice['balance'] }}</dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap gap-3">
            <a
                href="{{ route('storefront.invoice', $invoice['number']) }}"
                class="pressable inline-flex items-center rounded-[var(--radius-sm)] border border-line-strong px-5 py-2.5 text-sm font-semibold"
            >
                {{ __('billing.invoices.number') }}
            </a>
            <a
                href="{{ url('/client') }}"
                class="pressable inline-flex items-center rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content"
            >
                {{ __('storefront.client_area') }}
            </a>
        </div>
    </div>
@endsection
