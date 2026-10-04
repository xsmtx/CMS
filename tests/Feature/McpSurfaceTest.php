<?php

declare(strict_types=1);

use App\Domain\Api\StaffApiScope;
use App\Domain\Mcp\McpTool;

/**
 * What MCP is, and what it is not (ADR 0051).
 *
 * The decision this file keeps is that **every tool is a read**. Not "writes
 * behind a confirmation", not "writes for some scopes": none. An API endpoint
 * is called by a program somebody wrote, once, deliberately; a tool call is a
 * model inferring it should act, from text that on a support surface a
 * customer wrote.
 *
 * Somebody adding a write tool in six months meets this decision here rather
 * than discovering it after it ships — which is the whole reason the rule is a
 * test and not a sentence in a document.
 */
it('has an MCP surface at all', function (): void {
    // The guard on the guard: an audit that examined nothing would pass.
    expect(McpTool::cases())->not->toBeEmpty();
});

it('gives every tool a scope that exists and is a read', function (): void {
    foreach (McpTool::cases() as $tool) {
        $scope = $tool->scope();

        expect(StaffApiScope::tryFrom($scope->value))->toBeInstanceOf(StaffApiScope::class)
            ->and($scope->isWrite())->toBeFalse(
                $tool->value.' names '.$scope->value.', which writes.',
            )
            ->and($scope->value)->toEndWith(':read');

        // And the scope narrows rather than grants: a token carrying it
        // reaches nothing unless its holder holds the permissions behind it.
        expect($scope->requiredPermissions())->not->toBeEmpty();
    }
});

/**
 * Two rules, and the positive one is the stronger.
 */
it('names every tool as a read, and after no verb that changes the world', function (): void {
    /*
     * `grant` is deliberately not in the list below: `access_grants_list`
     * reads who holds a grant, and the noun collides with the verb. That
     * collision is what showed the word list was the weaker half of this
     * rule — a name ending in `_list` or `_get` says positively what a tool
     * is, where a list of banned words only says what somebody happened to
     * think of.
     */
    foreach (McpTool::cases() as $tool) {
        expect(
            str_ends_with($tool->value, '_list') || str_ends_with($tool->value, '_get'),
        )->toBeTrue($tool->value.' is not named as a read.');
    }

    // The verbs that are never nouns, kept as a second net.
    $forbidden = [
        'create', 'update', 'delete', 'write', 'apply', 'approve',
        'reply', 'resolve', 'suspend', 'terminate', 'restore', 'drain',
        'power', 'reboot', 'revoke', 'send', 'run',
    ];

    foreach (McpTool::cases() as $tool) {
        foreach ($forbidden as $word) {
            expect($tool->value)->not->toContain($word);
        }
    }
});

/**
 * A query tool would be an unscoped read with a model composing the filter —
 * the reason `ResourceTree` walks a level at a time rather than writing SQL.
 */
it('takes no free-text query from a model', function (): void {
    foreach (McpTool::cases() as $tool) {
        /** @var array<string, mixed> $properties */
        $properties = (array) ($tool->schema()['properties'] ?? []);

        foreach (array_keys($properties) as $name) {
            expect((string) $name)->not->toContain('query')
                ->and((string) $name)->not->toContain('sql')
                ->and((string) $name)->not->toContain('filter');
        }
    }
});

it('describes every tool in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (McpTool::cases() as $tool) {
            $description = (string) __($tool->descriptionKey());

            // A description is the whole interface here: it is the only thing
            // a model reads when deciding whether a tool answers the question
            // in front of it, so an unworded one is a tool called for the
            // wrong reason.
            expect($description)->not->toBe($tool->descriptionKey(), $tool->value.' in '.$locale)
                ->and(strlen($description))->toBeGreaterThan(40);
        }
    }

    app()->setLocale('en');
});
