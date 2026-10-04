<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use App\Domain\Shared\Refused;

/**
 * A draft that will not be written, and why (ADR 0050).
 *
 * **A provider being down is not an outage**, which is the whole shape of this
 * class: every one of these reaches an operator as a sentence beside a button
 * that did not work, and they write the reply themselves. `Entitlements` made
 * the same call — a seam whose failure is an outage is a seam built wrong.
 *
 * Named separately, the rule `PackageRefused` set: "the draft failed" would
 * make a module nobody installed, a feature nobody turned on, a vendor that is
 * down and a vendor refusing the content look identical. The first two are
 * somebody's settings screen and the last two are not.
 *
 * `refused` is the one worth reading twice. A provider declining to answer is
 * a fact about the provider's own rules, not about this platform, and an
 * operator reading "it would not answer" goes and writes the reply instead of
 * going to look at a log.
 */
final class AiUnavailable extends Refused
{
    public static function noProvider(): self
    {
        return new self(
            'No AI provider module is enabled on this installation.',
            'ai.errors.no_provider',
        );
    }

    public static function notEnabled(string $feature): self
    {
        return new self(
            'The '.$feature.' assistant is switched off.',
            'ai.errors.not_enabled',
            ['feature' => $feature],
        );
    }

    public static function noCredential(string $provider): self
    {
        return new self(
            'No API key is stored for '.$provider.'.',
            'ai.errors.no_credential',
            ['provider' => $provider],
        );
    }

    public static function unreachable(string $provider): self
    {
        return new self(
            $provider.' did not answer.',
            'ai.errors.unreachable',
            ['provider' => $provider],
        );
    }

    /**
     * The provider answered and declined — its own content rules, a quota, a
     * model that has been withdrawn.
     */
    public static function refused(string $provider): self
    {
        return new self(
            $provider.' would not answer this.',
            'ai.errors.refused',
            ['provider' => $provider],
        );
    }

    /**
     * Nothing came back, which is not the same as a refusal: a provider that
     * answered with an empty string has produced a draft of nothing, and
     * putting that in the reply box would read as the button having worked.
     */
    public static function emptyAnswer(string $provider): self
    {
        return new self(
            $provider.' answered with nothing.',
            'ai.errors.empty_answer',
            ['provider' => $provider],
        );
    }
}
