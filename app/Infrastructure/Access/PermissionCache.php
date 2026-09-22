<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Caches the effective permission set of a subject.
 *
 * Resolving permissions hits two joins on every authorization check; without
 * a cache a single admin page render can issue dozens of identical queries.
 * Any change to a role, to a role's permissions or to an assignment flushes
 * the affected entries immediately — stale authorization is never acceptable.
 */
final readonly class PermissionCache
{
    public function __construct(
        private CacheRepository $cache,
        private string $prefix,
        private int $ttl,
    ) {}

    /**
     * @param  Closure(): list<string>  $resolver
     * @return list<string>
     */
    public function remember(Model $subject, Closure $resolver): array
    {
        if ($this->ttl <= 0) {
            return $resolver();
        }

        return $this->cache->remember($this->keyFor($subject), $this->ttl, $resolver);
    }

    public function forget(Model $subject): void
    {
        $this->cache->forget($this->keyFor($subject));
    }

    /**
     * Invalidate every subject at once.
     *
     * Used when a role's permission set changes, because finding every
     * assignee is more expensive than letting the next request re-resolve.
     * The generation counter avoids requiring a taggable cache store.
     */
    public function flush(): void
    {
        $this->cache->forever($this->generationKey(), $this->generation() + 1);
    }

    private function keyFor(Model $subject): string
    {
        return $this->prefix.$this->generation().':'.$subject->getMorphClass().':'.$subject->getKey();
    }

    private function generationKey(): string
    {
        return $this->prefix.'generation';
    }

    private function generation(): int
    {
        $value = $this->cache->get($this->generationKey(), 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}
