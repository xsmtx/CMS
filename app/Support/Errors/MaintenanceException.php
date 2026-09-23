<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * The installation is closed on purpose.
 *
 * A 503 through the same envelope as everything else, so an integrator's
 * client sees a shape it already handles rather than an HTML page where it
 * expected JSON. The message is the operator's own wording, which is the
 * one place in this platform where an error body carries text somebody
 * outside wrote — and it is shown to the public by design.
 */
final class MaintenanceException extends PlatformException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::ServiceUnavailable;
    }
}
