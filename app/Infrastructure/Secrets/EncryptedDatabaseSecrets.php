<?php

declare(strict_types=1);

namespace App\Infrastructure\Secrets;

use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\Exceptions\MissingSecretBoundary;
use App\Domain\Secrets\SecretReference;
use App\Infrastructure\Secrets\Models\SecretRecord;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;

/**
 * The vault every installation has, because it needs no other software.
 *
 * Encrypted with the application key, in the application's own database,
 * owned by an organization. A `vault-hashicorp` module can implement the same
 * contract later against somebody else's; nothing above `SecretStore` knows
 * which one answered, which is the entire point of the seam.
 *
 * **Writes are audited, reads are not.** An audit row per read would be an
 * audit log made of polling — a scheduler reading one token every minute
 * writes 1,440 rows a day and buries the one row that matters. What matters
 * is that somebody changed a credential, and that is what is recorded:
 * `secrets.written`, `secrets.rotated`, `secrets.destroyed`, each naming the
 * reference and never the value.
 *
 * **Writing over a value is a rotation**, and it says so. The previous value
 * is gone — there is no history table, because a credential kept for history
 * is a credential somebody can still read.
 */
final readonly class EncryptedDatabaseSecrets implements SecretStore
{
    public function __construct(
        private OrganizationContext $organizations,
        private CurrentActor $actor,
    ) {}

    public function put(SecretReference $reference, string $value): void
    {
        $existing = $this->find($reference);

        if ($existing instanceof SecretRecord) {
            $existing->forceFill([
                'value' => $value,
                'last_rotated_at' => CarbonImmutable::now(),
            ])->save();

            $this->record('secrets.rotated', $existing);

            return;
        }

        $record = new SecretRecord;
        $record->forceFill([
            'organization_id' => $this->organizationId(),
            'reference' => $reference->key(),
            'value' => $value,
            'last_rotated_at' => CarbonImmutable::now(),
        ])->save();

        $this->record('secrets.written', $record);
    }

    public function get(SecretReference $reference): ?string
    {
        $record = $this->find($reference);

        return $record instanceof SecretRecord ? $record->value : null;
    }

    public function has(SecretReference $reference): bool
    {
        return $this->find($reference) instanceof SecretRecord;
    }

    public function forget(SecretReference $reference): void
    {
        $record = $this->find($reference);

        if (! $record instanceof SecretRecord) {
            return;
        }

        // Audited before it is gone: the row is what the audit record points
        // at, and a deleted row has no organization to file it under.
        $this->record('secrets.destroyed', $record);

        $record->delete();
    }

    private function find(SecretReference $reference): ?SecretRecord
    {
        return SecretRecord::query()->where('reference', $reference->key())->first();
    }

    private function record(string $action, SecretRecord $record): void
    {
        /*
         * The reference, never the value, and never a `changed()` pair: the
         * audit log is the one table this product keeps forever, and a
         * before/after of a credential would be the only place in it holding
         * one in the clear.
         */
        Audit::action($action)
            ->by($this->actor->model())
            ->on($record)
            ->forOrganization($record->organization_id)
            ->withMetadata(['reference' => $record->reference])
            ->write();
    }

    private function organizationId(): string
    {
        $id = $this->organizations->id();

        if (! is_string($id) || $id === '') {
            // A secret with no owner is a secret no boundary hides. Refusing
            // is the only safe answer: the alternative is a row every
            // organization can read.
            throw MissingSecretBoundary::forWrite();
        }

        return $id;
    }
}
