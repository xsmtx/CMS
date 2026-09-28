<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Security\Models\ReputationListing;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which of this installation's addresses are on a blocklist (§13).
 *
 * **Read-only, and there is nothing to write.** Asking a blocklist to lift a
 * listing is a form with a human on the other end, usually a captcha; a
 * button here would be a button that lies. What the screen can do instead is
 * carry the link to that form, which is the one thing an operator actually
 * needs next.
 *
 * Attributed listings first, because an address a customer's service is
 * sitting on is somebody to tell today — and an unattributed one is very
 * often our own outbound relay, which is ours to fix whenever.
 */
final class ReputationController extends Controller
{
    public function __invoke(Request $request, CurrentActor $actor): Response
    {
        if (! $actor->can('security.reputation.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $showAll = $request->boolean('all');

        $listings = ReputationListing::query()
            ->with([...Customer::displayNameWith('customer'), 'service'])
            ->unless($showAll, static fn ($query) => $query->open())
            // Somebody to tell, before something to fix on our own time.
            ->orderByRaw('customer_id IS NULL')
            ->orderBy('first_seen_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Security/Reputation', [
            'listings' => [
                'data' => array_map($this->row(...), array_values($listings->items())),
                'links' => $listings->linkCollection()->toArray(),
                'currentPage' => $listings->currentPage(),
                'lastPage' => $listings->lastPage(),
                'total' => $listings->total(),
            ],
            'filters' => ['all' => $showAll],
            'counts' => [
                'listed' => ReputationListing::query()->open()->count(),
                // Addresses rather than rows: one address on four lists is
                // one problem to solve, and counting it four times would
                // make the screen read as four times worse than it is.
                'addresses' => ReputationListing::query()->open()->distinct()->count('address_bytes'),
                'customers' => ReputationListing::query()->open()->whereNotNull('customer_id')
                    ->distinct()->count('customer_id'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReputationListing $listing): array
    {
        return [
            'id' => $listing->id,
            'address' => $listing->address,
            'list' => $listing->list,
            // The blocklist's own words, untranslated on purpose: it is
            // evidence in a conversation with somebody else, and a
            // paraphrase is how a delisting request gets refused.
            'reason' => $listing->reason,
            'delistUrl' => $listing->delist_url,
            'source' => $listing->source,
            'firstSeenAt' => $listing->first_seen_at->toIso8601String(),
            'lastSeenAt' => $listing->last_seen_at->toIso8601String(),
            'clearedAt' => $listing->cleared_at?->toIso8601String(),
            'customer' => $listing->customer?->displayName(),
            'customerId' => $listing->customer_id,
            'service' => $listing->service?->name,
            'serviceId' => $listing->service_id,
        ];
    }
}
