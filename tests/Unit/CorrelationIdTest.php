<?php

declare(strict_types=1);

use App\Support\Correlation\CorrelationId;

it('generates a 26 character ULID', function (): void {
    $id = CorrelationId::generate();

    expect($id->value)->toHaveLength(26)
        ->and(CorrelationId::tryFrom($id->value))->not->toBeNull();
});

it('accepts a well formed ULID and normalises its case', function (): void {
    $id = CorrelationId::tryFrom('01k5q8zp4f8t7m0qx9j0k3n2vb');

    expect($id)->not->toBeNull()
        ->and($id->value)->toBe('01K5Q8ZP4F8T7M0QX9J0K3N2VB');
});

it('accepts a well formed UUID', function (): void {
    $id = CorrelationId::tryFrom('9B1DEB4D-3B7D-4BAD-9BDD-2B0D7B3DCB6D');

    expect($id)->not->toBeNull()
        ->and($id->value)->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');
});

it('rejects identifiers that could poison log aggregation', function (string $candidate): void {
    expect(CorrelationId::tryFrom($candidate))->toBeNull();
})->with([
    'empty' => '',
    'too short' => '01K5Q8ZP4F',
    'newline injection' => "01K5Q8ZP4F8T7M0QX9J0K3N2VB\nfake log line",
    'html' => '<script>alert(1)</script>',
    'ulid with excluded letters' => '01K5Q8ZP4F8T7M0QX9J0K3N2VI',
    'sql' => "' OR 1=1 --",
]);

it('rejects a null identifier', function (): void {
    expect(CorrelationId::tryFrom(null))->toBeNull();
});
