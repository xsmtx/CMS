<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Import\ImportOutcome;

/**
 * What happened to one row.
 *
 * A value object rather than an outcome plus three out-parameters, because the
 * writer, the mapper and the run all handle it and an array here would mean
 * three places agreeing about key names.
 */
final readonly class ImportResult
{
    public function __construct(
        public ImportOutcome $outcome,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?string $message = null,
    ) {}

    public static function skipped(string $why): self
    {
        return new self(ImportOutcome::Skipped, null, null, $why);
    }

    /**
     * A row that could not be brought across, and why.
     *
     * The reason is shown to an operator, so it says what is wrong with the
     * row rather than what threw: "no currency GBP is configured here" is
     * something somebody can fix, and `UnknownCurrency` is not.
     */
    public static function failed(string $why): self
    {
        return new self(ImportOutcome::Failed, null, null, $why);
    }
}
