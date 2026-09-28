<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Backup;

/**
 * How a backup source describes the last run of a job (§12).
 *
 * Four members, because every vendor in this family has three and they are
 * not the same three. Veeam has Success, Warning and Failed; JetBackup has
 * completed, partial and failed; Borg has an exit status where 1 means "some
 * files could not be read". `Warning` is the one that must survive the
 * translation: a partial backup is not a success and is not a failure, and
 * collapsing it into either is how somebody finds out at restore time.
 *
 * `Unknown` is what a source that does not say gets. It is deliberately not
 * `Failed`: a job whose outcome nobody reported has not failed, and a screen
 * that drew it red would teach an operator to ignore red.
 */
enum BackupOutcome: string
{
    case Succeeded = 'succeeded';
    case Warning = 'warning';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.backup.outcomes.'.$this->value;
    }

    /**
     * The tone a screen draws it in.
     *
     * Decided here rather than in `status.ts`, like every other status this
     * product sends to the browser: the word and the tone cross as two
     * fields, so a translated label can never be what a tone is derived from.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Succeeded => 'healthy',
            self::Warning => 'warning',
            self::Failed => 'critical',
            self::Unknown => 'unknown',
        };
    }
}
