<?php

declare(strict_types=1);

use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\Exceptions\InvalidSecretReference;
use App\Domain\Secrets\SecretReference;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Secrets\Models\SecretRecord;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The credential vault (`advanced-operations-plan.md` §7).
 *
 * Six encrypted columns were right for handoff #1 and are wrong for
 * twenty-three adapter families. What these assert is the part that is easy
 * to get wrong and impossible to notice: that the value is encrypted at rest,
 * that it never reaches an array, an audit row or a boundary it does not
 * belong to, and that writing over one is a rotation rather than a quiet
 * overwrite.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->store = app(SecretStore::class);
    $this->reference = new SecretReference('monitoring', 'token', 'prom-one');
});

it('gives back what was put in', function (): void {
    $this->store->put($this->reference, 'a-very-secret-token');

    expect($this->store->get($this->reference))->toBe('a-very-secret-token')
        ->and($this->store->has($this->reference))->toBeTrue();
});

it('answers null for a credential nobody has configured', function (): void {
    expect($this->store->get(new SecretReference('monitoring', 'token', 'never-set')))->toBeNull()
        ->and($this->store->has(new SecretReference('monitoring', 'token', 'never-set')))->toBeFalse();
});

/**
 * The column is what a stolen backup contains, so this is the assertion that
 * matters most: what is on disk is not the token.
 */
it('encrypts the value at rest', function (): void {
    $this->store->put($this->reference, 'a-very-secret-token');

    $raw = DB::table('secrets')->where('reference', $this->reference->key())->value('value');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toContain('a-very-secret-token');
});

/**
 * A model that serialises its own secret is a model somebody will eventually
 * dump, return as JSON or hand to a queue.
 */
it('keeps the value out of every array the model makes', function (): void {
    $this->store->put($this->reference, 'a-very-secret-token');

    $record = SecretRecord::query()->where('reference', $this->reference->key())->sole();

    expect($record->toArray())->not->toHaveKey('value')
        ->and(json_encode($record))->not->toContain('a-very-secret-token');
});

it('writes an audit row that names the reference and never the value', function (): void {
    $staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->actingAs($staff, 'staff');

    $this->store->put($this->reference, 'a-very-secret-token');

    $entry = AuditLog::query()->where('action', 'secrets.written')->sole();

    expect($entry->metadata['reference'] ?? null)->toBe('monitoring/token/prom-one')
        ->and(json_encode($entry->toArray()))->not->toContain('a-very-secret-token');
});

it('calls writing over a value a rotation', function (): void {
    $this->store->put($this->reference, 'first');
    $this->store->put($this->reference, 'second');

    expect($this->store->get($this->reference))->toBe('second')
        ->and(SecretRecord::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'secrets.rotated')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'secrets.written')->count())->toBe(1);
});

it('destroys a credential rather than keeping it for history', function (): void {
    $this->store->put($this->reference, 'a-very-secret-token');
    $this->store->forget($this->reference);

    expect($this->store->get($this->reference))->toBeNull()
        ->and(DB::table('secrets')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'secrets.destroyed')->count())->toBe(1);
});

/**
 * A reseller's Prometheus token is not the provider's, and the boundary is
 * what says so.
 */
it('never hands one organization another organizations credential', function (): void {
    $this->store->put($this->reference, 'the-providers-token');

    $reseller = Organization::factory()->reseller($this->provider)->create();
    app(OrganizationContext::class)->set($reseller->id);

    expect($this->store->get($this->reference))->toBeNull();

    $this->store->put($this->reference, 'the-resellers-token');

    expect($this->store->get($this->reference))->toBe('the-resellers-token');

    app(OrganizationContext::class)->set($this->provider->id);

    expect($this->store->get($this->reference))->toBe('the-providers-token');
});

it('refuses a reference with a part that would collide with another one', function (): void {
    expect(fn (): SecretReference => new SecretReference('monitoring', 'token', 'one/two'))
        ->toThrow(InvalidSecretReference::class);

    expect(fn (): SecretReference => new SecretReference('Monitoring', 'token', 'one'))
        ->toThrow(InvalidSecretReference::class);

    expect(fn (): SecretReference => new SecretReference('monitoring', 'token', ''))
        ->toThrow(InvalidSecretReference::class);
});

it('reads a reference back from its stored form', function (): void {
    $parsed = SecretReference::parse('monitoring/token/prom-one');

    expect($parsed->area)->toBe('monitoring')
        ->and($parsed->kind)->toBe('token')
        ->and($parsed->owner)->toBe('prom-one')
        ->and((string) $parsed)->toBe('monitoring/token/prom-one');

    expect(fn (): SecretReference => SecretReference::parse('monitoring/token'))
        ->toThrow(InvalidSecretReference::class);
});
