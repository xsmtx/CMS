<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Domains\DomainStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\Tld;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $label = Str::lower(Str::random(10));
        $extension = 'test';

        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Customer::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['customer_id'])
                ->firstOrFail()
                ->organization_id,
            'registrar' => 'manual',
            'status' => DomainStatus::Pending->value,
            'label' => $label,
            'extension' => $extension,
            'name' => $label.'.'.$extension,
            'years' => 1,
            'currency_code' => 'EUR',
            'renewal_minor' => 1200,
            'auto_renew' => true,
            'registrar_lock' => true,
            'whois_privacy' => false,
        ];
    }

    public function forCustomer(Customer $customer): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    public function forTld(Tld $tld): self
    {
        return $this->state(function (array $attributes) use ($tld): array {
            $label = (string) ($attributes['label'] ?? Str::lower(Str::random(10)));

            return [
                'tld_id' => $tld->id,
                'extension' => $tld->extension,
                'registrar' => $tld->registrar,
                'name' => $label.'.'.$tld->extension,
            ];
        });
    }

    public function named(string $label, string $extension = 'test'): self
    {
        return $this->state(fn (): array => [
            'label' => $label,
            'extension' => $extension,
            'name' => $label.'.'.$extension,
        ]);
    }

    public function status(DomainStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function active(int $daysUntilExpiry = 300): self
    {
        return $this->state(fn (): array => [
            'status' => DomainStatus::Active->value,
            'external_id' => 'dom_'.Str::lower(Str::random(8)),
            'registered_on' => now()->subDays(365 - $daysUntilExpiry)->toDateString(),
            'expires_on' => now()->addDays($daysUntilExpiry)->toDateString(),
            'nameservers' => ['ns1.example.test', 'ns2.example.test'],
        ]);
    }
}
