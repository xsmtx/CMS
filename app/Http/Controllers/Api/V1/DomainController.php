<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Operations\WatchedDispatch;
use App\Domain\Domains\DomainOperation;
use App\Domain\Operations\OperationType;
use App\Http\Api\ApiResource;
use App\Http\Api\QueryOptions;
use App\Http\Requests\Api\NameserverRequest;
use App\Infrastructure\Domains\Jobs\RunDomainAction;
use App\Infrastructure\Domains\Models\Domain;
use App\Support\Correlation\CorrelationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's domain names.
 *
 * Nameservers are the one thing a domain owner changes often and the one
 * thing worth automating: a script that moves a hundred domains to a new
 * DNS provider is the reason this endpoint exists.
 *
 * The registrant is never returned. The registry holds it, this platform
 * deliberately does not (ADR 0028), and an API that invented it would be
 * publishing a guess about somebody's legal identity.
 */
final class DomainController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            filters: ['status' => 'status', 'tld' => 'tld_id'],
            sorts: ['created_at', 'name', 'expires_on'],
        );

        $domains = $options
            ->applyTo($this->customer->owned(Domain::query()), $request)
            ->paginate($options->perPage($request));

        return new JsonResponse(ApiResource::page(
            $domains,
            array_values(array_map($this->row(...), $domains->items())),
        ));
    }

    public function show(string $domain): JsonResponse
    {
        /** @var Domain $record */
        $record = $this->customer->find(Domain::query()->whereKey($domain));

        return new JsonResponse(ApiResource::item([
            ...$this->row($record),
            'nameservers' => $record->nameservers ?? [],
            'registrar_lock' => $record->registrar_lock,
            'whois_privacy' => $record->whois_privacy,
            'registered_on' => $record->registered_on?->toDateString(),
        ]));
    }

    public function nameservers(
        NameserverRequest $request,
        string $domain,
        WatchedDispatch $dispatcher,
        CorrelationContext $correlation,
    ): JsonResponse {
        /** @var Domain $record */
        $record = $this->customer->find(Domain::query()->whereKey($domain));

        /** @var list<string> $nameservers */
        $nameservers = $request->validated('nameservers');

        $dispatcher->handle(
            OperationType::DomainSync,
            $record,
            new RunDomainAction(
                $record->id,
                DomainOperation::SetNameservers,
                null,
                $nameservers,
                1,
                $correlation->id(),
            ),
            $this->customer->contact(),
        );

        return new JsonResponse(
            ApiResource::item([
                'id' => $record->id,
                'nameservers' => $nameservers,
                'status' => 'queued',
            ]),
            JsonResponse::HTTP_ACCEPTED,
        );
    }

    public function renew(
        Request $request,
        string $domain,
        WatchedDispatch $dispatcher,
        CorrelationContext $correlation,
    ): JsonResponse {
        /** @var Domain $record */
        $record = $this->customer->find(Domain::query()->whereKey($domain));

        $years = $request->integer('years') > 0 ? $request->integer('years') : 1;

        $dispatcher->handle(
            OperationType::DomainRenew,
            $record,
            new RunDomainAction(
                $record->id,
                DomainOperation::Renew,
                null,
                [],
                $years,
                $correlation->id(),
            ),
            $this->customer->contact(),
        );

        return new JsonResponse(
            ApiResource::item([
                'id' => $record->id,
                'years' => $years,
                'status' => 'queued',
            ]),
            JsonResponse::HTTP_ACCEPTED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Domain $domain): array
    {
        return [
            'id' => $domain->id,
            'name' => $domain->name,
            'status' => $domain->status->value,
            'auto_renew' => $domain->auto_renew,
            'expires_on' => $domain->expires_on?->toDateString(),
            'renewal' => ApiResource::money($domain->renewal),
            'created_at' => $domain->created_at?->toIso8601String(),
        ];
    }
}
