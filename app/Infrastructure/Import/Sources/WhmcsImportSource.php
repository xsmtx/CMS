<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Sources;

use App\Domain\Import\Contracts\ImportSource;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A WHMCS-shaped MySQL schema, read over a configured connection.
 *
 * **It has never read a real WHMCS database.** Its queries are written against
 * the documented and widely known table shapes; every column it reads is named
 * in `COLUMNS` and nowhere else, and `check()` verifies them all before anything
 * is written — so a schema that differs fails at the beginning, naming the
 * column, rather than halfway through an import. That is the same position the
 * Stripe, cPanel and Namecheap adapters are in and it is stated here rather than
 * left to be discovered.
 *
 * **Read-only, and it never holds credentials.** The connection is a Laravel
 * database connection the operator configures, so the credentials live in their
 * environment file next to their own database's — where such things belong. This
 * class issues nothing but `select`.
 *
 * **Every read is a cursor.** A migration is the one operation in this product
 * with an unbounded row count; a `get()` here would put twelve thousand invoices
 * in memory before the first one was written.
 *
 * One deliberate omission worth naming: `tblclients.password` is not read at
 * all. A legacy hash is somebody else's algorithm, and importing it would either
 * not work or work by this platform accepting another system's crypto.
 */
final readonly class WhmcsImportSource implements ImportSource
{
    /**
     * Every table and column this adapter reads.
     *
     * In one place so that `check()` can verify the schema and so that a
     * WHMCS version which renamed something produces one legible failure
     * instead of eight scattered ones.
     *
     * @var array<string, array{table: string, id: string, columns: list<string>}>
     */
    private const array COLUMNS = [
        'customers' => [
            'table' => 'tblclients',
            'id' => 'id',
            'columns' => ['id', 'firstname', 'lastname', 'companyname', 'email', 'status', 'currency'],
        ],
        'contacts' => [
            'table' => 'tblcontacts',
            'id' => 'id',
            'columns' => ['id', 'userid', 'firstname', 'lastname', 'email', 'phonenumber', 'subaccount'],
        ],
        'products' => [
            'table' => 'tblproducts',
            'id' => 'id',
            'columns' => ['id', 'name', 'description', 'type', 'gid'],
        ],
        'services' => [
            'table' => 'tblhosting',
            'id' => 'id',
            'columns' => [
                'id', 'userid', 'packageid', 'domain', 'username', 'billingcycle',
                'amount', 'domainstatus', 'regdate', 'nextduedate',
            ],
        ],
        'domains' => [
            'table' => 'tbldomains',
            'id' => 'id',
            'columns' => [
                'id', 'userid', 'domain', 'registrar', 'status', 'registrationdate',
                'expirydate', 'recurringamount', 'donotrenew',
            ],
        ],
        'invoices' => [
            'table' => 'tblinvoices',
            'id' => 'id',
            'columns' => [
                'id', 'invoicenum', 'userid', 'date', 'duedate', 'datepaid',
                'subtotal', 'credit', 'tax', 'total', 'status', 'notes',
            ],
        ],
        'transactions' => [
            'table' => 'tblaccounts',
            'id' => 'id',
            'columns' => [
                'id', 'userid', 'invoiceid', 'gateway', 'date', 'description',
                'amountin', 'amountout', 'transid',
            ],
        ],
        'tickets' => [
            'table' => 'tbltickets',
            'id' => 'id',
            'columns' => ['id', 'tid', 'userid', 'title', 'status', 'priority', 'date', 'lastreply'],
        ],
    ];

    public function __construct(private string $connection) {}

    public function key(): string
    {
        return 'whmcs';
    }

    /**
     * Everything that would stop this import, as sentences.
     *
     * All of them at once, rather than the first: an operator fixing a
     * connection wants the whole list, and finding them one deploy at a time is
     * how a migration takes a week.
     *
     * @return list<string>
     */
    public function check(): array
    {
        try {
            $connection = $this->connection();
            $connection->select('select 1');
        } catch (Throwable $exception) {
            // The message is a driver's and may carry a host or a user name, so
            // only its class is reported. An operator who has just typed the
            // credentials knows which ones they typed.
            return [sprintf(
                'The legacy database connection [%s] could not be opened (%s).',
                $this->connection,
                class_basename($exception::class),
            )];
        }

        $problems = [];

        foreach (self::COLUMNS as $domain => $shape) {
            if (! $connection->getSchemaBuilder()->hasTable($shape['table'])) {
                $problems[] = sprintf(
                    'The table [%s] is missing, so [%s] cannot be imported.',
                    $shape['table'],
                    $domain,
                );

                continue;
            }

            foreach ($shape['columns'] as $column) {
                if (! $connection->getSchemaBuilder()->hasColumn($shape['table'], $column)) {
                    $problems[] = sprintf('[%s.%s] is missing.', $shape['table'], $column);
                }
            }
        }

        return $problems;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $connection = $this->connection();
        $counts = [];

        foreach (self::COLUMNS as $domain => $shape) {
            try {
                $counts[$domain] = (int) $connection->table($shape['table'])->count();
            } catch (Throwable) {
                // A table this schema does not have. Reported as zero rather
                // than thrown: `check()` is where a missing table is named, and
                // the plan screen should still draw.
                $counts[$domain] = 0;
            }
        }

        return $counts;
    }

    /**
     * @return Generator<int, ImportRecord>
     */
    public function read(ImportDomain $domain): Generator
    {
        $shape = self::COLUMNS[$domain->value];

        $rows = $this->connection()
            ->table($shape['table'])
            ->select($shape['columns'])
            // Ordered by the legacy key so a resumed run reads them in the same
            // sequence — which, with the mapping table, means it skips a
            // contiguous block rather than seeking through the whole table.
            ->orderBy($shape['id'])
            ->cursor();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            yield new ImportRecord(
                domain: $domain,
                externalId: (string) ($data[$shape['id']] ?? ''),
                data: $data,
                label: $this->label($domain, $data),
            );
        }
    }

    /**
     * A name an operator recognises in a failure report.
     *
     * "Client 4182" is not somebody they can telephone about, and a report they
     * cannot act on is a report they will not read.
     *
     * @param  array<string, mixed>  $data
     */
    private function label(ImportDomain $domain, array $data): ?string
    {
        $pick = static function (array $keys) use ($data): ?string {
            foreach ($keys as $key) {
                $value = $data[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return null;
        };

        return match ($domain) {
            ImportDomain::Customers => $pick(['companyname', 'email'])
                ?? trim((($data['firstname'] ?? '')).' '.(($data['lastname'] ?? ''))) ?: null,
            ImportDomain::Contacts => $pick(['email']),
            ImportDomain::Products => $pick(['name']),
            ImportDomain::Services, ImportDomain::Domains => $pick(['domain']),
            ImportDomain::Invoices => $pick(['invoicenum']),
            ImportDomain::Transactions => $pick(['transid', 'gateway']),
            ImportDomain::Tickets => $pick(['title', 'tid']),
        };
    }

    private function connection(): Connection
    {
        return DB::connection($this->connection);
    }
}
