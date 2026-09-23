@extends('storefront::layout')

@section('title', __('ordering.checkout.thanks', ['number' => $order['number']]) . ' — ' . $brand)
@section('robots', 'noindex')

@section('content')
    <div class="max-w-[52ch]">
        <h1 class="text-3xl leading-[1.1] font-semibold tracking-tighter sm:text-4xl">
            {{ __('ordering.checkout.thanks', ['number' => $order['number']]) }}
        </h1>

        <dl class="mt-8 divide-y divide-line rounded-[var(--radius-lg)] border border-line bg-surface-raised px-5">
            <div class="flex justify-between gap-4 py-3 text-sm">
                <dt class="text-content-muted">{{ __('ordering.orders.number') }}</dt>
                <dd class="font-mono">{{ $order['number'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3 text-sm">
                <dt class="text-content-muted">{{ __('ordering.orders.status') }}</dt>
                <dd>{{ $order['status'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3 text-sm font-semibold">
                <dt>{{ __('ordering.cart.total') }}</dt>
                <dd class="tabular-nums">{{ $order['total'] }}</dd>
            </div>
            @if ($order['recurringTotal'])
                <div class="flex justify-between gap-4 py-3 text-sm">
                    <dt class="text-content-muted">{{ __('ordering.cart.recurring', ['amount' => '', 'suffix' => '']) }}</dt>
                    <dd class="tabular-nums text-content-muted">{{ $order['recurringTotal'] }}</dd>
                </div>
            @endif
        </dl>

        {{-- An account created at checkout has no password. It is reached
             through the reset flow, which also proves the address. --}}
        @if ($order['accountCreated'])
            <p class="mt-6 rounded-[var(--radius-sm)] border border-line bg-surface-sunken px-3 py-2 text-sm leading-relaxed">
                {{ __('ordering.checkout.verify_email') }}
            </p>
        @endif

        {{-- The invoice is raised as the order is placed, so the thing the
             customer most likely wants next is one click away. --}}
        @if ($invoice)
            <div class="mt-6 flex flex-wrap items-baseline justify-between gap-2 rounded-[var(--radius-sm)] border border-line bg-surface-sunken px-3 py-2 text-sm">
                <span>{{ __('billing.invoices.raised', ['number' => $invoice['number']]) }}</span>
                <span class="tabular-nums font-semibold">{{ $invoice['due'] }}</span>
            </div>
        @endif

        <p class="mt-6 text-sm leading-relaxed text-content-muted">
            {{ __('ordering.checkout.next_steps') }}
        </p>

        <div class="mt-8 flex flex-wrap gap-3">
            @if ($invoice)
                <a
                    href="{{ $invoice['url'] }}"
                    class="pressable inline-flex items-center rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised)"
                >
                    {{ __('billing.payments.pay_now') }}
                </a>
            @endif
            <a
                href="{{ url('/client') }}"
                class="pressable inline-flex items-center rounded-[var(--radius-sm)] {{ $invoice ? 'border border-line-strong' : 'bg-accent text-accent-content shadow-(--shadow-raised)' }} px-5 py-2.5 text-sm font-semibold"
            >
                {{ __('storefront.client_area') }}
            </a>
            <a
                href="{{ route('storefront.catalog') }}"
                class="pressable inline-flex items-center rounded-[var(--radius-sm)] border border-line-strong px-5 py-2.5 text-sm font-semibold"
            >
                {{ __('ordering.cart.continue') }}
            </a>
        </div>
    </div>
@endsection
