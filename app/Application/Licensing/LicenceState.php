<?php

declare(strict_types=1);

namespace App\Application\Licensing;

use App\Domain\Licensing\LicenceStatus;
use App\Domain\Licensing\LicenceToken;
use App\Infrastructure\Platform\Models\PlatformState;
use Carbon\CarbonImmutable;

/**
 * What this installation last heard about its licence, and how long that
 * answer is good for.
 *
 * In `platform_state` rather than the cache, for the reason the scheduler
 * heartbeat is: a licence state that vanished on a Redis restart would put a
 * paying customer into the unlicensed set after every deploy, and an operator
 * whose branding disappeared twice stops believing the licence check.
 *
 * **Grace is the whole point.** Three moments matter and they are different:
 *
 * - `heartbeatBy` — when the vendor said to come back. Passing it is normal;
 *   it is what the heartbeat task watches for.
 * - `graceUntil` — how long the last good answer keeps working when the server
 *   cannot be reached. Passing it is the commercial trade-off ADR 0013 names.
 * - `expiresAt` — when the licence itself runs out, which the token says and
 *   no outage extends.
 *
 * The stored token is kept **in its wire form** as well as decoded. Keeping the
 * raw string is what lets a later release re-verify it against a rotated key,
 * and what lets the replay check compare `issued_at` against the newest token
 * actually seen rather than against whatever happened to be decoded last.
 */
