<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use App\Domain\Organizations\OrganizationType;
use DomainException;

final class InvalidOrganizationHierarchy extends DomainException
{
    public static function cannotNest(OrganizationType $parent, OrganizationType $child): self
    {
        return new self(
            "A [{$parent->value}] organization may not own a [{$child->value}] organization."
        );
    }

    public static function providerMustBeRoot(): self
    {
        return new self('The provider organization is the root of the hierarchy and has no parent.');
    }

    public static function nonProviderMustHaveParent(OrganizationType $type): self
    {
        return new self("A [{$type->value}] organization must belong to a parent organization.");
    }
}
