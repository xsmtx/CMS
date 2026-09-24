<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * Something the graph will not store, and why.
 *
 * Every one of these is a programming error rather than an operator's mistake:
 * they are raised by code that built a node or an edge wrongly, not by a form.
 * So they carry the offending value and no translation — an operator never sees
 * one, and the developer who does needs the value.
 */
final class InvalidResource extends RuntimeException
{
    public static function badKind(string $kind): self
    {
        return new self(
            "Resource kind [{$kind}] is not a plain lowercase name. "
            .'A kind reaches a URL segment, a translation key and a filter.'
        );
    }

    public static function emptyKey(string $kind): self
    {
        return new self("A [{$kind}] node needs a key: it is the identity a second discovery matches on.");
    }

    public static function selfReference(string $nodeKey): self
    {
        return new self("Node [{$nodeKey}] cannot contain itself.");
    }

    public static function acrossOrganizations(string $container, string $contained): self
    {
        return new self(
            "Refusing an edge from [{$container}] to [{$contained}]: the contained node is outside "
            .'the container organization subtree, so no boundary could ever show both ends.'
        );
    }

    public static function unknownNode(string $nodeKey): self
    {
        return new self("No node [{$nodeKey}] inside the current boundary.");
    }
}
