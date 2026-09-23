@extends('storefront::layout')

@section('title', __('ordering.cart.title') . ' — ' . $brand)
@section('robots', 'noindex')

@section('content')
    <h1 class="text-3xl leading-[1.1] font-semibold tracking-tighter sm:text-4xl">
        {{ __('ordering.cart.title') }}
    </h1>

    @if (session('status'))
        <p role="status" class="mt-5 rounded-[var(--radius-sm)] border border-line bg-surface-sunken px-3 py-2 text-sm">
            {{ session('status') }}
        </p>
    @endif

    @if ($cart === null || $cart['empty'])
        <div class="mt-8 rounded-[var(--radius-lg)] border border-line bg-surface-raised p-6">
            <p class="text-sm font-medium">{{ __('ordering.cart.empty') }}</p>
            <p class="mt-1 text-sm text-content-muted">{{ __('ordering.cart.empty_hint') }}</p>

            <a
                href="{{ route('storefront.catalog') }}"
                class="pressable mt-5 inline-flex items-center rounded-[var(--radius-sm)] bg-accent px-4 py-2 text-sm font-semibold text-accent-content"
            >
                {{ __('ordering.cart.continue') }}
            </a>
        </div>
    @else
        <div class="mt-8 grid gap-10 lg:grid-cols-12 lg:gap-14">
            <div class="lg:col-span-7">
                <ul class="divide-y divide-line rounded-[var(--radius-lg)] border border-line bg-surface-raised">
                    @foreach ($cart['lines'] as $line)
                        <li class="px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium">{{ $line['name'] }}</p>
                                    <p class="mt-0.5 text-xs text-content-muted">
                                        @if ($line['cycleLabel']){{ $line['cycleLabel'] }}@endif
                                        @if ($line['domain']) · {{ $line['domain'] }}@endif
                                    </p>

                                    @foreach ($line['options'] as $option)
                                        <p class="mt-1 text-xs text-content-muted">
                                            {{ $option['group'] }}: {{ $option['label'] }}
                                            @if ($option['amount'])
                                                <span class="tabular-nums">({{ $option['amount'] }})</span>
                                            @endif
                                        </p>
                                    @endforeach

                                    @foreach ($cart['addonLines'] as $addon)
                                        @if ($addon['parentId'] === $line['id'])
                                            <p class="mt-1 text-xs text-content-muted">
                                                + {{ $addon['name'] }}
                                                <span class="tabular-nums">{{ $addon['lineTotal'] }}</span>
                                            </p>
                                        @endif
                                    @endforeach
                                </div>

                                <div class="text-right whitespace-nowrap">
                                    <p class="text-sm font-semibold tabular-nums">{{ $line['lineTotal'] }}</p>
                                    @if ($line['lineSetup'])
                                        <p class="text-xs text-content-subtle">
                                            {{ __('catalog.storefront.setup_fee', ['amount' => $line['lineSetup']]) }}
                                        </p>
                                    @endif
                                    @if ($line['lineDiscount'])
                                        <p class="text-xs text-success">−{{ $line['lineDiscount'] }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3 flex items-center gap-4">
                                <form method="POST" action="{{ route('storefront.cart.update', $line['id']) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <label class="sr-only" for="qty-{{ $line['id'] }}">{{ __('ordering.cart.quantity') }}</label>
                                    <input
                                        id="qty-{{ $line['id'] }}"
                                        name="quantity"
                                        type="number"
                                        value="{{ $line['quantity'] }}"
                                        min="1"
                                        max="100"
                                        class="w-20 rounded-[var(--radius-sm)] border border-line bg-surface px-2 py-1 text-sm tabular-nums"
                                    >
                                    <button type="submit" class="pressable text-xs text-content-muted underline underline-offset-4 hover:text-content">
                                        {{ __('ordering.cart.update_line') }}
                                    </button>
                                </form>

                                {{-- A DELETE, not a link: a crawler must not
                                     be able to empty anyone's basket. --}}
                                <form method="POST" action="{{ route('storefront.cart.remove', $line['id']) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="pressable text-xs text-danger underline underline-offset-4">
                                        {{ __('ordering.cart.remove') }}
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <a
                    href="{{ route('storefront.catalog') }}"
                    class="mt-4 inline-block text-sm text-content-muted underline underline-offset-4 hover:text-content"
                >
                    {{ __('ordering.cart.continue') }}
                </a>
            </div>

            <div class="lg:col-span-5">
                <div class="rounded-[var(--radius-lg)] border border-line bg-surface-raised p-6 shadow-(--shadow-panel)">
                    <dl class="divide-y divide-line text-sm">
                        <div class="flex justify-between py-2 first:pt-0">
                            <dt class="text-content-muted">{{ __('ordering.cart.subtotal') }}</dt>
                            <dd class="tabular-nums">{{ $cart['subtotal'] }}</dd>
                        </div>

                        @if ($cart['setup'])
                            <div class="flex justify-between py-2">
                                <dt class="text-content-muted">{{ __('ordering.cart.setup') }}</dt>
                                <dd class="tabular-nums">{{ $cart['setup'] }}</dd>
                            </div>
                        @endif

                        @if ($cart['discount'])
                            <div class="flex justify-between py-2">
                                <dt class="text-content-muted">
                                    {{ __('ordering.cart.discount') }}
                                    <span class="font-mono text-xs">{{ $cart['promotionCode'] }}</span>
                                </dt>
                                <dd class="tabular-nums text-success">−{{ $cart['discount'] }}</dd>
                            </div>
                        @endif

                        @if ($cart['tax'])
                            <div class="flex justify-between py-2">
                                <dt class="text-content-muted">{{ $cart['taxName'] ?? __('ordering.cart.tax') }}</dt>
                                <dd class="tabular-nums">{{ $cart['tax'] }}</dd>
                            </div>
                        @endif

                        <div class="flex justify-between py-3 text-base font-semibold">
                            <dt>{{ __('ordering.cart.total') }}</dt>
                            <dd class="tabular-nums">{{ $cart['total'] }}</dd>
                        </div>
                    </dl>

                    @if ($cart['recurringTotal'])
                        <p class="text-xs text-content-muted">
                            {{ __('ordering.cart.recurring', ['amount' => $cart['recurringTotal'], 'suffix' => '']) }}
                        </p>
                    @endif

                    <form method="POST" action="{{ route('storefront.cart.code') }}" class="mt-5 flex flex-col gap-2">
                        @csrf
                        <label for="code" class="text-xs font-medium text-content-muted">
                            {{ __('ordering.cart.promo_code') }}
                        </label>
                        <div class="flex gap-2">
                            <input
                                id="code"
                                name="code"
                                type="text"
                                value="{{ $cart['promotionCode'] }}"
                                autocomplete="off"
                                class="w-full rounded-[var(--radius-sm)] border border-line bg-surface px-3 py-2 font-mono text-sm uppercase"
                            >
                            <button
                                type="submit"
                                class="pressable shrink-0 rounded-[var(--radius-sm)] border border-line-strong px-3 py-2 text-sm font-semibold"
                            >
                                {{ __('ordering.cart.promo_apply') }}
                            </button>
                        </div>

                        @error('code')
                            <p class="text-xs text-danger">{{ $message }}</p>
                        @enderror

                        @if ($cart['promotionRefusal'])
                            <p class="text-xs text-danger">{{ $cart['promotionRefusal'] }}</p>
                        @endif
                    </form>

                    <a
                        href="{{ route('storefront.checkout') }}"
                        class="pressable mt-6 inline-flex w-full items-center justify-center rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised) transition-colors duration-(--duration-fast) hover:bg-accent-hover"
                    >
                        {{ __('ordering.cart.checkout') }}
                    </a>
                </div>
            </div>
        </div>
    @endif
@endsection
