<?php

declare(strict_types=1);

namespace InfraCMS\PduServertech;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\EnvironmentProvider;
use App\Domain\Infrastructure\Contracts\PowerProvider;
use App\Domain\Infrastructure\Exceptions\PduUnreachable;
use App\Domain\Infrastructure\Power\EnvironmentReading;
use App\Domain\Infrastructure\Power\PowerFeed;
use App\Domain\Infrastructure\Power\PowerOutlet;
use App\Domain\Infrastructure\Power\SensorKind;
use App\Domain\Infrastructure\RateLimits;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A Server Technology PDU, over the JAWS API.
 *
 * Four things about that API that only a careful read turns up, each pinned
 * by a test:
 *
 * - **An outlet's name is the only place the device is recorded.** There is
 *   no field for "what is plugged in": an operator types the machine's name
 *   into the socket's label, which is what every datacenter does and what
 *   this adapter therefore reads. A blank or a default name (`Outlet A1`)
 *   means nobody said, and is kept as nothing rather than as a device called
 *   `Outlet A1`.
 * - **`state` is a word, not a boolean**: `On`, `Off`, `OffWait`, `OnWait`,
 *   `OffError`. The two transitional ones are neither, and reading them as
 *   off would make a socket somebody is switching look already dead.
 * - **Power comes back as a string with its unit in it**, and in kilowatts on
 *   some firmware. A parse that assumed watts would be a thousand times
 *   wrong, which is a rack that looks empty.
 * - **Sensors are a separate collection and are optional.** A PDU with no
 *   probe answers 404 rather than an empty list, and that is not a failure:
 *   an adapter that threw would make every sweep on every unprobed PDU look
 *   like an outage.
 *
 * **Nothing here switches an outlet.** `Capability::PduOutletWrite` is not
 * declared: cutting the power to whatever is plugged in has none of the
 * warning a hypervisor's shutdown gives, and afterwards this platform could
 * not tell a machine that did not come back from a machine that was never on.
 *
 * It has never talked to a real PDU. Every request shape and every parse here
 * is tested against faked HTTP, which proves the code and not the integration.
 */
