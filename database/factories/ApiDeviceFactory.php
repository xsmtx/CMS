<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Api\DevicePlatform;
use App\Infrastructure\Api\Models\ApiDevice;
use App\Infrastructure\Identity\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ApiDevice>
 */
final class ApiDeviceFactory extends Factory
{
    protected $model = ApiDevice::class;

    public function definition(): array
    {
        return [
            'owner_type' => (new Contact)->getMorphClass(),
            'owner_id' => fn (): string => Contact::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Contact::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['owner_id'])
                ->firstOrFail()
                ->organization_id,
            'name' => 'iPhone',
            'platform' => DevicePlatform::Ios->value,
            'last_seen_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Named `heldBy`, not `for`: `Factory::for()` is the relationship helper
     * and overriding it with a different meaning is how a factory starts lying.
     */
    public function heldBy(Model $owner): self
    {
        return $this->state(fn (): array => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'organization_id' => $owner->getAttribute('organization_id'),
        ]);
    }

    public function revoked(string $reason = 'lost'): self
    {
        return $this->state(fn (): array => [
            'revoked_at' => CarbonImmutable::now(),
            'revoked_reason' => $reason,
        ]);
    }
}
