<?php

declare(strict_types=1);

namespace App\Application\Licensing;

use App\Domain\Licensing\Contracts\LicenceClient;
use App\Domain\Licensing\Exceptions\LicenceRefused;
use App\Domain\Licensing\Exceptions\LicenceUnreachable;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Activate, heartbeat, deactivate — the three things an installation does
 * about its licence.
 *
 * One class because they share every rule, and the rules are the phase:
 *
 * - **A token is proved locally before it is believed.** The client is a
 *   transport; `VerifyLicenceToken` is the authority (ADR 0013).
 * - **A refusal and an outage are different.** `LicenceRefused` means the
 *   token is not a licence and the state is written as failed; every other
 *   failure leaves the entitlements exactly where they were, because that is
 *   what grace is. A vendor's DNS problem must never be a customer's outage.
 * - **Every one of them is audited**, and a refusal is audited with which
 *   refusal it was. "Licence invalid" tells an incident review nothing.
 * - **The licence key never reaches a log, a flash message or a screen.** It
 *   is the one secret in this whole context.
 *
 * The replay check needs the newest `issued_at` this installation has already
 * seen, which is why every path loads the state first — and why a heartbeat
 * that receives an older token than it already holds is refused rather than
 * stored.
 */
final readonly class Licensing
{
    public function __construct(
        private LicenceClient $client,
        private InstallationIdentity $identity,
        private VerifyLicenceToken $verifier,
    ) {}

    /**
     * Claim a licence for this installation.
     *
     * The key is passed in rather than read from config, because activation is
     * the one moment an operator types one — and the configured key is for
     * everything afterwards.
     */
    public function activate(string $licenceKey, ?Model $actor = null): LicenceState
    {
        $installationId = $this->identity->id();

        $wire = $this->client->activate($licenceKey, $installationId, $this->identity->claims());

        return $this->accept($wire, $installationId, previous: null, action: 'activated', actor: $actor);
    }

    /**
     * Say we are still here.
     *
     * Returns the state either way. A failure inside grace is a state with a
     * reason on it and the same entitlements — the caller's job is to record
     * the run, not to decide whether the platform keeps working.
     */
    public function heartbeat(?Model $actor = null): LicenceState
    {
        $state = LicenceState::load();

        if (! $state->configured) {
            // Nothing to heartbeat. Not an error: most installations live here.
            return $state;
        }

        $key = $this->configuredKey();

        if ($key === null) {
            $failed = $state->withFailure('No licence key is configured.');
            $failed->save();

            return $failed;
        }

        $installationId = $this->identity->id();

        try {
            $wire = $this->client->heartbeat($key, $installationId, $this->identity->claims());
        } catch (LicenceUnreachable $unreachable) {
            /*
             * The whole grace period, in three lines. The entitlements do not
             * move, the reason is recorded, and the run that called this is
             * still a run that completed — a heartbeat that threw would make a
             * vendor's outage look like a broken installation.
             */
            $failed = $state->withFailure($unreachable->getMessage());
            $failed->save();

            Audit::action('licensing.heartbeat.failed')
                ->by($actor)
                ->because($unreachable->getMessage())
                ->write();

            return $failed;
        }

        return $this->accept(
            $wire,
            $installationId,
            previous: $state->issuedAt,
            action: 'heartbeat',
            actor: $actor,
        );
    }

    /**
     * Give the activation back.
     *
     * The local state is cleared **whether or not the server answered**. An
     * operator who has decided this installation is no longer licensed should
     * not be blocked by the vendor being down, and an activation the server
     * still thinks is live is the vendor's problem to reconcile — they can see
     * the missing heartbeats.
     */
    public function deactivate(?Model $actor = null): void
    {
        $key = $this->configuredKey();
        $installationId = $this->identity->id();

        if ($key !== null) {
            try {
                $this->client->deactivate($key, $installationId);
            } catch (LicenceUnreachable $unreachable) {
                Audit::action('licensing.deactivate.unreachable')
                    ->by($actor)
                    ->because($unreachable->getMessage())
                    ->write();
            }
        }

        LicenceState::forget();

        Audit::action('licensing.deactivated')
            ->by($actor)
            ->withMetadata(['installation_id' => $installationId])
            ->write();
    }

    /**
     * Prove a token, store it, and say what happened.
     */
    private function accept(
        string $wire,
        string $installationId,
        ?CarbonImmutable $previous,
        string $action,
        ?Model $actor,
    ): LicenceState {
        try {
            $token = $this->verifier->handle($wire, $installationId, $previous);
        } catch (LicenceRefused $refused) {
            /*
             * A refusal is a security event, so it says which refusal it was
             * and it does not repair itself. An installation that fell back to
             * the last good token after a bad signature could be pinned to a
             * revoked licence by anybody able to replay one captured response.
             */
            Audit::action('licensing.token.refused')
                ->by($actor)
                ->because($refused->getMessage())
                ->withMetadata(['action' => $action])
                ->write();

            throw $refused;
        }

        $state = LicenceState::fromToken($token, $wire, $this->graceDays());
        $state->save();

        Audit::action('licensing.'.$action)
            ->by($actor)
            ->withMetadata([
                // The licence id and the edition, never the key.
                'licence_id' => $token->licenceId,
                'edition' => $token->edition,
                'status' => $token->status->value,
                'expires_at' => $token->expiresAt->toIso8601String(),
            ])
            ->write();

        return $state;
    }

    private function configuredKey(): ?string
    {
        $key = config('platform.licensing.key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function graceDays(): int
    {
        $days = config('platform.licensing.grace_days', 30);

        return is_numeric($days) ? max(0, (int) $days) : 30;
    }
}
