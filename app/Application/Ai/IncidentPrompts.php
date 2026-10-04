<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Domain\Ai\AiFeature;
use App\Domain\Ai\AiPrompt;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentUpdate;

/**
 * What an incident sends to a vendor (ADR 0050).
 *
 * Assembled field by field, like `TicketPrompts` and for the same reason. What
 * leaves is the title, the state, and the updates somebody has already
 * written — the timeline **is** the thing a next update is written from, so
 * sending it is the whole point.
 *
 * **The alerts do not travel.** They name hostnames, node keys and the
 * machines a seller runs, and none of that is needed to write "we have failed
 * over and are watching the error rate". The status page already refuses to
 * publish them to customers for the same reason; a vendor is no more entitled
 * to them than the internet is.
 *
 * **Nor does the impact.** "47 customers, 12,400 EUR a month" tells somebody
 * the size of the business and which outage was the expensive one — the
 * sentence `PublicStatus` was built to withhold.
 */
final readonly class IncidentPrompts
{
    /**
     * The whole timeline, not a slice.
     *
     * An incident's updates are the evidence a postmortem is written from and
     * there are rarely more than a dozen; truncating the early ones would
     * drop exactly the part that says what was believed at half past two.
     */
    public function update(Incident $incident): AiPrompt
    {
        $incident->loadMissing('updates');

        $context = [
            ['label' => 'Title', 'value' => $incident->title],
            ['label' => 'State', 'value' => (string) __($incident->state->labelKey())],
        ];

        if ($incident->summary !== null && $incident->summary !== '') {
            $context[] = ['label' => 'Summary', 'value' => $incident->summary];
        }

        foreach ($incident->updates->sortBy('created_at') as $update) {
            /** @var IncidentUpdate $update */
            $context[] = [
                'label' => (string) __($update->state->labelKey()),
                'value' => $update->body,
            ];
        }

        return new AiPrompt(
            feature: AiFeature::IncidentUpdate,
            task: (string) __('ai.tasks.incident_update'),
            context: $context,
            // An incident update is three sentences. One that ran longer
            // would be a status page nobody finishes reading.
            maxTokens: 400,
        );
    }
}
