<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\NetworkDeviceProvider;
use App\Domain\Infrastructure\Contracts\NetworkDeviceWriter;
use App\Domain\Infrastructure\Network\DeviceConfiguration;
use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Back it up, check the device has not moved, apply it, read it back.
 *
 * The only thing in this product that changes a network device, called from a
 * job and from nowhere else. Every refusal below is a step §6 names, in the
 * order §6 names them, and the order is not a suggestion.
 *
 * **1. Something must be permitted to write.** Not "an adapter exists that
 * can": `AdapterRegistry` narrows a capability away entirely unless an
 * operator enabled it on the row, so a device nobody turned writes on for is a
 * refusal here and a button that was never offered on the screen.
 *
 * **2. Back up first.** A backup that failed is a change that does not happen.
 * The text is stored on the change, so a rollback has something to put back
 * without asking a device that may by then be unreachable.
 *
 * **3. The device must be where the diff left it.** The fingerprint is
 * recomputed from what the box says *now* and compared with the one stored
 * when the change was requested. This is the whole point of the workflow: a
 * diff somebody approved an hour ago is a diff against a device somebody else
 * may have edited since, and applying it would silently revert their work.
 *
 * **4. Apply, then read it back.** Verification is a second read rather than
 * trusting the adapter's return: a device that accepted a configuration and
 * did not keep it is exactly the failure worth catching, and it is not a
 * failure an adapter can report.
 *
 * **5. A failed verify rolls back.** The backup goes on, and the record says
 * `rolled_back` rather than `failed` — an operator arriving at three in the
 * morning needs to know whether the box is where it started.
 *
 * Nothing here runs inside a database transaction. The remote calls are the
 * slow part and holding a transaction across one is the rule this product has
 * had since provisioning (ADR 0026); the row is saved at each step instead, so
 * a process that dies mid-apply leaves a record saying where it got to.
 */
final readonly class ApplyNetworkChange
{
    public function __construct(
        private AdapterRegistry $registry,
        private SecretRedactor $redactor,
    ) {}

    public function handle(NetworkChange $change): NetworkChange
    {
        if (! $change->state->isApplicable() && $change->state !== NetworkChangeState::Applying) {
            throw ChangeRefused::notApplicable($change->state->value);
        }

        $device = $change->device;

        if ($device === null) {
            throw ChangeRefused::deviceNotReadable('the device');
        }

        $key = $device->node_key;

        $change->state = NetworkChangeState::Applying;
        $change->save();

        try {
            /*
             * Inside the try, not before it. Every reason an apply does not go
             * ahead belongs on the change where an operator reads it — a
             * refusal thrown out of here would leave the row saying
             * `authorized` while the operation beside it said failed, and the
             * two would disagree about the same event.
             *
             * The state check above is the exception, because "you may not
             * apply a rejected change" is a caller's mistake rather than an
             * outcome.
             */
            $registered = $this->writerFor($device->organization_id, $key);

            $backup = $this->backUp($registered, $key);

            $change->backup = $backup->text;
            $change->backed_up_at = CarbonImmutable::now();
            $change->save();

            // Step three, and the reason all of this exists.
            if ($backup->fingerprint() !== $change->fingerprint_before) {
                throw ChangeRefused::deviceMoved($key);
            }

            /** @var NetworkDeviceWriter $writer */
            $writer = $registered->adapter();
            $writer->applyConfiguration($key, $change->intended);
        } catch (ChangeRefused $refusal) {
            return $this->fail($change, $refusal->getMessage());
        } catch (Throwable $exception) {
            return $this->fail($change, $this->redactor->redactString($exception->getMessage()));
        }

        return $this->verify($change, $registered, $key);
    }

    /**
     * Read it back, and put the backup on if it did not take.
     *
     * A second read rather than the adapter's word: a device that accepted a
     * configuration and did not keep it is the failure worth catching, and no
     * adapter can report it.
     */
    private function verify(NetworkChange $change, RegisteredAdapter $registered, string $key): NetworkChange
    {
        $reader = $registered->adapter();

        if (! $reader instanceof NetworkDeviceProvider) {
            // Nothing can read it back. The change went out and this platform
            // cannot say whether it stuck, which is stated rather than
            // reported as success.
            return $this->complete($change, 'Applied. This adapter cannot read the configuration back.');
        }

        try {
            $after = $reader->configuration($key);
        } catch (Throwable $exception) {
            return $this->fail($change, $this->redactor->redactString($exception->getMessage()));
        }

        if (! ConfigurationDiff::differ($after->text, $change->intended)) {
            return $this->complete($change, 'Applied and verified.');
        }

        return $this->rollBack($change, $registered, $key);
    }

    private function rollBack(NetworkChange $change, RegisteredAdapter $registered, string $key): NetworkChange
    {
        $backup = $change->backup;

        if ($backup === null) {
            return $this->fail($change, 'The device did not keep the configuration, and there was no backup to put back.');
        }

        /** @var NetworkDeviceWriter $writer */
        $writer = $registered->adapter();

        try {
            $writer->restoreConfiguration($key, new DeviceConfiguration(
                target: $key,
                text: $backup,
                retrievedAt: $change->backed_up_at ?? CarbonImmutable::now(),
            ));
        } catch (Throwable $exception) {
            // The worst outcome this workflow has, and it is stated plainly:
            // the device has neither the old configuration nor the new one.
            return $this->fail(
                $change,
                'The device did not keep the configuration and the backup could not be put back: '
                .$this->redactor->redactString($exception->getMessage()),
            );
        }

        $change->state = NetworkChangeState::RolledBack;
        $change->result = 'The device did not keep the configuration. The backup was put back.';
        $change->applied_at = CarbonImmutable::now();
        $change->save();

        return $change;
    }

    private function backUp(RegisteredAdapter $registered, string $key): DeviceConfiguration
    {
        $reader = $registered->adapter();

        if (! $reader instanceof NetworkDeviceProvider) {
            throw ChangeRefused::backupFailed($key);
        }

        try {
            return $reader->configuration($key);
        } catch (Throwable) {
            throw ChangeRefused::backupFailed($key);
        }
    }

    private function complete(NetworkChange $change, string $result): NetworkChange
    {
        $change->state = NetworkChangeState::Completed;
        $change->result = $result;
        $change->applied_at = CarbonImmutable::now();
        $change->save();

        return $change;
    }

    private function fail(NetworkChange $change, string $result): NetworkChange
    {
        $change->state = NetworkChangeState::Failed;
        $change->result = $result;
        $change->applied_at = CarbonImmutable::now();
        $change->save();

        return $change;
    }

    /**
     * The adapter this installation permits to change this device.
     *
     * `permitted()` rather than `capabilities()`: the package says what is
     * possible and the row says what this installation allows, and a write an
     * operator never enabled is absent rather than refused later.
     */
    private function writerFor(string $organizationId, string $key): RegisteredAdapter
    {
        foreach ($this->registry->all($organizationId) as $registered) {
            if (! $registered->adapter() instanceof NetworkDeviceWriter) {
                continue;
            }

            if ($registered->permitted()->has(Capability::DeviceConfigWrite)) {
                return $registered;
            }
        }

        throw ChangeRefused::noWriter($key);
    }
}
