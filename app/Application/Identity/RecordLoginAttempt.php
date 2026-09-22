<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Guard;
use App\Domain\Identity\LoginFailureReason;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Support\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes one row per sign-in attempt.
 *
 * Failures are recorded as deliberately as successes. Without them nobody can
 * tell a customer who forgot their password from a host working through a
 * credential list, and that distinction is the whole reason the table exists.
 *
 * Writes bypass the organization scope: an attempt on an unknown address has
 * no organization yet, and one on a known account must be recorded even when
 * no boundary has been established, which is exactly the case during login.
 */
final readonly class RecordLoginAttempt
{
    public function __construct(
        private CorrelationContext $correlation,
        private Request $request,
    ) {}

    public function succeeded(Guard $guard, Model $subject): void
    {
        $this->write(
            guard: $guard,
            email: (string) $subject->getAttribute('email'),
            successful: true,
            reason: null,
            subject: $subject,
        );
    }

    public function failed(
        Guard $guard,
        string $email,
        LoginFailureReason $reason,
        ?Model $subject = null,
    ): void {
        $this->write($guard, $email, false, $reason, $subject);
    }

    private function write(
        Guard $guard,
        string $email,
        bool $successful,
        ?LoginFailureReason $reason,
        ?Model $subject,
    ): void {
        $organizationId = $subject?->getAttribute('organization_id');

        LoginHistory::query()->withoutGlobalScope('organization')->create([
            'organization_id' => is_string($organizationId) ? $organizationId : null,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'guard' => $guard->value,
            // Truncated rather than dropped: a very long submitted address is
            // itself worth seeing, but it must not blow the column.
            'email_attempted' => mb_substr($email, 0, 191),
            'successful' => $successful,
            'failure_reason' => $reason?->value,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'correlation_id' => $this->correlation->idOrGenerate(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
