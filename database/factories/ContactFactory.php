<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    protected $model = Contact::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            // A contact belongs to the same organization as its customer,
            // never to whichever boundary happens to be active.
            'organization_id' => fn (array $attributes): string => self::organizationOf($attributes['customer_id']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+3161#######'),
            'portal_access' => true,
            'password' => self::$password ??= Hash::make('password'),
            'email_verified_at' => now(),
            'is_primary' => false,
            'status' => AccountStatus::Active->value,
            'remember_token' => Str::random(10),
        ];
    }

    public function forCustomer(Customer|string $customer): static
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return $this->state(fn (): array => [
            'customer_id' => $id,
            'organization_id' => self::organizationOf($id),
        ]);
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }

    /**
     * A person on file who cannot sign in: the billing contact who exists
     * only so invoices reach the right inbox.
     */
    public function withoutPortalAccess(): static
    {
        return $this->state(fn (): array => [
            'portal_access' => false,
            'password' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Suspended->value]);
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [Hash::make('aaaaa-bbbbb')],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private static function organizationOf(string $customerId): string
    {
        return Customer::query()
            ->withoutGlobalScope('organization')
            ->findOrFail($customerId)
            ->organization_id;
    }
}
