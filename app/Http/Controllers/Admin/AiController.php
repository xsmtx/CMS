<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Ai\AiProviders;
use App\Application\Ai\AiSettings;
use App\Application\Shared\ResolveSeller;
use App\Domain\Ai\AiFeature;
use App\Domain\Ai\Contracts\AiProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiSettingsRequest;
use App\Infrastructure\Ai\Models\AiSetting;
use App\Infrastructure\Ai\Models\AiUsage;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Whether this installation uses an assistant, and for what (ADR 0050).
 *
 * **Owner-only, for exactly the reason the tax screen is** (ADR 0045): an
 * Administrator holds every staff permission by design, so no permission
 * could ever mean "the person who decides whether our customers' words are
 * sent to a vendor". That is a decision about data processing with a legal
 * owner, and on this installation the owner is the installation's.
 *
 * The screen says what leaves, per feature, in plain words — because somebody
 * turning one on is agreeing to it on their customers' behalf, and an
 * agreement nobody could read is not one.
 */
final class AiController extends Controller
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AiProviders $providers,
        private readonly ResolveSeller $sellers,
        private readonly CurrentActor $actor,
        private readonly OrganizationContext $organizations,
    ) {}

    public function index(): Response
    {
        $setting = $this->settings->forSeller($this->seller());

        return Inertia::render('Admin/Apps/Ai', [
            'settings' => [
                'provider' => $setting->provider_key,
                'features' => $setting->enabled_features ?? [],
                'instructions' => $setting->instructions,
            ],
            /*
             * Only what this installation has actually enabled. A list of
             * vendors core knows about would be core recommending one, and
             * a name on this screen that cannot be chosen is a name somebody
             * will go looking for.
             */
            'providers' => array_map(
                static fn (AiProvider $provider): array => [
                    'value' => $provider->key(),
                    'label' => $provider->key(),
                ],
                $this->providers->all(),
            ),
            'features' => array_map(
                static fn (AiFeature $feature): array => [
                    'value' => $feature->value,
                    'label' => (string) __($feature->labelKey()),
                    'description' => (string) __($feature->descriptionKey()),
                    // The distinction somebody turning one on actually needs:
                    // drafting what a customer reads and summarising for a
                    // colleague are different agreements.
                    'reachesCustomer' => $feature->reachesACustomer(),
                ],
                AiFeature::cases(),
            ),
            'usage' => $this->usage(),
        ]);
    }

    public function update(AiSettingsRequest $request): RedirectResponse
    {
        $sellerId = $this->seller();
        $provider = $request->string('provider')->toString();

        // A key naming a module that is not enabled is a setting that would
        // refuse at the reply box rather than here, which is the wrong place
        // to find out.
        $chosen = $provider === '' || $this->providers->get($provider) === null ? null : $provider;

        /** @var list<string> $features */
        $features = $chosen === null ? [] : ($request->validated('features') ?? []);

        $setting = AiSetting::query()->updateOrCreate(
            ['organization_id' => $sellerId],
            [
                'provider_key' => $chosen,
                'enabled_features' => array_values($features),
                'instructions' => $request->string('instructions')->toString() ?: null,
            ],
        );

        /*
         * Audited with the features named one by one, the way turning on an
         * adapter's writes is: "the assistant was switched on" would not say
         * whether somebody agreed to draft replies a customer reads or only
         * to summarise threads for colleagues.
         */
        Audit::action('ai.settings.updated')
            ->by($this->actor->model())
            ->on($setting)
            ->forOrganization($sellerId)
            ->withMetadata([
                'provider' => $chosen ?? 'none',
                'features' => implode(' ', $features),
            ])
            ->write();

        return back()->with('status', __('ai.saved'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usage(): array
    {
        $rows = AiUsage::query()
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AiUsage $usage): array => [
                'id' => $usage->id,
                // Two fields, as everywhere: the value and the word.
                'feature' => $usage->feature->value,
                'featureLabel' => (string) __($usage->feature->labelKey()),
                'provider' => $usage->provider_key,
                'model' => $usage->model,
                // Null rather than zero: "nobody said" and "it was free" are
                // different answers, and the screen says so in words.
                'tokens' => $usage->tokens(),
                'outcome' => $usage->outcome,
                'outcomeLabel' => (string) __('ai.usage.outcomes.'.$usage->outcome),
                'who' => $usage->actor?->name,
                'at' => $usage->created_at?->toIso8601String(),
            ])
            ->all();

        return array_values($rows);
    }

    private function seller(): string
    {
        return $this->sellers->forOrganization((string) $this->organizations->id());
    }
}
