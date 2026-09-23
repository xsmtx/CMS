<?php

declare(strict_types=1);

namespace App\Domain\Operations\Contracts;

/**
 * A queued job that appears in the Background Operations Center.
 *
 * A method rather than a public property, because a property cannot be
 * declared on an interface and `WatchedDispatch` has to be able to say —
 * statically, not by inspecting traits at runtime — that this job can be
 * told which operation it belongs to.
 *
 * Not every queued thing implements it. A mail send is not a business
 * operation and does not belong on that screen.
 */
interface ReportsToOperations
{
    public function withOperation(string $operationId): static;
}
