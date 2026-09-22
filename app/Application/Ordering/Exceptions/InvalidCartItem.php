<?php

declare(strict_types=1);

namespace App\Application\Ordering\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A line that does not describe anything orderable: an option belonging to
 * another product, a required question left unanswered, an addon attached
 * to the wrong plan.
 */
final class InvalidCartItem extends PlatformException
{
    public static function optionNotOnProduct(): self
    {
        return new self(__('ordering.errors.option_not_on_product'));
    }

    public static function addonNotOnProduct(): self
    {
        return new self(__('ordering.errors.addon_not_on_product'));
    }

    public static function optionRequired(string $group): self
    {
        return new self(__('ordering.errors.option_required', ['group' => $group]), ['group' => $group]);
    }

    public static function domainRequired(): self
    {
        return new self(__('ordering.errors.domain_required'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
