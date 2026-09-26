@extends('storefront::layout')

@section('title', __('billing.invoices.number') . ' ' . $invoice['number'] . ' — ' . $brand)
@section('robots', 'noindex')

@section('content')
    <div class="max-w-[64ch]">
        <h1 class="text-page leading-[1.1] font-semibold tracking-tighter sm:text-display">
            {{ __('billing.invoices.number') }} {{ $invoice['number'] }}
        </h1>

        <p class="mt-3 text-body text-content-muted">
            {{ $invoice['status'] }}
            @if ($invoice['isPastDue'])
                · <span class="text-danger">{{ __('billing.invoices.overdue_since', ['date' => $invoice['dueOn']]) }}</span>
            @elseif ($invoice['dueOn'])
                · {{ __('billing.invoices.due') }} {{ $invoice['dueOn'] }}
            @endif
        </p>

        @if ($errors->any())
            <div role="alert" class="mt-6 rounded-[var(--radius-sm)] border border-danger/30 bg-surface-secondary px-3 py-2 text-body text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        <ul class="mt-8 divide-y divide-line rounded-[var(--radius-xl)] border border-line bg-surface-primary">
            @foreach ($invoice['items'] as $item)
                <li class="flex items-start justify-between gap-4 px-5 py-3 text-body">
                    <span>
                        {{ $item['description'] }}
                        @if ($item['quantity'] > 1)
                            <span class="text-content-muted">× {{ $item['quantity'] }}</span>
                        @endif
                        @if ($item['detail'])
                            <span class="mt-0.5 block text-chrome text-content-muted whitespace-pre-line">{{ $item['detail'] }}</span>
                        @endif
                    </span>
                    <span class="tabular-nums whitespace-nowrap">{{ $item['lineAmount'] }}</span>
                </li>
            @endforeach
        </ul>

        <dl class="mt-4 divide-y divide-line text-body">
            <div class="flex justify-between py-2">
                <dt class="text-content-muted">{{ __('billing.invoices.total') }}</dt>
                <dd class="tabular-nums">{{ $invoice['total'] }}</dd>
            </div>
            <div class="flex justify-between py-2">
                <dt class="text-content-muted">{{ __('billing.invoices.paid') }}</dt>
                <dd class="tabular-nums">{{ $invoice['paid'] }}</dd>
            </div>
            <div class="flex justify-between py-3 text-title font-semibold">
                <dt>{{ __('billing.invoices.balance') }}</dt>
                <dd class="tabular-nums">{{ $invoice['balance'] }}</dd>
            </div>
        </dl>

        @if ($invoice['isOwed'] && ! empty($gateways))
            <section class="mt-8 rounded-[var(--radius-xl)] border border-line bg-surface-primary p-6">
                <h2 class="text-body font-semibold">{{ __('billing.payments.choose_method') }}</h2>

                <form method="POST" action="{{ route('storefront.invoice.pay', $invoice['number']) }}" class="mt-4 flex flex-col gap-3">
                    @csrf

                    @foreach ($gateways as $index => $gateway)
                        <label class="flex cursor-pointer items-start gap-3 rounded-[var(--radius-md)] border border-line px-4 py-3 text-body has-checked:border-brand">
                            <input
                                type="radio"
                                name="gateway"
                                value="{{ $gateway['value'] }}"
                                class="accent-brand mt-0.5 size-4"
                                @checked($index === 0)
                                required
                            >
                            <span>
                                {{ $gateway['label'] }}
                                @if ($gateway['instructions'])
                                    <span class="mt-1 block text-chrome leading-relaxed text-content-muted whitespace-pre-line">{{ $gateway['instructions'] }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach

                    <div class="mt-2">
                        <button
                            type="submit"
                            class="pressable inline-flex items-center rounded-[var(--radius-sm)] bg-brand px-5 py-2.5 text-body font-semibold text-content-inverse transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                        >
                            {{ __('billing.payments.pay_now') }} — {{ $invoice['balance'] }}
                        </button>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
