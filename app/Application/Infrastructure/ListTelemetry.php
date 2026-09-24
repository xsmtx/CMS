<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * What arrived, from where, and what has not.
 *
 * A use case rather than a controller's queries, for the same reason
 * `ListResources` is one — and because three of these four answers are about
 * *absence*, which is the part of a telemetry screen that is easy to get subtly
 * wrong and worth having in one place with a name on it.
 */
final readonly class ListTelemetry
{
    /**
     * @return LengthAwarePaginator<int, ResourceMetric>
     */
    public function handle(?string $source = null, ?string $metric = null, int $perPage = 50): LengthAwarePaginator
    {
        return ResourceMetric::query()
            ->when(
                $source !== null && $source !== '',
                static fn (Builder $query): Builder => $query->where('source', $source),
            )
            ->when(
                $metric !== null && $metric !== '',
                static fn (Builder $query): Builder => $query->where('metric', $metric),
            )
            // The node is printed on every row, so it is loaded with them. Fifty
            // rows is exactly the shape `LazyLoadingTest` catches when it is not.
            ->with('node')
            ->latest('sampled_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return list<array{value: string, label: string, count: int}>
     */
    public function sources(): array
    {
        $rows = ResourceMetric::query()
            ->select('source')
            ->selectRaw('count(*) as total')
            ->groupBy('source')
            ->orderBy('source')
            // `toBase()` keeps the model's global scope while giving a typed row
            // back: an aggregate on a model builder is what PHPStan cannot see.
            ->toBase()
            ->get();

        return array_values($rows->map(static fn (object $row): array => [
            'value' => (string) $row->source,
            'label' => (string) $row->source,
            'count' => (int) $row->total,
        ])->all());
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'measurements' => ResourceMetric::query()->count(),
            'sources' => ResourceMetric::query()->distinct()->count('source'),
            /*
             * Staleness in SQL rather than in PHP.
             *
             * The obvious version loads every row and filters with `isStale()`,
             * which is correct and gets slower every week — and this is a count
             * on a screen, not a decision. The interval comes from the column
             * because each source declares its own freshness: a reading five
             * minutes old from a minutely poller is a problem and the same
             * reading from an hourly one is not.
             */
            'stale' => ResourceMetric::query()
                ->whereNotNull('stale_after_seconds')
                ->whereRaw('sampled_at < date_sub(now(), interval stale_after_seconds second)')
                ->count(),
            'unwatched' => $this->unwatchedQuery()->count(),
        ];
    }

    /**
     * The resources nothing is reporting on, capped.
     *
     * On a fresh installation this is *every* node, and a list of four thousand
     * is not a finding. The count is the finding; this is the first few to go and
     * look at.
     *
     * @return list<ResourceNode>
     */
    public function unwatched(int $limit = 10): array
    {
        return array_values(
            $this->unwatchedQuery()
                ->orderBy('kind')
                ->orderBy('label')
                ->limit($limit)
                ->get()
                ->all()
        );
    }

    /**
     * @return Builder<ResourceNode>
     */
    private function unwatchedQuery(): Builder
    {
        return ResourceNode::query()
            ->whereNull('retired_at')
            ->whereDoesntHave('metrics');
    }
}
