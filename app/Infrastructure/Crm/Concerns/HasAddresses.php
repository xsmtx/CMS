<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Concerns;

use App\Domain\Crm\AddressType;
use App\Infrastructure\Crm\Models\Address;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait HasAddresses
{
    /**
     * @return MorphMany<Address, $this>
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * The address a document should use.
     *
     * Falls back to any address of the type rather than returning nothing:
     * an invoice with no address is worse than an invoice with a
     * non-default one.
     */
    public function addressFor(AddressType $type): ?Address
    {
        return $this->addresses()
            ->where('type', $type->value)
            ->orderByDesc('is_default')
            ->first();
    }
}
