<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Why this node, in a form that survives being written down.
 *
 * The deliverable of scored placement is not the choice — a coin does that —
 * it is this object. "Why did it pick that node" is the question an operator
 * asks at two in the morning, six weeks after the placement, about a service
 * whose server has since been rebuilt.
 *
 * So it is **numbers and slugs, never a sentence**. A translated sentence in a
 * database column is a sentence in the language of whoever's job queue happened
 * to run the placement, which the next operator cannot read; the wording lives
 * in `lang/` and the row holds what was measured.
 */
final readonly class PlacementDecision
{
    /**
     * @param  list<PlacementComponent>  $components
     */
    public function __construct(
        public string $serverId,
        /** @var list<PlacementComponent> */
        public array $components,
        /** How many nodes were in the running, this one included. */
        public int $candidates,
    ) {}

    /**
     * The weighted mean of the factors that answered, between zero and one.
     *
     * A mean rather than a sum, so a node with readings is comparable with one
     * that has none: adding up whatever happened to be available would make
     * "nothing is monitored here" the lowest score on the list.
     */
    public function score(): float
    {
        $weight = 0;
        $total = 0.0;

        foreach ($this->components as $component) {
            $weight += $component->weight();
            $total += $component->weighted();
        }

        return $weight === 0 ? 0.0 : $total / $weight;
    }

    /**
     * @return array<string, array{score: float, weight: int, measure: float|null, assumed: bool}>
     */
    public function toArray(): array
    {
        $factors = [];

        foreach ($this->components as $component) {
            $factors[$component->factor->value] = [
                'score' => round($component->score, 4),
                'weight' => $component->weight(),
                'measure' => $component->measure === null ? null : round($component->measure, 4),
                'assumed' => $component->assumed,
            ];
        }

        return $factors;
    }
}
