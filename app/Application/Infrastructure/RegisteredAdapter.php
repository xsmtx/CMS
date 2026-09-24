<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterDescriptor;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * An adapter, its row, and the narrowing between them.
 *
 * The pair exists because neither half is enough on its own: the package says
 * what is possible and the row says what this installation allows, and every
 * caller needs the intersection. Handing out the raw adapter would let a caller
 * reach a capability the operator never enabled.
 */
final readonly class RegisteredAdapter
{
    public function __construct(
        public AdapterDescriptor $descriptor,
        public ResourceAdapter $row,
        private InfrastructureAdapter $adapter,
    ) {}

    /**
     * What may actually be asked of it here.
     *
     * Writes vanish unless the row has them enabled — not refused later, absent
     * now, so that a screen built from this cannot offer a button the platform
     * would then refuse.
     */
    public function permitted(): CapabilitySet
    {
        // The rule lives on the row and is asked here, rather than being
        // written out twice. It was written out twice once, and the two copies
        // disagreed about a disabled adapter.
        return $this->row->narrow($this->descriptor->capabilities);
    }

    /**
     * The live adapter, for a caller that has already checked a capability.
     *
     * Named to be conspicuous at the call site. There is no way to make this
     * impossible to misuse — somebody with the object can call any method it has
     * — so it is instead impossible to do *accidentally*: `permitted()` is the
     * normal path and this reads as a decision.
     */
    public function adapter(): InfrastructureAdapter
    {
        return $this->adapter;
    }

    /**
     * Ask the remote how it is, and write down the answer.
     *
     * Anything thrown becomes `failing` with a redacted message, because an
     * adapter author should not have to write a try/catch to report that a device
     * is down — and because an exception message from a device is the most likely
     * place a credential reaches a database column.
     */
    public function checkHealth(SecretRedactor $redactor): AdapterHealth
    {
        try {
            $health = $this->adapter->health();
        } catch (Throwable $exception) {
            $health = AdapterHealth::failing($redactor->redactString($exception->getMessage()));
        }

        $this->row->health = $health->state->value;
        $this->row->health_message = $health->message === null
            ? null
            : $redactor->redactString($health->message);
        $this->row->remote_version = $health->remoteVersion;
        $this->row->supported = $health->supported;
        $this->row->health_checked_at = $health->checkedAt ?? CarbonImmutable::now();
        $this->row->save();

        return $health;
    }

    public function state(): ?HealthState
    {
        return $this->row->healthState();
    }
}
