<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Catalog\CurrencyAttributes;
use App\Application\Catalog\DeleteCurrency;
use App\Application\Catalog\SaveCurrency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CurrencyRequest;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Shared\Models\ExchangeRateSnapshot;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CurrencyController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $this->authorize('viewAny', CurrencyRecord::class);

        return Inertia::render('Admin/Catalog/Currencies/Index', [
            'currencies' => CurrencyRecord::query()
                ->orderByDesc('is_base')
                ->orderBy('code')
                ->get()
                ->map(fn (CurrencyRecord $record): array => [
                    'id' => $record->id,
                    'code' => $record->code,
                    'name' => $record->name,
                    'symbol' => $record->symbol,
                    'exponent' => $record->exponent,
                    'rate' => $record->rate,
                    'isBase' => $record->is_base,
                    'isActive' => $record->is_active,
                ])
                ->values()
                ->all(),
            'canManage' => $this->actor->can('create', CurrencyRecord::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CurrencyRecord::class);

        return Inertia::render('Admin/Catalog/Currencies/Form', [
            'currency' => null,
            'history' => [],
        ]);
    }

    public function store(CurrencyRequest $request, SaveCurrency $save): RedirectResponse
    {
        $this->authorize('create', CurrencyRecord::class);

        $save->handle(
            (string) $this->actor->organizationId(),
            $this->attributes($request),
            null,
            $this->actor->model(),
        );

        return to_route('admin.catalog.currencies.index')->with('status', __('catalog.currencies.saved'));
    }

    public function edit(CurrencyRecord $currency): Response
    {
        $this->authorize('view', $currency);

        return Inertia::render('Admin/Catalog/Currencies/Form', [
            'currency' => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'exponent' => $currency->exponent,
                'rate' => $currency->rate,
                'isBase' => $currency->is_base,
                'isActive' => $currency->is_active,
            ],
            // The history is what makes a rate change explainable after the
            // fact, so it sits on the same screen as the field that changes it.
            'history' => $currency->snapshots()
                ->limit(25)
                ->get()
                ->map(fn (ExchangeRateSnapshot $snapshot): array => [
                    'rate' => $snapshot->rate,
                    'source' => $snapshot->source,
                    'capturedAt' => $snapshot->captured_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function update(CurrencyRequest $request, CurrencyRecord $currency, SaveCurrency $save): RedirectResponse
    {
        $this->authorize('update', $currency);

        $save->handle($currency->organization_id, $this->attributes($request), $currency, $this->actor->model());

        return to_route('admin.catalog.currencies.index')->with('status', __('catalog.currencies.saved'));
    }

    public function destroy(CurrencyRecord $currency, DeleteCurrency $delete): RedirectResponse
    {
        $this->authorize('delete', $currency);

        $delete->handle($currency, $this->actor->model());

        return to_route('admin.catalog.currencies.index')->with('status', __('catalog.currencies.deleted'));
    }

    private function attributes(CurrencyRequest $request): CurrencyAttributes
    {
        return new CurrencyAttributes(
            code: $request->string('code')->upper()->toString(),
            name: $request->string('name')->toString(),
            symbol: $request->input('symbol'),
            rate: $request->string('rate')->toString(),
            isBase: $request->boolean('is_base'),
            isActive: $request->boolean('is_active'),
        );
    }
}
