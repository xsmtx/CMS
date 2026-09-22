<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
final class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf(
                (string) $attributes['customer_id'],
            ),
            'module' => 'manual',
            'status' => ServiceStatus::Pending->value,
            'name' => Str::title(fake()->word().' Hosting'),
            'package' => 'starter',
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 1499,
            'setup_minor' => 0,
            'domain' => fake()->domainName(),
            'next_due_on' => now()->addMonth()->toDateString(),
        ];
    }

    public function forCustomer(Customer $customer): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    public function status(ServiceStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function on(Server $server): self
    {
        return $this->state(fn (): array => [
            'server_id' => $server->id,
            'module' => $server->module,
        ]);
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => ServiceStatus::Active->value,
            'external_id' => 'acct_'.Str::lower(Str::random(8)),
            'provisioned_at' => now(),
        ]);
    }

    private function organizationOf(string $customerId): string
    {
        return Customer::query()
            ->withoutGlobalScope('organization')
            ->whereKey($customerId)
            ->firstOrFail()
            ->organization_id;
    }
}
