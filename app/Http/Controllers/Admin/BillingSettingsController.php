<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\BillingSettings;
use App\Application\Billing\SaveBillingSettings;
use App\Application\Shared\ResolveSeller;
use App\Application\Shared\SaveNumberSequence;
use App\Domain\Shared\NumberResetPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingSettingsRequest;
use App\Infrastructure\Shared\Models\NumberSequence;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing terms, and what a document number looks like.
 *
 * Owner only, alongside Tax and for the same reason: `due_days` decides when
 * dunning starts taking somebody's services away, `late_fee_rate_ppm` is money
 * charged to a customer, and the numbering rows can produce a duplicate invoice
 * number — none of which is a setting an Administrator should meet while looking
 * for something else.
 *
 * The numbering panel shows the **next number each sequence will actually
 * produce**, worked out by the same code that will produce it. An operator
 * setting `padding` to 5 and a prefix of `2026/` is entitled to see `2026/00042`
 * before a customer does, and a preview computed by a second implementation
 * would eventually disagree with the first.
 */
final class BillingSettingsController extends Controller
{
    /**
     * The sequences an operator may configure, in the order documents happen.
     *
     * Named rather than "every row in the table": `ticket` is in there too, and a
     * support ticket's number is not a financial document. A key with no row yet
     * is still offered, because a sequence is created lazily on the first
     * document and an operator setting up an installation has none of them.
     */
    private const array KEYS = ['order', 'invoice', 'proforma', 'credit_note'];

    public function __construct(
        private readonly CurrentActor $actor,
        private readonly BillingSettings $settings,
        private readonly ResolveSeller $sellers,
        private readonly OrganizationContext $organizations,
    ) {}

    public function index(): Response
    {
        $this->assertOwner();

        $sellerId = $this->sellerId();
        $settings = $this->settings->forSeller($sellerId);

        return Inertia::render('Admin/Billing/Settings', [
            'settings' => [
                'dueDays' => $settings->due_days,
                'lateFeeRate' => $settings->lateFeePercentage(),
                'lateFeeLabel' => $settings->late_fee_label,
                'documentNote' => $settings->document_note,
                // Whether anybody has stated these, so the screen can say "this
                // is the shipped default" rather than implying somebody chose it.
                'stated' => $settings->exists,
            ],
            'sequences' => $this->sequences($sellerId),
            'options' => [
                'resetPeriods' => array_map(
                    static fn (NumberResetPeriod $period): array => [
                        'value' => $period->value,
                        'label' => (string) __($period->labelKey()),
                    ],
                    NumberResetPeriod::cases(),
                ),
            ],
        ]);
    }

    public function update(BillingSettingsRequest $request, SaveBillingSettings $save): RedirectResponse
    {
        $this->assertOwner();

        $save->handle($this->sellerId(), $request->toAttributes(), $this->actor->model());

        return back()->with('status', __('billing.settings.saved'));
    }

    public function numbering(Request $request, string $key, SaveNumberSequence $save): RedirectResponse
    {
        $this->assertOwner();

        abort_unless(in_array($key, self::KEYS, true), 404);

        $data = $request->validate([
            // A prefix may be empty — a number with no prefix is a valid
            // numbering scheme — so `present` rather than `required`.
            'prefix' => ['present', 'string', 'max:16'],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],
            // The next number, not the last one used. Naming it after what the
            // operator will see is the difference between continuing a legacy
            // book at 10421 and colliding with its last invoice.
            'next_value' => ['required', 'integer', 'min:1'],
            'reset_period' => ['required', 'string'],
        ]);

        $save->handle(
            $this->sequenceFor($this->sellerId(), $key),
            prefix: (string) $data['prefix'],
            padding: (int) $data['padding'],
            nextValue: (int) $data['next_value'],
            resetPeriod: NumberResetPeriod::from((string) $data['reset_period']),
            actor: $this->actor->model(),
        );

        return back()->with('status', __('billing.settings.numbering.saved'));
    }

    /**
     * Every financial sequence, whether or not a row exists yet.
     *
     * @return list<array<string, mixed>>
     */
    private function sequences(string $sellerId): array
    {
        $rows = $this->organizations->withoutBoundary(
            static fn (): array => NumberSequence::query()
                ->where('organization_id', $sellerId)
                ->whereIn('key', self::KEYS)
                ->get()
                ->keyBy('key')
                ->all(),
        );

        $now = CarbonImmutable::now();

        return array_map(function (string $key) use ($rows, $now): array {
            $sequence = $rows[$key] ?? $this->placeholder($key);

            $period = $sequence->reset_period->keyFor($now);
            $stale = $period !== null && $sequence->isStale($period);

            return [
                'key' => $key,
                'label' => (string) __('billing.settings.numbering.keys.'.$key),
                'prefix' => $sequence->prefix,
                'padding' => $sequence->padding,
                'nextValue' => $stale ? 1 : $sequence->next_value,
                'resetPeriod' => $sequence->reset_period->value,
                'periodKey' => $sequence->period_key,
                // Through the same formatter the allocation uses, so what an
                // operator reads here is what a customer will read on paper.
                'preview' => $sequence->format($stale ? 1 : $sequence->next_value),
                'exists' => $sequence->exists,
            ];
        }, self::KEYS);
    }

    /**
     * The row a key will get the first time a document of that kind is raised.
     *
     * Unsaved, and the screen says so. Writing rows here would create four
     * sequences on an installation that has never issued anything, and an
     * operator who then changed the invoice prefix would have written a row
     * nobody asked for on three other sequences too.
     */
    private function placeholder(string $key): NumberSequence
    {
        $sequence = new NumberSequence;

        $sequence->key = $key;
        $sequence->prefix = (string) config(match ($key) {
            'order' => 'platform.ordering.numbering.prefix',
            'proforma' => 'platform.billing.numbering.proforma_prefix',
            'credit_note' => 'platform.billing.numbering.credit_note_prefix',
            default => 'platform.billing.numbering.invoice_prefix',
        }, '');
        $sequence->padding = (int) config(
            $key === 'order' ? 'platform.ordering.numbering.padding' : 'platform.billing.numbering.padding',
            6,
        );

        return $sequence;
    }

    /**
     * The row to write to, created on first save.
     *
     * An operator who configures the numbering before the first invoice exists
     * has to have their answer kept, so this is the one place a sequence is
     * created outside `AllocateNumber`.
     */
    private function sequenceFor(string $sellerId, string $key): NumberSequence
    {
        return $this->organizations->withoutBoundary(
            fn (): NumberSequence => NumberSequence::query()->firstOrCreate(
                ['organization_id' => $sellerId, 'key' => $key],
                [
                    'prefix' => $this->placeholder($key)->prefix,
                    'next_value' => 1,
                    'padding' => $this->placeholder($key)->padding,
                    'reset_period' => NumberResetPeriod::Never->value,
                ],
            ),
        );
    }

    private function sellerId(): string
    {
        return $this->sellers->forOrganization($this->organizations->id() ?? '');
    }

    private function assertOwner(): void
    {
        AppsController::assertSuperAdminFor($this->actor);
    }
}
