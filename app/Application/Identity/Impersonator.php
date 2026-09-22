<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\Impersonation;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Audit\Facades\Audit;
use App\Support\Correlation\CorrelationContext;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * A staff member acting as a customer.
 *
 * The most dangerous capability in the product: it produces actions
 * attributable to someone who did not perform them. Three things make it
 * acceptable, and all three are enforced here rather than left to the UI:
 * the boundary is checked, a reason is required, and both the start and the
 * end are audited under one correlation identifier.
 *
 * Only one guard is ever authenticated. The staff session is replaced by the
 * contact session and restored on exit, so nothing downstream has to reason
 * about which of two signed-in identities is the real one.
 */
final readonly class Impersonator
{
    public const string SESSION_KEY = 'auth.impersonation';

    public function __construct(
        private SessionRegistry $sessions,
        private OrganizationContext $organizations,
        private CorrelationContext $correlation,
        private Request $request,
    ) {}

    /**
     * Whether this staff member may act as this contact.
     *
     * The permission is checked by the policy; this is the boundary check,
     * and it is the one that stops a reseller reaching another reseller's
     * customers.
     */
    public function canImpersonate(StaffUser $actor, Contact $subject): bool
    {
        if (! $subject->canAuthenticate()) {
            return false;
        }

        $actorOrganization = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('id', $actor->organization_id)
            ->first();

        $subjectOrganization = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('id', $subject->organization_id)
            ->first();

        if ($actorOrganization === null || $subjectOrganization === null) {
            return false;
        }

        // Strictly below: a staff member cannot impersonate a contact in
        // their own organization, because that is a colleague, not a
        // customer.
        return $actorOrganization->owns($subjectOrganization)
            && $actorOrganization->id !== $subjectOrganization->id;
    }

    public function start(StaffUser $actor, Contact $subject, string $reason): Impersonation
    {
        if (! $this->canImpersonate($actor, $subject)) {
            throw new RuntimeException('That contact cannot be impersonated.');
        }

        $impersonation = Impersonation::query()->withoutGlobalScope('organization')->create([
            'organization_id' => $subject->organization_id,
            'impersonator_id' => $actor->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'correlation_id' => $this->correlation->idOrGenerate(),
            'started_at' => CarbonImmutable::now(),
        ]);

        Audit::action('identity.impersonation.started')
            ->by($actor)
            ->on($subject)
            ->because($reason)
            ->forOrganization($subject->organization_id)
            ->withMetadata(['impersonation_id' => $impersonation->id])
            ->write();

        $this->swapSession($actor, $subject, $impersonation);

        return $impersonation;
    }

    public function stop(): ?StaffUser
    {
        $payload = $this->payload();

        if ($payload === null) {
            return null;
        }

        $actor = StaffUser::query()
            ->withoutGlobalScope('organization')
            ->where('id', (string) ($payload['impersonator_id'] ?? ''))
            ->first();

        $impersonation = Impersonation::query()
            ->withoutGlobalScope('organization')
            ->where('id', (string) ($payload['impersonation_id'] ?? ''))
            ->first();

        $subject = Auth::guard(Guard::Client->value)->user();

        if ($impersonation !== null) {
            $impersonation->forceFill(['ended_at' => CarbonImmutable::now()])->save();
        }

        if ($actor !== null && $subject instanceof Contact) {
            Audit::action('identity.impersonation.ended')
                ->by($actor)
                ->on($subject)
                ->forOrganization($subject->organization_id)
                ->withMetadata(['impersonation_id' => $impersonation?->id])
                ->write();
        }

        $this->sessions->forget($this->request->session()->getId());
        Auth::guard(Guard::Client->value)->logout();
        $this->request->session()->forget(self::SESSION_KEY);

        if ($actor === null) {
            // The staff account was removed while impersonating. Leaving the
            // session anonymous is the only safe outcome.
            $this->request->session()->invalidate();
            $this->request->session()->regenerateToken();

            return null;
        }

        Auth::guard(Guard::Staff->value)->login($actor);
        $this->request->session()->regenerate();

        $this->organizations->set($actor->organization_id);
        $this->sessions->register(Guard::Staff, $actor, $this->request->session()->getId());

        return $actor;
    }

    public function isImpersonating(): bool
    {
        return $this->payload() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $payload = $this->request->session()->get(self::SESSION_KEY);

        return is_array($payload) ? $payload : null;
    }

    private function swapSession(StaffUser $actor, Contact $subject, Impersonation $impersonation): void
    {
        $this->sessions->forget($this->request->session()->getId());

        // Logging the staff guard out does not clear session data, so the
        // payload written next survives the swap.
        Auth::guard(Guard::Staff->value)->logout();

        $this->request->session()->put(self::SESSION_KEY, [
            'impersonation_id' => $impersonation->id,
            'impersonator_id' => $actor->id,
            'impersonator_name' => $actor->displayName(),
            'subject_name' => $subject->displayName(),
        ]);

        Auth::guard(Guard::Client->value)->login($subject);

        // New identifier, same data: a session id captured before the swap
        // cannot be replayed against the customer session.
        $this->request->session()->regenerate();

        $this->organizations->set($subject->organization_id);
        $this->sessions->register(Guard::Client, $subject, $this->request->session()->getId());
    }
}
