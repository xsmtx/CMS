<?php

declare(strict_types=1);

namespace App\Domain\Import\Contracts;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use Generator;

/**
 * A legacy system this platform can read from.
 *
 * A contract because the second adapter is somebody's afternoon once there is a
 * customer asking for it, and because the framework has to be testable without
 * one — the tests drive a fake source that yields rows from an array.
 *
 * **`read()` is a generator.** A migration is the one operation in this product
 * with an unbounded row count, and an adapter that returned an array would put
 * twelve thousand invoices in memory before the first one was written.
 *
 * **`check()` exists so an import fails at the beginning.** A missing column
 * discovered on row 8,000 is a half-finished import; the same column named
 * before anything is written is a configuration problem an operator can fix in
 * a minute. It returns the problems rather than throwing, because an operator
 * wants all of them at once.
 */
interface ImportSource
{
    /** The identifier written onto every mapping row, e.g. `whmcs`. */
    public function key(): string;

    /**
     * Anything that would stop this import, as sentences.
     *
     * Empty means ready. Never throws: a source that cannot be reached is one
     * of the problems, not a reason for the screen to break.
     *
     * @return list<string>
     */
    public function check(): array;

    /**
     * How many rows each domain has, for the plan.
     *
     * @return array<string, int> keyed by `ImportDomain::value`
     */
    public function counts(): array;

    /**
     * The rows of one domain, in whatever order the legacy system gives them.
     *
     * @return Generator<int, ImportRecord>
     */
    public function read(ImportDomain $domain): Generator;
}
