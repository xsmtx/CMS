<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Security\Models\ZoneFinding;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is wrong with the DNS of the domains this installation holds (§8).
 *
 * **Read-only.** Core owns the checks because they are RFCs rather than
 * opinions; changing a record belongs behind §6's guarded workflow rather
 * than on a screen — a bulk record change is how a business vanishes from the
 * internet for four hours.
 *
 * Warnings first, then information. The two that are objectively broken —
 * two SPF records, `+all`, one nameserver — are the ones somebody should act
 * on today, and a list sorted by domain would bury them among the DMARC
 * records everybody is still rolling out.
 */
final class ZoneHealthController extends Controller
{
    public function __invoke(Request $request, CurrentActor $actor): Response
    {
        if (! $actor->can('security.dns.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $showAll = $request->boolean('all');

        $findings = ZoneFinding::query()
            ->with(['domain', ...Customer::displayNameWith('domain.customer')])
            ->unless($showAll, static fn ($query) => $query->open())
            // Warnings before information. Stated rather than inferred: the
            // two values sort the wrong way alphabetically, and a list that
            // buried the `+all` among the DMARC records everybody is still
            // rolling out is a list nobody acts on.
            ->orderByRaw("FIELD(severity, 'warning', 'info')")
            ->orderBy('first_seen_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Security/ZoneHealth', [
            'findings' => [
                'data' => array_map($this->row(...), array_values($findings->items())),
                'links' => $findings->linkCollection()->toArray(),
                'currentPage' => $findings->currentPage(),
                'lastPage' => $findings->lastPage(),
                'total' => $findings->total(),
            ],
            'filters' => ['all' => $showAll],
            'counts' => [
                'warnings' => ZoneFinding::query()->open()->where('severity', 'warning')->count(),
                'information' => ZoneFinding::query()->open()->where('severity', 'info')->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ZoneFinding $finding): array
    {
        $check = $finding->check;

        return [
            'id' => $finding->id,
            'domain' => $finding->domain?->name,
            'domainId' => $finding->domain_id,
            'check' => $check->value,
            'checkLabel' => (string) __($check->labelKey()),
            // The sentence that says what to do about it. Kept in `lang/`
            // rather than on the row for the `health_message` reason: a
            // sentence stored in whichever language the sweep ran in is a
            // sentence the next operator cannot read.
            'detail' => (string) __($check->detailKey()),
            'severity' => $finding->severity->value,
            'severityLabel' => (string) __($finding->severity->labelKey()),
            'severityTone' => $finding->severity->tone(),
            'source' => $finding->source,
            'firstSeenAt' => $finding->first_seen_at->toIso8601String(),
            'lastSeenAt' => $finding->last_seen_at->toIso8601String(),
            'clearedAt' => $finding->cleared_at?->toIso8601String(),
            'customer' => $finding->domain?->customer?->displayName(),
            'customerId' => $finding->domain?->customer_id,
        ];
    }
}
