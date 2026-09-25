<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Contracts;

use App\Domain\Secrets\SecretReference;

/**
 * Where a credential lives, for everything that is not a column.
 *
 * Handoff #1 has six encrypted columns and `SecretRedactor` on the logging
 * path. That was right for six columns and is wrong for twenty-three adapter
 * families (`advanced-operations-plan.md` §7): a Prometheus token, a
 * FortiGate API key and a Veeam password are not six more columns, and each
 * one added as a column is another place to forget the cast.
 *
 * **A reference, never a value, is what the rest of the product holds.** A
 * row points at `monitoring/prometheus/01J…` and asks for the value at the
 * moment of the call — the same rule `SafeUrl` follows for DNS, and for the
 * same reason: what was true when it was saved is not what matters.
 *
 * **Reading is not audited; writing is.** An audit row per read would be an
 * audit log made of polling, and the thing worth knowing is not that a
 * scheduler used a token at 03:14 but that somebody changed it. Rotation and
 * destruction are audited by name, never by value — an audit row that
 * carried the secret would be the one place in this product that stored a
 * credential in the clear.
 *
 * **No actor is passed in.** Who is acting is a question the platform already
 * answers once, and a contract that took an actor would be a contract that
 * imports the framework's `Model` into `app/Domain`, which the architecture
 * tests refuse. The implementation asks `CurrentActor`, so a write is audited
 * whoever calls it and a scheduler's write honestly records nobody.
 *
 * The default implementation encrypts in the database. A `vault-hashicorp`
 * module implements the same three methods against somebody else's vault
 * later; nothing above this contract knows which one answered.
 */
interface SecretStore
{
    /**
     * Write a value, creating it or replacing what was there.
     *
     * Replacing is a rotation, and a rotation is audited as one: the previous
     * value is gone and anything that cached it must ask again.
     */
    public function put(SecretReference $reference, string $value): void;

    /**
     * The value, or null when nothing was ever written under that reference.
     *
     * Null rather than an exception: an adapter whose credential has not been
     * configured yet is an ordinary state of an installation being set up,
     * and a missing credential must read as "not configured" rather than as a
     * failure nobody can place.
     */
    public function get(SecretReference $reference): ?string;

    public function has(SecretReference $reference): bool;

    /**
     * Remove a value entirely.
     *
     * Hard, not soft. A credential kept "for history" is a credential
     * somebody can still read, and the history worth keeping is the audit row
     * saying it was destroyed.
     */
    public function forget(SecretReference $reference): void;
}
