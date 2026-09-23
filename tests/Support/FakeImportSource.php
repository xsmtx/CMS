<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Import\Contracts\ImportSource;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use Generator;
use RuntimeException;

/**
 * A legacy system, in an array.
 *
 * The WHMCS adapter is a transport over somebody else's schema; what the tests
 * need to exercise is the pipeline — dependency order, mapping, idempotency, the
 * dry run, the failure recording. So this source yields rows shaped the way the
 * mappers read them and nothing about a real database is involved.
 *
 * It uses WHMCS's column names on purpose, so that a mapper reading the wrong key
 * fails here rather than on a customer's migration.
 */
final class FakeImportSource implements ImportSource
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    /** @var list<string> */
    public array $problems = [];

    /** Domains whose read explodes, so a broken source can be tested. */
    public ?ImportDomain $failReadOf = null;

    public function key(): string
    {
        return 'whmcs';
    }

    public function check(): array
    {
        return $this->problems;
    }

    public function counts(): array
    {
        $counts = [];

        foreach (ImportDomain::cases() as $domain) {
            $counts[$domain->value] = count($this->rows[$domain->value] ?? []);
        }

        return $counts;
    }

    public function read(ImportDomain $domain): Generator
    {
        if ($this->failReadOf === $domain) {
            throw new RuntimeException('The legacy database went away.');
        }

        foreach ($this->rows[$domain->value] ?? [] as $row) {
            yield new ImportRecord(
                domain: $domain,
                externalId: (string) ($row['id'] ?? ''),
                data: $row,
                label: isset($row['label']) && is_string($row['label']) ? $row['label'] : null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function add(ImportDomain $domain, array $row): self
    {
        $this->rows[$domain->value][] = $row;

        return $this;
    }
}