final readonly class ServertechProvider implements EnvironmentProvider, PowerProvider
{
    /**
     * Names a PDU gives a socket nobody has labelled.
     *
     * Matching these means "nobody said", which is different from a device
     * called `Outlet A1` — and the difference is an edge in the graph.
     */
    private const string DefaultOutletName = '/^(outlet|tower)[ _-]?[a-z]?\d+$/i';

    /**
     * @param  Closure(): ?string  $password
     */
    public function __construct(
        private string $baseUrl,
        private string $username,
        private Closure $password,
        private PowerFeed $feed = PowerFeed::Unknown,
        private bool $verifyTls = true,
        private int $timeout = 10,
    ) {}

    public function key(): string
    {
        return 'servertech';
    }

    public function name(): string
    {
        return 'Server Technology PDU';
    }

    public function vendor(): string
    {
        return 'Server Technology';
    }

    public function capabilities(): CapabilitySet
    {
        // The read, and not the switch this PDU would accept.
        return CapabilitySet::of([Capability::PduLoadRead]);
    }

    public function limits(): RateLimits
    {
        /*
         * A PDU is a small computer on a slow network and its web server is
         * the least robust thing in the rack. One request at a time.
         */
        return new RateLimits(perMinute: 30, concurrency: 1, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/jaws/monitor/units');
        } catch (Throwable) {
            return AdapterHealth::failing('The PDU could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The PDU refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The PDU answered '.$response->status().'.');
        }

        $rows = $response->json();
        $first = is_array($rows) ? ($rows[0] ?? null) : null;
        $version = is_array($first) ? $this->text($first, 'firmware') : null;

        return new AdapterHealth(
            HealthState::Ok,
            'The PDU answered.',
            remoteVersion: $version,
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<PowerOutlet>
     */
    public function outlets(): array
    {
        $outlets = [];

        foreach ($this->rows('/jaws/monitor/outlets') as $row) {
            $id = $this->text($row, 'id');

            if ($id === null) {
                continue;
            }

            $name = $this->text($row, 'name') ?? $id;

            $outlets[] = new PowerOutlet(
                key: $id,
                name: $name,
                // Which of the rack's two this PDU is, from configuration:
                // no PDU knows which of a pair it is, and an operator does.
                feed: $this->feed,
                on: $this->state($row),
                watts: $this->watts($row),
                amps: $this->number($row, 'current'),
                breaker: $this->text($row, 'branch_id') ?? $this->text($row, 'line_id'),
                // The socket's label is the only place the device is
                // recorded. A default name means nobody said.
                deviceKey: preg_match(self::DefaultOutletName, $name) === 1 ? null : $name,
            );
        }

        return $outlets;
    }

    /**
     * @return list<EnvironmentReading>
     */
    public function sensors(): array
    {
        $readings = [];

        /*
         * Optional. A PDU with no probe answers 404, and an adapter that
         * threw would make every sweep on every unprobed PDU look like an
         * outage.
         */
        foreach ($this->rows('/jaws/monitor/sensors/temperature', optional: true) as $row) {
            $reading = $this->sensor($row, SensorKind::Temperature, 'temperature');

            if ($reading instanceof EnvironmentReading) {
                $readings[] = $reading;
            }
        }

        foreach ($this->rows('/jaws/monitor/sensors/humidity', optional: true) as $row) {
            $reading = $this->sensor($row, SensorKind::Humidity, 'humidity');

            if ($reading instanceof EnvironmentReading) {
                $readings[] = $reading;
            }
        }

        foreach ($this->rows('/jaws/monitor/sensors/water', optional: true) as $row) {
            $id = $this->text($row, 'id');

            if ($id === null) {
                continue;
            }

            $readings[] = new EnvironmentReading(
                key: $id,
                name: $this->text($row, 'name') ?? $id,
                kind: SensorKind::Leak,
                // A state, never a number — and null where the probe did not
                // say, because a leak detector nobody heard from has not
                // reported dry.
                triggered: match ($this->text($row, 'status')) {
                    'Alarm', 'Wet' => true,
                    'Normal', 'Dry' => false,
                    default => null,
                },
                location: $this->text($row, 'sensor_id'),
            );
        }

        return $readings;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sensor(array $row, SensorKind $kind, string $field): ?EnvironmentReading
    {
        $id = $this->text($row, 'id');

        if ($id === null) {
            return null;
        }

        return new EnvironmentReading(
            key: $id,
            name: $this->text($row, 'name') ?? $id,
            kind: $kind,
            value: $this->number($row, $field) ?? $this->number($row, 'value'),
            location: $this->text($row, 'sensor_id'),
        );
    }

    /**
     * `On`, `Off`, and the two that are neither.
     *
     * Reading `OnWait` as off would make a socket somebody is switching look
     * already dead, so the transitional states answer null — "the PDU did not
     * say", which is what they mean.
     *
     * @param  array<string, mixed>  $row
     */
    private function state(array $row): ?bool
    {
        return match ($this->text($row, 'state')) {
            'On' => true,
            'Off' => false,
            default => null,
        };
    }

    /**
     * Power, in watts, whatever the PDU wrote it in.
     *
     * Some firmware answers a number and some a string with its unit in it,
     * and kilowatts appear on the larger units. A parse that assumed watts
     * would be a thousand times wrong, which is a rack that looks empty.
     *
     * @param  array<string, mixed>  $row
     */
    private function watts(array $row): ?float
    {
        $value = $row['power'] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(kw|w)?\s*$/i', $value, $match) !== 1) {
            return null;
        }

        $number = (float) $match[1];

        return strtolower($match[2] ?? 'w') === 'kw' ? $number * 1000 : $number;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $path, bool $optional = false): array
    {
        try {
            $response = $this->request()->get($path);
        } catch (Throwable) {
            throw PduUnreachable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw PduUnreachable::refused($this->key());
        }

        if ($optional && $response->status() === 404) {
            // No probe fitted. Not a failure.
            return [];
        }

        if (! $response->successful()) {
            throw PduUnreachable::answered($this->key(), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PduUnreachable::unreadable($this->key(), 'no list where one was expected');
        }

        $rows = [];

        foreach ($body as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function number(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $password = ($this->password)();

        // Basic, which is all JAWS speaks. A missing password still makes the
        // request, so the PDU's own 401 is what the operator is told.
        return is_string($password) && $password !== ''
            ? $request->withBasicAuth($this->username, $password)
            : $request;
    }
}
