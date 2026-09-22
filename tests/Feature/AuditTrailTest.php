<?php

declare(strict_types=1);

use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\User;
use App\Support\Audit\Facades\Audit;

it('writes an audit record with actor, target and correlation identifier', function (): void {
    $actor = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $target = User::factory()->create();

    Audit::action('identity.user.updated')
        ->by($actor)
        ->on($target)
        ->because('Support ticket #42')
        ->write();

    $record = AuditLog::query()->sole();

    expect($record->action)->toBe('identity.user.updated')
        ->and($record->actor_id)->toBe($actor->id)
        ->and($record->actor_label)->toBe('Ada Lovelace <ada@example.com>')
        ->and($record->target_id)->toBe($target->id)
        ->and($record->reason)->toBe('Support ticket #42')
        ->and($record->correlation_id)->not->toBeNull();
});

it('records a diff of what actually changed', function (): void {
    Audit::action('settings.updated')
        ->bySystem('test')
        ->changed(['plan' => 'starter', 'region' => 'eu'], ['plan' => 'pro', 'region' => 'eu'])
        ->write();

    $record = AuditLog::query()->sole();

    expect($record->changes)->toBe(['plan' => ['from' => 'starter', 'to' => 'pro']]);
});

it('never writes a secret into the trail', function (): void {
    Audit::action('servers.credentials.rotated')
        ->bySystem('test')
        ->changed(
            ['api_token' => 'old-token', 'hostname' => 'node-1'],
            ['api_token' => 'new-token', 'hostname' => 'node-2'],
        )
        ->withMetadata(['password' => 'hunter2', 'port' => 2087])
        ->write();

    $record = AuditLog::query()->sole();

    expect($record->changes['api_token'])->toBe('[redacted]')
        ->and($record->changes['hostname'])->toBe(['from' => 'node-1', 'to' => 'node-2'])
        ->and($record->metadata['password'])->toBe('[redacted]')
        ->and($record->metadata['port'])->toBe(2087);
});

it('refuses to update a written record', function (): void {
    Audit::action('settings.updated')->bySystem('test')->write();

    $record = AuditLog::query()->sole();
    $record->action = 'settings.tampered';
    $record->save();
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete a written record', function (): void {
    Audit::action('settings.updated')->bySystem('test')->write();

    AuditLog::query()->sole()->delete();
})->throws(RuntimeException::class, 'append-only');

it('records an action performed with no actor at all', function (): void {
    Audit::action('automation.renewal.invoiced')->bySystem('scheduler')->write();

    $record = AuditLog::query()->sole();

    expect($record->actor_type)->toBeNull()
        ->and($record->actor_id)->toBeNull()
        ->and($record->actor_label)->toBe('scheduler');
});

it('groups records written during one unit of work under one correlation id', function (): void {
    Audit::action('orders.placed')->bySystem('test')->write();
    Audit::action('invoices.issued')->bySystem('test')->write();

    $ids = AuditLog::query()->pluck('correlation_id')->unique();

    expect($ids)->toHaveCount(1);
});

it('can be faked so a feature test asserts intent without touching the database', function (): void {
    $audit = $this->fakeAudit();

    Audit::action('services.suspended')->bySystem('test')->because('Overdue')->write();

    $audit->assertRecorded('services.suspended', fn ($entry): bool => $entry->reason === 'Overdue');

    expect(AuditLog::query()->count())->toBe(0);
});
