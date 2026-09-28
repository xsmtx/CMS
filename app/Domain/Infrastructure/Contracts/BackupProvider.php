<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Backup\ProtectedResource;

/**
 * Something that knows what it is backing up (§12).
 *
 * Veeam, Acronis, JetBackup, Proxmox Backup Server, Borg, Restic, an S3
 * lifecycle somebody wrote a script around.
 *
 * **Core never takes a backup and never will.** `docs/operations/` has said
 * so since handoff #1: a PHP process cannot take a consistent snapshot, and
 * one that produced an inconsistent snapshot would be worse than none because
 * somebody would rely on it. So this contract reads, and
 * `Capability::BackupRunWrite` and `RestoreWrite` exist with no method here —
 * the same decision the firewall policy and the DNS record got, for the same
 * reason. Running a restore belongs behind a guarded workflow, and a method
 * anything could call would be the shortcut around it, built first.
 *
 * **What core can do is the thing no backup vendor can.** Veeam knows what it
 * backs up. Only this installation knows what exists. The difference — which
 * of the things we sell nothing is protecting — is the reason this family is
 * worth building, and it is a question core asks of its own rows once the
 * adapter has answered this one.
 *
 * **It answers the whole inventory, not a page.** A resource the source stops
 * naming has been taken out of the backup job, which is exactly the event
 * "this stopped being protected"; core retires the row rather than deleting
 * it, so the date it stopped is still there to read.
 *
 * **A read that failed must answer by throwing, never by returning `[]`.** An
 * empty answer is taken literally — it means every resource this source had
 * has left the job — so a source that is merely unreachable and says nothing
 * would retire the whole estate and report every customer as unprotected at
 * three in the morning.
 */
interface BackupProvider extends InfrastructureAdapter
{
    /**
     * Everything this source is currently protecting.
     *
     * @return list<ProtectedResource>
     */
    public function protectedResources(): array;
}
