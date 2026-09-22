<?php

declare(strict_types=1);

namespace App\Support\Errors\Contracts;

/**
 * Implemented by exceptions carrying structured, user-safe detail that helps
 * a client correct the request. Never put internal diagnostics here.
 */
interface ProvidesErrorDetails
{
    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array;
}
