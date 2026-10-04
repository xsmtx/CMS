<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Api\StaffApiScope;
use App\Domain\Mcp\McpTool;
use App\Http\Controllers\Api\V1\Staff\AccessGrantController;
use App\Http\Controllers\Api\V1\Staff\AlertController;
use App\Http\Controllers\Api\V1\Staff\IncidentController;
use App\Http\Controllers\Api\V1\Staff\LookupController;
use App\Http\Controllers\Api\V1\Staff\NetworkChangeController;
use App\Http\Controllers\Api\V1\Staff\RemoteHandsController;
use App\Http\Controllers\Api\V1\Staff\TicketController;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Running one tool (ADR 0051).
 *
 * **In `Http` rather than in `Application`, and the arch test is why.** This
 * class calls the staff API controllers, which is the interface layer, and an
 * application service reaching into it would be the dependency the layering
 * forbids. That is not a technicality dodged — it names what this is: MCP is
 * a *surface*, and translating one surface's call into another's is exactly
 * the interface layer's job. The use cases are reached the way they always
 * are, one layer further in.
 *
 * **Every tool calls the staff API controller a client would call**, and that
 * is the point rather than a shortcut. ADR 0051 claims MCP is a surface over
 * the use cases that already exist; routing through the same controller is
 * what makes the claim checkable — the shape, the organization boundary and
 * the policies cannot drift, because there is nothing to drift from.
 *
 * The scope is asked here rather than by middleware, because it varies by
 * tool rather than by route: one endpoint serves ten tools, and a route-level
 * check would have to be the union of their scopes, which is every scope.
 *
 * A record is resolved through an ordinary scoped query, so the boundary
 * applies and an id belonging to another organization answers "not found"
 * rather than 403 — the client area's rule, and a 403 on a lookup would
 * confirm the row exists.
 */
final readonly class McpTools
{
    public function __construct(private CurrentActor $actor) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<StaffApiScope>  $granted
     * @return array<string, mixed>|list<mixed>|null
     */
    public function run(McpTool $tool, array $arguments, array $granted, Request $request): ?array
    {
        /*
         * **Both** of `RequireStaffApiScope`'s questions, and the second one
         * was missing when this was first written: does the token carry the
         * scope, and does its holder hold the permissions behind it. The
         * first alone makes a scope a *grant* rather than a filter — a token
         * would do things its owner cannot, which is a privilege escalation
         * arrived at by accident, and on this surface the ceiling is every
         * permission on the installation.
         *
         * Both refusals are the same sentence, deliberately: which of the two
         * failed is information about the account.
         */
        if (! in_array($tool->scope(), $granted, strict: true)) {
            throw $this->refuse($tool->scope());
        }

        foreach ($tool->scope()->requiredPermissions() as $permission) {
            if (! $this->actor->can($permission)) {
                throw $this->refuse($tool->scope());
            }
        }

        return match ($tool) {
            McpTool::AlertsList => $this->data(app(AlertController::class)->index($request)),
            McpTool::IncidentsList => $this->data(app(IncidentController::class)->index($request)),
            McpTool::IncidentGet => $this->incident($arguments),
            McpTool::TicketsList => $this->data(app(TicketController::class)->index($request)),
            McpTool::TicketGet => $this->ticket($arguments),
            McpTool::MachinesList => $this->data(app(LookupController::class)->servers()),
            McpTool::ResourceGet => $this->data(
                app(LookupController::class)->show($this->with($request, ['key' => $arguments['key'] ?? ''])),
            ),
            McpTool::DeviceChangesList => $this->data(app(NetworkChangeController::class)->index($request)),
            McpTool::AccessGrantsList => $this->data(app(AccessGrantController::class)->index($request)),
            McpTool::RemoteHandsList => $this->data(app(RemoteHandsController::class)->index($request)),
        };
    }

    private function refuse(StaffApiScope $scope): ForbiddenException
    {
        return new ForbiddenException((string) __('api.errors.scope_missing', [
            'scope' => $scope->value,
        ]));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|list<mixed>|null
     */
    private function incident(array $arguments): ?array
    {
        $incident = Incident::query()->find((string) ($arguments['id'] ?? ''));

        // Null rather than a refusal: an id that is not here is not here,
        // whether it never existed or belongs to another organization, and a
        // model is told the same thing either way.
        return $incident === null ? null : $this->data(app(IncidentController::class)->show($incident));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|list<mixed>|null
     */
    private function ticket(array $arguments): ?array
    {
        $ticket = Ticket::query()->find((string) ($arguments['id'] ?? ''));

        return $ticket === null ? null : $this->data(app(TicketController::class)->show($ticket));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function with(Request $request, array $extra): Request
    {
        $copy = Request::create($request->fullUrl(), 'GET', $extra);
        $copy->setUserResolver($request->getUserResolver());
        $copy->attributes->add($request->attributes->all());

        return $copy;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function data(JsonResponse $response): ?array
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $response->getData(true);

        /** @var array<string, mixed>|list<mixed>|null $data */
        $data = $payload['data'] ?? null;

        return $data;
    }
}
