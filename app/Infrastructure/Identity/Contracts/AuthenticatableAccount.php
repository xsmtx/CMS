<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Contracts;

use App\Domain\Identity\Contracts\PlatformAccount;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use App\Infrastructure\Identity\Models\LoginHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A platform account that the framework can also authenticate.
 *
 * The domain contract deliberately knows nothing about Laravel. This one
 * joins it to `Authenticatable` so that infrastructure and application
 * services can accept a single type instead of an intersection spelled out
 * at every call site.
 */
interface AuthenticatableAccount extends Authenticatable, PlatformAccount
{
    /**
     * @return MorphMany<LoginHistory, covariant Model>
     */
    public function loginHistories(): MorphMany;

    /**
     * @return MorphMany<AuthenticatedSession, covariant Model>
     */
    public function authenticatedSessions(): MorphMany;
}
