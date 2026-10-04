<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiFeature;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Infrastructure\Ai\Models\AiUsage;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * The one place a prompt reaches a provider (ADR 0050).
 *
 * Everything that must be true of an AI call in this product is true here and
 * nowhere else, which is the point: a second path would be a second place to
 * forget the settings check.
 *
 * In order, and the order matters:
 *
 * 1. **Is a provider registered at all?** No module, no assistant.
 * 2. **Has this seller turned this feature on?** Per feature, not per
 *    installation — drafting a summary for a colleague and drafting what a
 *    customer will read are different decisions.
 * 3. The seller's own instructions are attached to the prompt.
 * 4. The call is made, and **whatever happens a usage row is written** —
 *    including a refusal, because "the vendor would not answer" is what
 *    somebody is looking for when they ask why the button stopped working.
 *
 * **Nothing here sends anything to a customer.** It returns text. The caller
 * puts it in a box that a person then edits and submits, and there is no code
 * path from this method to an outbox.
 */
final readonly class Draft
{
    public function __construct(
        private AiProviders $providers,
        private AiSettings $settings,
        private OrganizationContext $organizations,
    ) {}

    public function write(AiPrompt $prompt, ?StaffUser $actor = null): AiCompletion
    {
        $organizationId = $this->organizations->id();

        if ($organizationId === null) {
            // Nothing runs outside a boundary here. A draft with no
            // organization has no settings to consult, which would mean
            // asking a vendor on behalf of nobody.
            throw AiUnavailable::noProvider();
        }

        $setting = $this->settings->forOrganization($organizationId);

        if (! $this->providers->any()) {
            throw AiUnavailable::noProvider();
        }

        if (! $setting->allows($prompt->feature)) {
            throw AiUnavailable::notEnabled((string) __($prompt->feature->labelKey()));
        }

        $provider = $this->providers->get((string) $setting->provider_key);

        if ($provider === null) {
            // The module was disabled or removed after somebody chose it. A
            // stored key naming nothing is a settings screen that has gone
            // stale, not a failure anybody can act on from a reply box.
            throw AiUnavailable::noProvider();
        }

        $withInstructions = new AiPrompt(
            feature: $prompt->feature,
            task: $prompt->task,
            context: $prompt->context,
            instructions: $setting->instructions,
            maxTokens: $prompt->maxTokens,
        );

        try {
            $completion = $provider->complete($withInstructions);
        } catch (Throwable $exception) {
            $this->record($organizationId, $prompt->feature, $provider->key(), null, $actor);

            // Anything the adapter did not already turn into an `AiUnavailable`
            // becomes one here. A reply box is not where a client library's
            // exception belongs, and non-negotiable 8 wants a sanitised error.
            throw $exception instanceof AiUnavailable
                ? $exception
                : AiUnavailable::unreachable($provider->key());
        }

        if (trim($completion->text) === '') {
            $this->record($organizationId, $prompt->feature, $provider->key(), null, $actor);

            // An empty answer is not a draft. Putting it in the box would
            // read as the button having worked.
            throw AiUnavailable::emptyAnswer($provider->key());
        }

        $this->record($organizationId, $prompt->feature, $provider->key(), $completion, $actor);

        return $completion;
    }

    /**
     * What it cost, never what it said.
     *
     * The audit row names the feature and the provider and stops there: an
     * audit log is the one table nothing deletes from, and a customer's words
     * in it would outlive every retention policy this product has — the rule
     * the abuse desk's evidence capture already needed.
     */
    private function record(
        string $organizationId,
        AiFeature $feature,
        string $providerKey,
        ?AiCompletion $completion,
        ?StaffUser $actor,
    ): void {
        $usage = AiUsage::query()->create([
            'organization_id' => $organizationId,
            'staff_user_id' => $actor?->id,
            'feature' => $feature->value,
            'provider_key' => $providerKey,
            'model' => $completion instanceof AiCompletion ? $completion->model : $providerKey,
            'prompt_tokens' => $completion?->promptTokens,
            'completion_tokens' => $completion?->completionTokens,
            'outcome' => $completion === null
                ? AiUsage::OutcomeRefused
                : AiUsage::OutcomeAnswered,
        ]);

        $entry = Audit::action('ai.draft.requested')
            ->on($usage)
            ->forOrganization($organizationId)
            ->withMetadata([
                'feature' => $feature->value,
                'provider' => $providerKey,
                'outcome' => $usage->outcome,
            ]);

        $actor instanceof StaffUser ? $entry->by($actor)->write() : $entry->bySystem('ai')->write();
    }
}
