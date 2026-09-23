<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\ProductType;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Domain\Provisioning\AutoSetup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A legacy product becomes a product — and nothing else.
 *
 * **No prices are imported.** That is deliberate and it is the decision worth
 * reading: a price in this platform exists for a billing cycle and a currency
 * only when a row exists for it, and absence means "not sold". A legacy
 * products table holds one price column per cycle, zero-filled, so importing
 * them faithfully would create a price of zero for every cycle in every
 * currency — and zero means **free**, not unpriced. An operator would discover
 * it when a customer ordered an annual plan for nothing.
 *
 * So the products come across as a catalogue to price, and the report says so.
 * The services keep the amounts their customers are actually paying, which is
 * where the money that matters lives.
 *
 * **Provisioning is not imported either.** A legacy product's module and
 * package name refer to servers this installation has not been told about. An
 * imported product provisions nothing until somebody wires it up, which is
 * safer than a product that tries to create accounts on a server that is not
 * there.
 */
final readonly class ProductMapper implements ImportMapper
{
    public function domain(): ImportDomain
    {
        return ImportDomain::Products;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $name = $record->text('name');

        if ($name === '') {
            return ImportResult::failed('It has no name.');
        }

        return $writer->create($this->domain(), $record->externalId, fn (): Model => Product::query()->create([
            'product_group_id' => $this->group()->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'type' => $this->type($record->text('type')),
            'description' => $record->text('description') ?: null,
            // Hidden, not active: an imported product has no prices, and a
            // storefront offering an unpriced product is a storefront
            // showing "from —".
            'status' => CatalogStatus::Hidden->value,
            'requires_domain' => false,
            // Never the legacy module. It names servers this installation
            // has not been told about.
            'provisioning_module' => null,
            'auto_setup' => AutoSetup::None->value,
        ]));
    }

    /**
     * The group an imported product lands in.
     *
     * One group for everything, named once. Importing the legacy group table as
     * a ninth domain would double the mapping work to reproduce a folder
     * structure an operator is about to rearrange anyway.
     */
    private function group(): ProductGroup
    {
        return ProductGroup::query()->firstOrCreate(
            ['slug' => 'imported'],
            [
                'name' => 'Imported',
                'description' => 'Brought across from a previous system. Price these before selling them.',
                'status' => CatalogStatus::Hidden->value,
                'position' => 99,
            ],
        );
    }

    private function type(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'hostingaccount', 'shared' => ProductType::SharedHosting->value,
            'reselleraccount', 'reseller' => ProductType::Reseller->value,
            'server', 'dedicated' => ProductType::Dedicated->value,
            'vps' => ProductType::Vps->value,
            'ssl' => ProductType::Ssl->value,
            // A legacy type this platform has no equivalent for. `Other` is a
            // real member for exactly this, rather than a refused row.
            default => ProductType::Other->value,
        };
    }
}
