<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Provisioning\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceOption;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the customer is running.
 *
 * Reads the same rows the admin screen reads, narrowed to this customer.
 * What it leaves out is the point: the server's name, the module, the
 * external account id and the event log are an operator's vocabulary, and
 * a customer reading "placement failed on node-7" learns only that
 * something they do not control is broken.
 *
 * The password the provider issued is shown, because it is theirs and they
 * cannot get in without it. It is the one credential in this platform that
 * is meant to be read by the person it belongs to.
 */
final class ServiceController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
    ) {}

    public function index(): Response
    {
        $this->authorizeServices();

        $services = $this->customer
            ->owned(Service::query())
            ->whereNot('status', ServiceStatus::Terminated->value)
            ->latest()
            ->get();

        return Inertia::render('Client/Services/Index', [
            'services' => $services
                ->map(fn (Service $service): array => $this->row($service))
                ->values()
                ->all(),
        ]);
    }

    public function show(string $service): Response
    {
        $this->authorizeServices();

        /** @var Service $record */
        $record = $this->customer->find(Service::query()->whereKey($service));

        $record->load('options');

        return Inertia::render('Client/Services/Show', [
            'service' => [
                ...$this->row($record),
                'package' => $record->package,
                'startsOn' => $record->starts_on?->toDateString(),
                'options' => $record->options
                    ->map(fn (ServiceOption $option): array => [
                        'group' => $option->group_name,
                        'label' => $option->label,
                    ])
                    ->values()
                    ->all(),
                // Theirs, and they cannot sign in without it.
                'credentials' => $record->status->isUsable() && $record->username !== null
                    ? ['username' => $record->username, 'password' => $record->password]
                    : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'status' => $service->status->value,
            'statusLabel' => (string) __($service->status->labelKey()),
            'isUsable' => $service->status->isUsable(),
            'domain' => $service->domain,
            'recurring' => $service->recurring->format(app()->getLocale()),
            'cycleLabel' => $service->billing_cycle === null
                ? null
                : (string) __('catalog.cycles.'.$service->billing_cycle->value),
            'nextDueOn' => $service->next_due_on?->toDateString(),
        ];
    }

    private function authorizeServices(): void
    {
        if (! $this->actor->can('portal.services.view')) {
            throw new ForbiddenException(__('provisioning.services.not_permitted'));
        }
    }
}
