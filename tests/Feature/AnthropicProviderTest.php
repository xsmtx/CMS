<?php

declare(strict_types=1);

use App\Domain\Ai\AiFeature;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InfraCMS\AiAnthropic\AnthropicProvider;

/**
 * The Claude adapter, against faked HTTP.
 *
 * **It has never spoken to Anthropic**, which the module's own docblock says
 * and the release checklist repeats about every adapter here: this proves the
 * request shape, the parsing and the refusals, and it does not prove the
 * integration.
 *
 * What is worth pinning is the four ways it can fail, because each one sends
 * an operator somewhere different — a missing key is a settings screen, a
 * refusal is "write it yourself", and a connection failure is the vendor.
 */
beforeEach(function (): void {
    loadModuleClasses('ai-anthropic');

    $this->provider = fn (?string $key = 'sk-test'): AnthropicProvider => new AnthropicProvider(
        model: 'claude-sonnet-5',
        baseUrl: 'https://api.anthropic.test',
        timeout: 5,
        apiKey: static fn (): ?string => $key,
    );

    $this->prompt = new AiPrompt(
        feature: AiFeature::TicketReply,
        task: 'Write the next reply.',
        context: [['label' => 'Subject', 'value' => 'Site is down']],
        maxTokens: 400,
    );
});

it('asks for a completion and reads the text back', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'claude-sonnet-5-20260101',
        'content' => [['type' => 'text', 'text' => 'We have restarted the pool.']],
        'usage' => ['input_tokens' => 140, 'output_tokens' => 36],
    ])]);

    $completion = ($this->provider)()->complete($this->prompt);

    expect($completion->text)->toBe('We have restarted the pool.')
        // What actually answered, not what was configured: a provider that
        // served a smaller model is one whose bill will not match the table.
        ->and($completion->model)->toBe('claude-sonnet-5-20260101')
        ->and($completion->tokens())->toBe(176);
});

it('sends the key, the pinned version and the prompt’s own ceiling', function (): void {
    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Fine.']],
    ])]);

    ($this->provider)()->complete($this->prompt);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->hasHeader('x-api-key', 'sk-test')
            // Pinned rather than left to the server's default, so a vendor
            // moving its default cannot change what this parses.
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $body['max_tokens'] === 400
            && str_contains((string) $body['messages'][0]['content'], 'Site is down');
    });
});

it('puts the seller’s instructions after the platform’s, not instead of them', function (): void {
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fine.']]])]);

    ($this->provider)()->complete(new AiPrompt(
        feature: AiFeature::TicketReply,
        task: 'Write a reply.',
        instructions: 'Answer in Turkish.',
    ));

    Http::assertSent(function (Request $request): bool {
        $system = (string) $request->data()['system'];

        return str_contains($system, 'draft that a human will read')
            && str_contains($system, 'Answer in Turkish.');
    });
});

/**
 * The API answers in blocks, and only the text ones are a draft.
 */
it('ignores a block that is not text', function (): void {
    Http::fake(['*' => Http::response(['content' => [
        ['type' => 'tool_use', 'id' => 'x', 'name' => 'whatever', 'input' => []],
        ['type' => 'text', 'text' => 'The real answer.'],
    ]])]);

    // Concatenating every block would put a tool-use payload into somebody's
    // reply box the first time the API grew one.
    expect(($this->provider)()->complete($this->prompt)->text)->toBe('The real answer.');
});

it('reports no credential rather than calling with none', function (): void {
    Http::fake();

    expect(fn () => ($this->provider)(null)->complete($this->prompt))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.no_credential');
        });

    Http::assertNothingSent();
});

it('reads a rejected key as a missing credential, not as an outage', function (): void {
    Http::fake(['*' => Http::response(['error' => 'x'], 401)]);

    // Those two send an operator to two different screens.
    expect(fn () => ($this->provider)()->complete($this->prompt))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.no_credential');
        });
});

it('reads anything else the vendor refuses as a refusal', function (): void {
    Http::fake(['*' => Http::response(['error' => 'overloaded'], 529)]);

    expect(fn () => ($this->provider)()->complete($this->prompt))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.refused');
        });
});

it('never lets a client library’s exception reach a reply box', function (): void {
    Http::fake(fn () => throw new ConnectionException('dns'));

    expect(fn () => ($this->provider)()->complete($this->prompt))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.unreachable');
        });
});