final readonly class LicenceState
{
    public const string STATE_KEY = 'licensing.state';

    /**
     * @param  list<string>  $excluded
     * @param  array<string, int>  $limits
     */
    private function __construct(
        public bool $configured,
        public ?string $licenceId,
        public ?string $edition,
        public LicenceStatus $status,
        public array $excluded,
        public array $limits,
        public ?CarbonImmutable $issuedAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $heartbeatBy,
        public ?CarbonImmutable $graceUntil,
        public ?CarbonImmutable $lastContactAt,
        /** Why the last attempt failed, as one sanitised sentence. */
        public ?string $lastFailure,
        public ?string $token,
    ) {}

    /**
     * No licence has ever been activated here.
     *
     * Not an error state. A self-hosted installation with no commercial
     * relationship lives here permanently and everything is allowed.
     */
    public static function unconfigured(): self
    {
        return new self(
            configured: false,
            licenceId: null,
            edition: null,
            status: LicenceStatus::Active,
            excluded: [],
            limits: [],
            issuedAt: null,
            expiresAt: null,
            heartbeatBy: null,
            graceUntil: null,
            lastContactAt: null,
            lastFailure: null,
            token: null,
        );
    }

    public static function fromToken(
        LicenceToken $token,
        string $wire,
        int $graceDays,
        ?CarbonImmutable $now = null,
    ): self {
        $now ??= CarbonImmutable::now();

        return new self(
            configured: true,
            licenceId: $token->licenceId,
            edition: $token->edition,
            status: $token->status,
            excluded: $token->excluded,
            limits: $token->limits,
            issuedAt: $token->issuedAt,
            expiresAt: $token->expiresAt,
            heartbeatBy: $token->heartbeatBy,
            // Counted from the heartbeat deadline, not from now: a vendor who
            // said "come back in seven days" and a grace of thirty means a
            // customer keeps working for thirty-seven, which is the promise
            // that makes the whole arrangement safe to ship.
            graceUntil: $token->heartbeatBy->addDays($graceDays),
            lastContactAt: $now,
            lastFailure: null,
            token: $wire,
        );
    }

    /**
     * The same licence, with a failed attempt recorded against it.
     *
     * The entitlements do not move. That is the point of grace: a failed
     * heartbeat inside the window changes nothing an operator can see except
     * the reason on the licence screen.
     */
    public function withFailure(string $reason): self
    {
        return new self(
            configured: $this->configured,
            licenceId: $this->licenceId,
            edition: $this->edition,
            status: $this->status,
            excluded: $this->excluded,
            limits: $this->limits,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            heartbeatBy: $this->heartbeatBy,
            graceUntil: $this->graceUntil,
            // Deliberately *not* updated: `lastContactAt` means "last time we
            // actually got an answer", and a failed attempt moving it would
            // make the licence screen say everything was fine.
            lastContactAt: $this->lastContactAt,
            lastFailure: $reason,
            token: $this->token,
        );
    }

    /**
     * Whether the entitlements this state carries still apply.
     *
     * Three questions, and all of them have to hold: the vendor still says
     * active, the licence has not run out, and the grace window has not closed.
     */
    public function isLive(?CarbonImmutable $now = null): bool
    {
        if (! $this->configured) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        return $this->status === LicenceStatus::Active
            && $this->expiresAt !== null && $this->expiresAt->isAfter($now)
            && $this->graceUntil !== null && $this->graceUntil->isAfter($now);
    }

    /**
     * Inside grace, but out of contact.
     *
     * The state the licence screen and the health check care about: everything
     * still works, and somebody should look before it stops.
     */
    public function isInGrace(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->isLive($now)
            && $this->heartbeatBy !== null
            && $this->heartbeatBy->isBefore($now);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'licence_id' => $this->licenceId,
            'edition' => $this->edition,
            'status' => $this->status->value,
            'excluded' => $this->excluded,
            'limits' => $this->limits,
            'issued_at' => $this->issuedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'heartbeat_by' => $this->heartbeatBy?->toIso8601String(),
            'grace_until' => $this->graceUntil?->toIso8601String(),
            'last_contact_at' => $this->lastContactAt?->toIso8601String(),
            'last_failure' => $this->lastFailure,
            'token' => $this->token,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        if (($stored['configured'] ?? false) !== true) {
            return self::unconfigured();
        }

        return new self(
            configured: true,
            licenceId: self::text($stored, 'licence_id'),
            edition: self::text($stored, 'edition'),
            status: LicenceStatus::tryFrom((string) ($stored['status'] ?? '')) ?? LicenceStatus::Revoked,
            excluded: self::list($stored, 'excluded'),
            limits: self::ints($stored, 'limits'),
            issuedAt: self::date($stored, 'issued_at'),
            expiresAt: self::date($stored, 'expires_at'),
            heartbeatBy: self::date($stored, 'heartbeat_by'),
            graceUntil: self::date($stored, 'grace_until'),
            lastContactAt: self::date($stored, 'last_contact_at'),
            lastFailure: self::text($stored, 'last_failure'),
            token: self::text($stored, 'token'),
        );
    }

    /**
     * Read from the installation's own state row.
     *
     * A row nobody can read is treated as unconfigured rather than thrown: a
     * malformed licence state is not a reason for the panel to stop answering,
     * and the health check will say the licence is unknown.
     */
    public static function load(): self
    {
        $row = PlatformState::query()->find(self::STATE_KEY);

        if ($row === null || ! is_array($row->value)) {
            return self::unconfigured();
        }

        /** @var array<string, mixed> $value */
        $value = $row->value;

        return self::fromArray($value);
    }

    public function save(): void
    {
        PlatformState::query()->updateOrCreate(
            ['key' => self::STATE_KEY],
            ['value' => $this->toArray(), 'updated_at' => CarbonImmutable::now()],
        );
    }

    public static function forget(): void
    {
        PlatformState::query()->where('key', self::STATE_KEY)->delete();
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function text(array $stored, string $key): ?string
    {
        $value = $stored[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<string>
     */
    private static function list(array $stored, string $key): array
    {
        $value = $stored[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, int>
     */
    private static function ints(array $stored, string $key): array
    {
        $value = $stored[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $ints = [];

        foreach ($value as $name => $limit) {
            if (is_string($name) && is_numeric($limit)) {
                $ints[$name] = (int) $limit;
            }
        }

        return $ints;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function date(array $stored, string $key): ?CarbonImmutable
    {
        $value = $stored[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
