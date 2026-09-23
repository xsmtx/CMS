<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the gateways told us, and whether we could match it.
 *
 * The screen an operator opens when a customer says they paid and the
 * invoice says otherwise. Every webhook this platform accepted is here with
 * the provider's own event id, so "did it arrive" and "did we act on it"
 * are two questions with two answers rather than one shrug.
 *
 * **The payload is not shown.** A gateway's body can carry a name, an
 * address and the last four digits of a card, and a log screen is the
 * easiest place in a product to leak all three to somebody who only needed
 * to know whether an event arrived. The fact, the kind and the time are
 * enough to answer the question this screen exists for.
 */
final class GatewayLogController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        if (! $this->actor->can('billing.payments.manage')) {
            throw new ForbiddenException(__('billing.errors.not_permitted'));
        }

        $gateway = $request->string('gateway')->toString();

        $events = GatewayEventRecord::query()
            ->when($gateway !== '', fn ($query) => $query->where('gateway', $gateway))
            ->latest('received_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Billing/GatewayLog', [
            'events' => [
                'data' => array_map($this->row(...), $events->items()),
                'currentPage' => $events->currentPage(),
                'lastPage' => $events->lastPage(),
                'total' => $events->total(),
            ],
            'filters' => ['gateway' => $gateway === '' ? null : $gateway],
            'gateways' => array_values(GatewayEventRecord::query()
                ->select('gateway')
                ->distinct()
                ->orderBy('gateway')
                ->pluck('gateway')
                ->map(static fn (string $key): array => ['value' => $key, 'label' => $key])
                ->all()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GatewayEventRecord $event): array
    {
        return [
            'id' => $event->id,
            'gateway' => $event->gateway,
            'eventId' => $event->event_id,
            'type' => $event->type,
            // Whether we acted on it, and what went wrong if not. This is
            // the whole point of the screen.
            'outcome' => $event->outcome,
            'error' => $event->error,
            'reference' => $event->payment_reference,
            'receivedAt' => $event->received_at->toIso8601String(),
            'processedAt' => $event->processed_at?->toIso8601String(),
        ];
    }
}
