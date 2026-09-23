<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Import\Mappers\ContactMapper;
use App\Application\Import\Mappers\CustomerMapper;
use App\Application\Import\Mappers\DomainMapper;
use App\Application\Import\Mappers\InvoiceMapper;
use App\Application\Import\Mappers\ProductMapper;
use App\Application\Import\Mappers\ServiceMapper;
use App\Application\Import\Mappers\TicketMapper;
use App\Application\Import\Mappers\TransactionMapper;
use App\Domain\Import\ImportDomain;
use Illuminate\Contracts\Container\Container;

/**
 * One mapper per domain.
 *
 * A `match` over a closed enum rather than a discovered list, for the same
 * reason `TaskRegistry` is one: the compiler then refuses to let a domain be
 * added without a mapper, which is better than a run that skips a domain
 * silently because nothing was registered for it.
 */
final readonly class ImportMapperRegistry
{
    public function __construct(private Container $container) {}

    public function for(ImportDomain $domain): ImportMapper
    {
        /** @var ImportMapper */
        return $this->container->make(match ($domain) {
            ImportDomain::Customers => CustomerMapper::class,
            ImportDomain::Contacts => ContactMapper::class,
            ImportDomain::Products => ProductMapper::class,
            ImportDomain::Services => ServiceMapper::class,
            ImportDomain::Domains => DomainMapper::class,
            ImportDomain::Invoices => InvoiceMapper::class,
            ImportDomain::Transactions => TransactionMapper::class,
            ImportDomain::Tickets => TicketMapper::class,
        });
    }
}
