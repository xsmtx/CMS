<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use Closure;
use Illuminate\Support\Facades\Context;

/**
 * The organization boundary for the current unit of work.
 *
 * Authorization in this platform is
 * `actor -> organization boundary -> resource ownership -> permission`, and
 * this class owns the first step. It is stored in Laravel's Context so that
 * a queued job inherits the boundary of the request that dispatched it — a
 * job that silently ran unscoped would be a cross-tenant data leak.
 *
 * Nothing here decides *what* a subject may do; it decides which rows exist
 * as far as the subject is concerned.
 */
final class OrganizationContext
{
    private const string CONTEXT_KEY = 'organization_id';

    /**
     * Set when a deliberate, audited operation needs to see every
     * organization at once (provider-wide reports, maintenance commands).
     */
    private bool $unscoped = false;

    public function set(string $organizationId): void
    {
        Context::add(self::CONTEXT_KEY, $organizationId);
    }

    public function id(): ?string
    {
        $value = Context::get(self::CONTEXT_KEY);

        return is_string($value) ? $value : null;
    }

    public function forget(): void
    {
        Context::forget(self::CONTEXT_KEY);
    }

    public function hasBoundary(): bool
    {
        return ! $this->unscoped && $this->id() !== null;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /**
     * Run a callback inside a specific organization boundary and restore the
     * previous one afterwards, even if the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(string $organizationId, Closure $callback): mixed
    {
        $previous = $this->id();
        $previouslyUnscoped = $this->unscoped;

        $this->unscoped = false;
        $this->set($organizationId);

        try {
            return $callback();
        } finally {
            $this->unscoped = $previouslyUnscoped;

            if ($previous === null) {
                $this->forget();
            } else {
                $this->set($previous);
            }
        }
    }

    /**
     * Escape the boundary for one callback.
     *
     * Every call site is a deliberate decision that must be justified in
     * review: provider-wide reporting, the installer, and maintenance
     * commands are the intended users. Request handling never calls this.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutBoundary(Closure $callback): mixed
    {
        $previouslyUnscoped = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previouslyUnscoped;
        }
    }
}
