<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Crm\CompleteCancellation;
use App\Application\Crm\SearchCancellations;
use App\Domain\Crm\CancellationStatus;
use App\Domain\Crm\CancellationType;
use App\Http\Controllers\Controller;
use App\Infrastructure\Crm\Models\CancellationRequest;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cancellation queue: who asked to stop, why, and by when.
 *
 * Open requests first and oldest first, because that is what a queue is
 * for. A request nobody acted on is a customer who told you they were
 * leaving and heard nothing back, which is the one cancellation that is
 * still worth a telephone call.
 */
final class CancellationController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request, SearchCancellations $search): Response
    {
        $this->authorizeFor('services.view');

        /** @var array<string, string> $criteria */
        $criteria = array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $request->only(['reason', 'client', 'domain', 'type', 'service', 'status']),
        );

        $requests = $search->paginate($criteria);

        return Inertia::render('Admin/Cancellations/Index', [
            'requests' => [
                'data' => array_map($this->row(...), $requests->items()),
                'currentPage' => $requests->currentPage(),
                'lastPage' => $requests->lastPage(),
                'total' => $requests->total(),
                'links' => $requests->linkCollection()->all(),
            ],
            'filters' => $criteria,
            'types' => array_values(array_map(
                static fn (CancellationType $type): array => [
                    'value' => $type->value,
                    'label' => (string) __($type->labelKey()),
                ],
                CancellationType::cases(),
            )),
            'statuses' => array_values(array_map(
                static fn (CancellationStatus $status): array => [
                    'value' => $status->value,
                    'label' => (string) __($status->labelKey()),
                ],
                CancellationStatus::cases(),
            )),
            'can' => ['manage' => $this->actor->can('services.manage')],
        ]);
    }

    public function complete(string $request, CompleteCancellation $cancellations): RedirectResponse
    {
        $this->authorizeFor('services.manage');

        $cancellations->complete($this->find($request), $this->actor->model());

        return back()->with('status', __('crm.cancellations.completed'));
    }

    public function withdraw(string $request, CompleteCancellation $cancellations): RedirectResponse
    {
        $this->authorizeFor('services.manage');

        $cancellations->withdraw($this->find($request), $this->actor->model());

        return back()->with('status', __('crm.cancellations.withdrawn'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CancellationRequest $request): array
    {
        return [
            'id' => $request->id,
            'requestedAt' => $request->requested_at->toIso8601String(),
            'service' => $request->service?->name,
            'serviceId' => $request->service_id,
            'domain' => $request->service?->domain,
            'customer' => $request->customer?->displayName(),
            'customerId' => $request->customer_id,
            'reason' => $request->reason,
            'type' => $request->type->value,
            'typeLabel' => (string) __($request->type->labelKey()),
            'status' => $request->status->value,
            'statusLabel' => (string) __($request->status->labelKey()),
            'requestedBy' => $request->requested_by_label,
            // When it actually stops. An immediate request stops now; an
            // end-of-term one stops on the date they have paid up to, which
            // is the service's own next due date.
            'endsOn' => $request->type === CancellationType::Immediate
                ? null
                : $request->service?->next_due_on?->toDateString(),
        ];
    }

    private function find(string $id): CancellationRequest
    {
        $request = CancellationRequest::query()->whereKey($id)->first();

        if (! $request instanceof CancellationRequest) {
            abort(404);
        }

        return $request;
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('provisioning.services.not_permitted'));
        }
    }
}
