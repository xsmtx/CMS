<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What is actually sent to a provider (ADR 0050).
 *
 * **Assembled from named fields, never from a row.** `SecretRedactor` is the
 * net and not the plan (non-negotiable 7): a prompt built by serialising a
 * model would carry whatever column somebody adds next phase, and the first
 * anybody would know is when a card's last four digits turned up in a vendor's
 * logs.
 *
 * So this object is deliberately dull — a system sentence, some context lines
 * an application service named one by one, and the thing to do. A caller that
 * wants to send something new has to add a line and say what it is.
 *
 * `instructions` is the seller's own wording where they have set one. A
 * seller who tells their operators to answer formally and in Turkish wants
 * the draft to come back that way, and core has no opinion about which.
 */
final readonly class AiPrompt
{
    /**
     * @param  list<array{label: string, value: string}>  $context
     */
    public function __construct(
        public AiFeature $feature,
        public string $task,
        public array $context = [],
        public ?string $instructions = null,
        /**
         * What the answer is allowed to cost, in tokens.
         *
         * On the prompt rather than in the adapter's configuration because it
         * varies by feature: a triage suggestion is two words and a reply is
         * several paragraphs, and one ceiling for both either truncates the
         * reply or pays for a summary nobody asked for.
         */
        public int $maxTokens = 800,
    ) {}

    /**
     * The whole of what leaves, as one string.
     *
     * Built here rather than in each adapter so that **one test can assert
     * what a feature emits**. An adapter that assembled its own would be a
     * second place for a customer's address to get in.
     */
    public function render(): string
    {
        $lines = [];

        foreach ($this->context as $entry) {
            $lines[] = $entry['label'].': '.$entry['value'];
        }

        $lines[] = '';
        $lines[] = $this->task;

        return implode("\n", $lines);
    }
}
