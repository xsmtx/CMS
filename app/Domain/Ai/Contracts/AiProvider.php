<?php

declare(strict_types=1);

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\Exceptions\AiUnavailable;

/**
 * Something that can answer a prompt (ADR 0050).
 *
 * **One method, and deliberately no more.** No streaming, no tool calling, no
 * conversation state: a draft is one question and one answer. A seam that
 * carried a conversation would be a seam with a session in it, and a seam that
 * let a model call tools would be the agentic loop ADR 0050 refuses — built as
 * an interface first, which is how these things arrive.
 *
 * An implementation is a **module** (non-negotiable 6). Core names no vendor
 * and ships no default, exactly as it ships no gateway and no registrar.
 *
 * Non-negotiable 8 applies in full: a timeout, bounded retries with backoff, a
 * correlation id and a sanitised structured error. And the refusal has to be
 * `AiUnavailable` rather than whatever the HTTP client threw — a provider that
 * is down must read as "the button did not work", because an operator who can
 * write the reply themselves is not having an outage.
 *
 * @throws AiUnavailable when the provider cannot or will not answer
 */
interface AiProvider
{
    /**
     * A stable identifier for this provider, used on the usage row and in the
     * registry. Lower-case, `[a-z0-9-]`, like every other adapter key.
     */
    public function key(): string;

    public function complete(AiPrompt $prompt): AiCompletion;
}
