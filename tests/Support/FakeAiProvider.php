<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Exceptions\AiUnavailable;

/**
 * A provider that answers without a vendor (ADR 0050).
 *
 * It keeps **the last prompt it was handed**, which is what the tests about
 * this family actually care about: the question is not whether a model can
 * write a reply, it is whether a customer's address, tax id or card ever left
 * this installation. `AiPromptTest` reads `$lastPrompt` and asserts what is
 * not in it.
 *
 * `Tests\Support\FakeLicenceClient`'s shape, for the same reason: the vendor
 * is a separate system this repository does not contain, and a test that
 * reached one would be a test that fails when somebody else's service does.
 */
final class FakeAiProvider implements AiProvider
{
    public ?AiPrompt $lastPrompt = null;

    public int $calls = 0;

    public function __construct(
        private readonly string $answer = 'Thanks for getting in touch — we have restarted the pool.',
        private readonly ?AiUnavailable $refusal = null,
        private readonly string $key = 'fake',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        $this->calls++;
        $this->lastPrompt = $prompt;

        if ($this->refusal instanceof AiUnavailable) {
            throw $this->refusal;
        }

        return new AiCompletion(
            text: $this->answer,
            model: $this->key.'-1',
            promptTokens: 120,
            completionTokens: 80,
        );
    }
}
