@extends('storefront::layout')

@section('title', __('ordering.configure.title', ['product' => $product['name']]) . ' — ' . $brand)

@section('content')
    <nav aria-label="{{ __('storefront.primary_navigation') }}" class="text-xs text-content-subtle">
        <a href="{{ route('storefront.catalog') }}" class="underline underline-offset-4 hover:text-content-muted">
            {{ __('catalog.storefront.title') }}
        </a>
        <span aria-hidden="true" class="mx-1.5">/</span>
        <a href="{{ route('storefront.product', $product['slug']) }}" class="underline underline-offset-4 hover:text-content-muted">
            {{ $product['name'] }}
        </a>
    </nav>

    <h1 class="mt-4 text-3xl leading-[1.1] font-semibold tracking-tighter text-balance sm:text-4xl">
        {{ __('ordering.configure.title', ['product' => $product['name']]) }}
    </h1>

    @if ($errors->any())
        <div role="alert" class="mt-6 rounded-[var(--radius-sm)] border border-danger/30 bg-surface-secondary px-3 py-2 text-sm text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('storefront.cart.store') }}" class="mt-8 grid gap-10 lg:grid-cols-12 lg:gap-14">
        @csrf
        <input type="hidden" name="product_id" value="{{ $product['id'] }}">

        <div class="flex flex-col gap-8 lg:col-span-7">
            <fieldset>
                <legend class="text-sm font-semibold">{{ __('ordering.configure.cycle') }}</legend>

                {{-- Every cycle is priced up front, so comparing them needs
                     no round trip and the number compared is the number
                     charged. --}}
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($product['cycles'] as $index => $cycle)
                        <label class="flex cursor-pointer items-center justify-between gap-3 rounded-[var(--radius-md)] border border-line bg-surface-primary px-4 py-3 text-sm has-checked:border-brand">
                            <span class="flex items-center gap-3">
                                <input
                                    type="radio"
                                    name="billing_cycle"
                                    value="{{ $cycle['cycle'] }}"
                                    class="accent-brand size-4"
                                    @checked($index === 0)
                                    required
                                >
                                {{ $cycle['cycleLabel'] }}
                            </span>
                            <span class="text-right">
                                <span class="font-semibold tabular-nums">{{ $cycle['amount'] }}</span>
                                @if ($cycle['setup'])
                                    <span class="mt-0.5 block text-xs text-content-subtle">
                                        {{ __('catalog.storefront.setup_fee', ['amount' => $cycle['setup']]) }}
                                    </span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if ($product['requiresDomain'])
                <div class="flex flex-col gap-2">
                    <label for="domain" class="text-sm font-semibold">{{ __('ordering.configure.domain') }}</label>
                    <input
                        id="domain"
                        name="domain"
                        type="text"
                        inputmode="url"
                        autocomplete="off"
                        placeholder="example.com"
                        value="{{ old('domain') }}"
                        required
                        class="w-full rounded-[var(--radius-sm)] border border-line bg-surface-primary px-3 py-2 text-sm"
                    >
                    <p class="text-xs text-content-muted">{{ __('ordering.configure.domain_hint') }}</p>
                </div>
            @endif

            @foreach ($product['optionGroups'] as $group)
                <fieldset>
                    <legend class="text-sm font-semibold">
                        {{ $group['name'] }}
                        @unless ($group['isRequired'])
                            <span class="ml-1.5 text-xs font-normal text-content-subtle">optional</span>
                        @endunless
                    </legend>

                    @if ($group['description'])
                        <p class="mt-1 text-sm text-content-muted">{{ $group['description'] }}</p>
                    @endif

                    @if ($group['type'] === 'quantity')
                        <input
                            type="number"
                            name="options[{{ $group['id'] }}][quantity]"
                            value="{{ $group['minQuantity'] }}"
                            min="{{ $group['minQuantity'] }}"
                            @if ($group['maxQuantity']) max="{{ $group['maxQuantity'] }}" @endif
                            class="mt-3 w-28 rounded-[var(--radius-sm)] border border-line bg-surface-primary px-3 py-2 text-sm tabular-nums"
                        >
                    @else
                        <div class="mt-3 divide-y divide-line rounded-[var(--radius-md)] border border-line">
                            @foreach ($group['options'] as $option)
                                <label class="flex cursor-pointer items-center justify-between gap-4 px-4 py-2.5 text-sm">
                                    <span class="flex items-center gap-3">
                                        <input
                                            type="radio"
                                            name="options[{{ $group['id'] }}][option_id]"
                                            value="{{ $option['id'] }}"
                                            class="accent-brand size-4"
                                            @checked($option['isDefault'])
                                            @required($group['isRequired'])
                                        >
                                        {{ $option['label'] }}
                                    </span>
                                    <span class="text-content-muted tabular-nums">{{ $option['delta'] ?? '—' }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
            @endforeach

            @if (! empty($product['addons']))
                <fieldset>
                    <legend class="text-sm font-semibold">{{ __('ordering.configure.addons') }}</legend>

                    <div class="mt-3 divide-y divide-line rounded-[var(--radius-md)] border border-line">
                        @foreach ($product['addons'] as $addon)
                            <label class="flex cursor-pointer items-start justify-between gap-4 px-4 py-3 text-sm">
                                <span class="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        name="addons[]"
                                        value="{{ $addon['id'] }}"
                                        class="accent-brand mt-0.5 size-4"
                                    >
                                    <span>
                                        {{ $addon['name'] }}
                                        @if ($addon['description'])
                                            <span class="mt-0.5 block text-xs text-content-muted">{{ $addon['description'] }}</span>
                                        @endif
                                    </span>
                                </span>
                                <span class="whitespace-nowrap text-content-muted tabular-nums">{{ $addon['price'] ?? '—' }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif
        </div>

        <div class="lg:col-span-5">
            <div class="rounded-[var(--radius-lg)] border border-line bg-surface-primary p-6 shadow-(--shadow-panel) lg:sticky lg:top-8">
                <h2 class="text-sm font-semibold">{{ $product['name'] }}</h2>

                @if ($product['tagline'])
                    <p class="mt-1 text-sm leading-relaxed text-content-muted">{{ $product['tagline'] }}</p>
                @endif

                <div class="mt-5 flex flex-col gap-2">
                    <label for="quantity" class="text-xs font-medium text-content-muted">
                        {{ __('ordering.cart.quantity') }}
                    </label>
                    <input
                        id="quantity"
                        name="quantity"
                        type="number"
                        value="1"
                        min="1"
                        max="100"
                        class="w-24 rounded-[var(--radius-sm)] border border-line bg-background px-3 py-2 text-sm tabular-nums"
                    >
                </div>

                <button
                    type="submit"
                    class="pressable mt-6 inline-flex w-full items-center justify-center rounded-[var(--radius-sm)] bg-brand px-5 py-2.5 text-sm font-semibold text-content-inverse shadow-(--shadow-raised) transition-colors duration-(--duration-fast) hover:bg-brand-hover"
                >
                    {{ __('ordering.configure.add_to_cart') }}
                </button>

                <p class="mt-3 text-xs text-content-subtle">
                    {{ __('catalog.pricing.subtitle') }}
                </p>
            </div>
        </div>
    </form>
@endsection
