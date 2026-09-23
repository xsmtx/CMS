<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Who can read it.
 *
 * `Customers` is not a security boundary for secrets — it is for content
 * that is only meaningful once somebody has an account. Anything that would
 * actually harm the business if published does not belong in a knowledge
 * base at all.
 */
enum ArticleVisibility: string
{
    case Public = 'public';
    case Customers = 'customers';
    case Draft = 'draft';

    public function labelKey(): string
    {
        return 'support.visibility.'.$this->value;
    }

    public function isPublished(): bool
    {
        return $this !== self::Draft;
    }

    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
