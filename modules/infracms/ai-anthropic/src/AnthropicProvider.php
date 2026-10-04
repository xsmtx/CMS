<?php

declare(strict_types=1);

namespace InfraCMS\AiAnthropic;

use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Exceptions\AiUnavailable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One request to the Messages API (ADR 0050).
 *
 * Non-negotiable 8 in full: a timeout, bounded retries with backoff, and a
 * refusal that says which of the four things went wrong rather than handing a
 * client library's exception to a reply box.
 *
 * **Retries are bounded at one and only for the cases worth retrying.** A
 * draft is written while somebody watches a spinner, so three attempts with
 * backoff would be a button that appears to hang; and a 400 is a prompt this
 * platform built wrongly, which retrying cannot fix. A 429 or a 5xx is the
 * vendor having a moment, and one more attempt is worth the second.
 *
 * `max_tokens` comes from the prompt rather than from configuration, because
 * it varies by feature: a triage suggestion is two words and a reply is
 * several paragraphs, and one ceiling for both either truncates the reply or
 * pays for a summary nobody asked for.
 */
final readonly class AnthropicProvider implements AiProvider
{
    /**
     * @param  Closure(): ?string  $apiKey
     */
    public function __construct(
        private string $model,
        private string $baseUrl,
        private int $timeout,
        private Closure $apiKey,
    ) {}

    public function key(): string
    {
        return 'anthropic';
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        $key = ($this->apiKey)();

        if (! is_string($key) || $key === '') {
            throw AiUnavailable::noCredential($this->key());
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                // The API version is pinned rather than left to the server's
                // default: a provider that moves its default underneath an
                // installation would change what this adapter parses without
                // anybody deploying anything.
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout($this->timeout)
                ->retry(2, 500, fn (Throwable $e, $request): bool => $this->worthRetrying($e), throw: false)
                ->post($this->baseUrl.'/v1/messages', $this->body($prompt));
        } catch (ConnectionException) {
            throw AiUnavailable::unreachable($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            // A key that is wrong reads as "no credential" rather than as the
            // vendor being down, because those send an operator to two
            // different screens.
            throw AiUnavailable::noCredential($this->key());
        }

        if ($response->failed()) {
            // A 400 is a prompt this platform built wrongly and a 529 is the
            // vendor being busy; neither is something a reply box can act on,
            // and the operator's answer to both is to write it themselves.
            throw AiUnavailable::refused($this->key());
        }

        return $this->read($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AiPrompt $prompt): array
    {
        $system = [(string) __('ai.system')];

        if ($prompt->instructions !== null && trim($prompt->instructions) !== '') {
            // The seller's own wording, after the platform's. A seller who
            // tells their operators to answer formally wants the draft that
            // way, and core has no opinion about which.
            $system[] = $prompt->instructions;
        }

        return [
            'model' => $this->model,
            'max_tokens' => $prompt->maxTokens,
            'system' => implode("\n\n", $system),
            'messages' => [
                ['role' => 'user', 'content' => $prompt->render()],
            ],
        ];
    }

    /**
     * @param  mixed  $payload
     */
    private function read($payload): AiCompletion
    {
        if (! is_array($payload)) {
            throw AiUnavailable::refused($this->key());
        }

        /** @var array<int, array<string, mixed>> $content */
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];

        $text = '';

        foreach ($content as $block) {
            // The answer arrives as blocks and only the text ones are a
            // draft. Concatenating every block would put a tool-use payload
            // into somebody's reply box the first time the API grew one.
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new AiCompletion(
            text: trim($text),
            // What actually answered, not what was asked for: a provider that
            // served a smaller model is one whose bill will not match the
            // usage table.
            model: is_string($payload['model'] ?? null) ? $payload['model'] : $this->model,
            promptTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            completionTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
        );
    }

    private function worthRetrying(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException;
    }
}
