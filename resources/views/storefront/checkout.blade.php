@extends('storefront::layout')

@section('title', __('ordering.checkout.title') . ' — ' . $brand)
@section('robots', 'noindex')

@section('content')
    <h1 class="text-3xl leading-[1.1] font-semibold tracking-tighter sm:text-4xl">
        {{ __('ordering.checkout.title') }}
    </h1>

    @if ($errors->any())
        <div role="alert" class="mt-5 rounded-[var(--radius-sm)] border border-danger/30 bg-surface-sunken px-3 py-2 text-sm text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('storefront.checkout.store') }}" class="mt-8 grid gap-10 lg:grid-cols-12 lg:gap-14">
        @csrf

        {{-- What the customer was shown. Placement re-prices the cart and
             refuses if this no longer matches, because a price that moved
             underneath someone is a conversation, not a silent charge. --}}
        <input type="hidden" name="expected_total" value="{{ $cart['totalMinor'] }}">

        <div class="lg:col-span-7">
            <h2 class="text-sm font-semibold">{{ __('ordering.checkout.account') }}</h2>

            @if ($contact)
                <div class="mt-3 rounded-[var(--radius-md)] border border-line bg-surface-raised px-4 py-3 text-sm">
                    <p class="font-medium">{{ $contact['name'] }}</p>
                    <p class="text-content-muted">{{ $contact['email'] }}</p>
                    @if ($contact['company'])
                        <p class="text-content-muted">{{ $contact['company'] }}</p>
                    @endif
                </div>
            @else
                <p class="mt-1 text-sm text-content-muted">
                    {{ __('ordering.checkout.existing') }}
                    <a href="{{ url('/login') }}" class="underline underline-offset-4">
                        {{ __('ordering.checkout.sign_in') }}
                    </a>
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['first_name', __('ordering.checkout.first_name'), 'given-name', true],
                        ['last_name', __('ordering.checkout.last_name'), 'family-name', true],
                        ['email', __('ordering.checkout.email'), 'email', true],
                        ['phone', __('ordering.checkout.phone'), 'tel', false],
                        ['company', __('ordering.checkout.company'), 'organization', false],
                        ['country_code', __('ordering.checkout.country'), 'country', false],
                    ] as [$field, $label, $autocomplete, $required])
                        <div class="flex flex-col gap-2">
                            <label for="{{ $field }}" class="text-sm font-medium">
                                {{ $label }}
                                @if ($required)<span class="text-content-subtle" aria-hidden="true">*</span>@endif
                            </label>
                            <input
                                id="{{ $field }}"
                                name="{{ $field }}"
                                type="{{ $field === 'email' ? 'email' : 'text' }}"
                                autocomplete="{{ $autocomplete }}"
                                value="{{ old($field) }}"
                                @required($required)
                                class="w-full rounded-[var(--radius-sm)] border border-line bg-surface-raised px-3 py-2 text-sm"
                            >
                            @error($field)
                                <p class="text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-8 flex flex-col gap-3">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="terms" value="1" class="accent-accent mt-0.5 size-4" required>
                    <span>
                        {{ __('ordering.checkout.terms') }}
                        <span class="mt-0.5 block text-xs text-content-subtle">
                            Version {{ $termsVersion }}
                        </span>
                    </span>
                </label>

                @unless ($contact)
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="marketing_opt_in" value="1" class="accent-accent mt-0.5 size-4">
                        <span class="text-content-muted">Send me occasional product news.</span>
                    </label>
                @endunless
            </div>
        </div>

        <div class="lg:col-span-5">
            <div class="rounded-[var(--radius-lg)] border border-line bg-surface-raised p-6 shadow-(--shadow-panel) lg:sticky lg:top-8">
                <h2 class="text-sm font-semibold">{{ __('ordering.checkout.summary') }}</h2>

                <ul class="mt-4 divide-y divide-line">
                    @foreach ($cart['lines'] as $line)
                        <li class="flex items-start justify-between gap-4 py-2.5 text-sm">
                            <span>
                                {{ $line['name'] }}
                                @if ($line['quantity'] > 1)
                                    <span class="text-content-muted">× {{ $line['quantity'] }}</span>
                                @endif
                                @if ($line['cycleLabel'])
                                    <span class="mt-0.5 block text-xs text-content-muted">{{ $line['cycleLabel'] }}</span>
                                @endif
                            </span>
                            <span class="tabular-nums whitespace-nowrap">{{ $line['lineTotal'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="mt-2 divide-y divide-line border-t border-line text-sm">
                    @if ($cart['discount'])
                        <div class="flex justify-between py-2">
                            <dt class="text-content-muted">{{ __('ordering.cart.discount') }}</dt>
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

                <button
                    type="submit"
                    class="pressable mt-6 inline-flex w-full items-center justify-center rounded-[var(--radius-sm)] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-content shadow-(--shadow-raised) transition-colors duration-(--duration-fast) hover:bg-accent-hover"
                >
                    {{ __('ordering.checkout.place_order') }}
                </button>

                <p class="mt-3 text-xs text-content-subtle">
                    {{ __('ordering.checkout.next_steps') }}
                </p>
            </div>
        </div>
    </form>
@endsection
