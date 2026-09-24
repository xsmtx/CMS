<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Shared\ResolveSeller;
use App\Application\Tax\DeleteTaxRule;
use App\Application\Tax\SaveTaxRule;
use App\Application\Tax\SaveTaxSettings;
use App\Application\Tax\TaxRules;
use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxCustomerKind;
use App\Domain\Tax\TaxRounding;
use App\Domain\Tax\TaxSettingsAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\TaxRuleRequest;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tax, as rows an operator maintains.
 *
 * **The owner's screen and nobody else's**, for the reason ADR 0045 gives: a wrong
 * rate misstates a legal document for every customer at once, and an
 * Administrator holds every staff permission by design, so a permission could
 * never mean "the person who answers to the tax authority".
 *
 * The third panel is the one that earns the screen. An operator configuring a
 * country this platform knows nothing about needs to see what their rules
 * actually do to an amount — including which rule won when two could have — before
 * a customer does. It calls the real calculator rather than reimplementing the
 * arithmetic, because a preview that agreed with a second implementation and
 * disagreed with the invoice would be worse than no preview.
 */
final class TaxController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly TaxRules $rules,
        private readonly ResolveSeller $sellers,
        private readonly OrganizationContext $organizations,
    ) {}

    public function index(): Response
    {
        $this->assertOwner();

        $sellerId = $this->sellerId();
        $settings = $this->rules->settings($sellerId);

        return Inertia::render('Admin/Tax/Index', [
            'rules' => array_values(
                TaxRule::query()
                    ->where('organization_id', $sellerId)
                    ->orderBy('level')
                    ->orderByDesc('priority')
                    ->orderBy('country_code')
                    ->orderBy('region_code')
                    ->get()
                    ->map(fn (TaxRule $rule): array => $this->row($rule))
                    ->all()
            ),
            'settings' => [
                'pricesIncludeTax' => $settings->prices_include_tax,
                'rounding' => $settings->rounding->value,
                'taxIdLabel' => $settings->tax_id_label,
                'requireTaxIdForBusiness' => $settings->require_tax_id_for_business,
                'exemptionNote' => $settings->exemption_note,
            ],
            'options' => [
                'appliesTo' => $this->options(TaxAppliesTo::cases()),
                'customerKinds' => $this->options(TaxCustomerKind::cases()),
                'rounding' => $this->options(TaxRounding::cases()),
                'currencies' => array_values(
                    CurrencyRecord::query()
                        ->where('organization_id', $sellerId)
                        ->orderBy('code')
                        ->pluck('code')
                        ->all()
                ),
            ],
            // Built only when somebody asks for it by name, so an ordinary page
            // load computes nothing.
            'preview' => Inertia::optional(fn (): ?array => $this->preview(request())),
        ]);
    }

    public function store(TaxRuleRequest $request, SaveTaxRule $save): RedirectResponse
    {
        $this->assertOwner();

        $save->handle($this->sellerId(), $request->toAttributes(), null, $this->actor->model());

        return back()->with('status', __('tax.rules.saved'));
    }

    public function update(TaxRuleRequest $request, TaxRule $rule, SaveTaxRule $save): RedirectResponse
    {
        $this->assertOwner();

        $save->handle($rule->organization_id, $request->toAttributes(), $rule, $this->actor->model());

        return back()->with('status', __('tax.rules.saved'));
    }

    public function destroy(TaxRule $rule, DeleteTaxRule $delete): RedirectResponse
    {
        $this->assertOwner();

        $delete->handle($rule, $this->actor->model());

        return back()->with('status', __('tax.rules.deleted'));
    }

    public function settings(Request $request, SaveTaxSettings $save): RedirectResponse
    {
        $this->assertOwner();

        $data = $request->validate([
            'prices_include_tax' => ['sometimes', 'boolean'],
            'rounding' => ['required', 'string'],
            'tax_id_label' => ['nullable', 'string', 'max:32'],
            'require_tax_id_for_business' => ['sometimes', 'boolean'],
            'exemption_note' => ['nullable', 'string', 'max:191'],
        ]);

        $save->handle($this->sellerId(), new TaxSettingsAttributes(
            pricesIncludeTax: $request->boolean('prices_include_tax'),
            rounding: TaxRounding::from((string) $data['rounding']),
            // `??`, not `$data['key']`: `validate()` returns only what was
            // submitted, so a field the form left empty is absent rather than
            // null.
            taxIdLabel: $data['tax_id_label'] ?? null,
            requireTaxIdForBusiness: $request->boolean('require_tax_id_for_business'),
            exemptionNote: $data['exemption_note'] ?? null,
        ), $this->actor->model());

        return back()->with('status', __('tax.settings.saved'));
    }

    /**
     * What the rules do to one amount, through the real calculator.
     *
     * @return array<string, mixed>|null
     */
    private function preview(Request $request): ?array
    {
        $amount = $request->integer('amount');
        $currency = strtoupper($request->string('currency')->toString());

        if ($amount <= 0 || strlen($currency) !== 3) {
            return null;
        }

        $supply = new TaxableSupply(
            amount: Money::ofMinor($amount, $currency),
            countryCode: $this->upper($request, 'country_code'),
            stateCode: $this->upper($request, 'region_code'),
            postalCode: $request->input('postcode'),
            // **Never the tax id itself.** The preview is a GET, so everything it
            // sends lands in the address bar, in browser history and in the
            // server's access log — and a customer's tax id has no business in
            // any of them. The calculator only ever asks whether there *is*
            // one: it does not validate an id and could not, since that means
            // calling a country's own service. So the form sends the answer to
            // that question and nothing more.
            taxId: $request->boolean('has_tax_id') ? 'provided' : null,
            isBusiness: $request->boolean('is_business'),
            appliesTo: TaxAppliesTo::tryFrom($request->string('applies_to')->toString()) ?? TaxAppliesTo::All,
        );

        $result = app(TaxCalculator::class)->calculate($supply);
        $locale = app()->getLocale();

        return [
            'net' => $supply->amount->format($locale),
            'tax' => $result->total->format($locale),
            'gross' => $supply->amount->plus($result->total)->format($locale),
            'exemption' => $result->exemptionReason,
            'components' => array_map(
                static fn (TaxComponent $component): array => [
                    'name' => $component->name,
                    'rate' => $component->rate,
                    'amount' => $component->amount->format($locale),
                    'jurisdiction' => $component->jurisdiction,
                ],
                $result->components,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TaxRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'rate' => $rule->percentage(),
            'countryCode' => $rule->country_code,
            'regionCode' => $rule->region_code,
            'postcodePattern' => $rule->postcode_pattern,
            'level' => $rule->level,
            'compound' => $rule->compound,
            'appliesTo' => $rule->applies_to->value,
            'customerKind' => $rule->customer_kind->value,
            'exemptsValidatedBusiness' => $rule->exempts_validated_business,
            'exemptionNote' => $rule->exemption_note,
            'priority' => $rule->priority,
            'startsOn' => $rule->starts_on?->toDateString(),
            'endsOn' => $rule->ends_on?->toDateString(),
            'isActive' => $rule->is_active,
            'notes' => $rule->notes,
        ];
    }

    /**
     * @param  list<TaxAppliesTo|TaxCustomerKind|TaxRounding>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            static fn (TaxAppliesTo|TaxCustomerKind|TaxRounding $case): array => [
                'value' => $case->value,
                'label' => (string) __($case->labelKey()),
            ],
            $cases,
        );
    }

    private function upper(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));

        return $value === '' ? null : strtoupper($value);
    }

    /**
     * Whose rules these are: the seller's, resolved the way document numbers and
     * support departments resolve it.
     */
    private function sellerId(): string
    {
        $organizationId = $this->organizations->id() ?? '';

        return $this->sellers->forOrganization($organizationId);
    }

    private function assertOwner(): void
    {
        AppsController::assertSuperAdminFor($this->actor);
    }
}
