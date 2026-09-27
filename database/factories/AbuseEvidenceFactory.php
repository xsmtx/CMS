<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Security\EvidenceKind;
use App\Infrastructure\Security\Models\AbuseEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbuseEvidence>
 */
final class AbuseEvidenceFactory extends Factory
{
    protected $model = AbuseEvidence::class;

    public function definition(): array
    {
        return [
            'kind' => EvidenceKind::MailMessageId,
            'reference' => '<20260926.1a2b3c@mail.example.net>',
            'captured_at' => CarbonImmutable::now(),
            'retain_until' => CarbonImmutable::now()->addDays(90),
        ];
    }

    /** Past its deadline, which is what the forgetting sweep looks for. */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'captured_at' => CarbonImmutable::now()->subDays(120),
            'retain_until' => CarbonImmutable::now()->subDay(),
        ]);
    }
}
