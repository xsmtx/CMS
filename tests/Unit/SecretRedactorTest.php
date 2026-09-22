<?php

declare(strict_types=1);

use App\Support\Logging\SecretRedactor;

function redactor(bool $cardLike = true): SecretRedactor
{
    return new SecretRedactor(
        keys: ['password', 'token', 'secret', 'authorization', 'card', 'cvv'],
        placeholder: '[redacted]',
        maxDepth: 5,
        redactCardLikeValues: $cardLike,
    );
}

it('redacts values whose key contains a secret fragment', function (): void {
    $result = redactor()->redact([
        'email' => 'ops@example.com',
        'password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'api_token' => 'sk_live_abc',
    ]);

    expect($result['email'])->toBe('ops@example.com')
        ->and($result['password'])->toBe('[redacted]')
        ->and($result['password_confirmation'])->toBe('[redacted]')
        ->and($result['api_token'])->toBe('[redacted]');
});

it('matches keys regardless of casing', function (): void {
    $result = redactor()->redact(['Authorization' => 'Bearer abc', 'CVV' => '123']);

    expect($result['Authorization'])->toBe('[redacted]')
        ->and($result['CVV'])->toBe('[redacted]');
});

it('redacts nested structures', function (): void {
    $result = redactor()->redact([
        'gateway' => [
            'name' => 'stripe',
            'credentials' => ['secret_key' => 'sk_live_abc'],
        ],
    ]);

    expect($result['gateway']['name'])->toBe('stripe')
        ->and($result['gateway']['credentials']['secret_key'])->toBe('[redacted]');
});

it('stops descending at the configured depth instead of recursing forever', function (): void {
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'value']]]]]];

    $result = redactor()->redact($deep);

    expect($result)->toBeArray();
});

it('redacts card-like values even when the key looks innocent', function (): void {
    $result = redactor()->redact(['note' => 'customer gave 4242 4242 4242 4242 on the phone']);

    expect($result['note'])->toBe('customer gave [redacted] on the phone');
});

it('leaves card-like values alone when the safety net is disabled', function (): void {
    $result = redactor(cardLike: false)->redact(['note' => '4242424242424242']);

    expect($result['note'])->toBe('4242424242424242');
});

it('preserves non-string scalars', function (): void {
    $result = redactor()->redact(['count' => 5, 'active' => true, 'ratio' => 1.5, 'nothing' => null]);

    expect($result['count'])->toBe(5)
        ->and($result['active'])->toBeTrue()
        ->and($result['ratio'])->toBe(1.5)
        ->and($result['nothing'])->toBeNull();
});
