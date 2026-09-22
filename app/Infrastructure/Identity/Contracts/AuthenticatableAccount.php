<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Contracts;

use App\Domain\Identity\Contracts\PlatformAccount;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A platform account that the framework can also authenticate.
 *
 * The domain contract deliberately knows nothing about Laravel. This one
 * joins it to `Authenticatable` so that infrastructure and application
 * services can accept a single type instead of an intersection spelled out
 * at every call site.
 */
interface AuthenticatableAccount extends Authenticatable, PlatformAccount {}
