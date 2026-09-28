<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\BackupCoverage;
use App\Http\Controllers\Controller;
use App\Infrastructure\Backup\Models\BackupProtection;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is protected, what is stale, and what nothing is backing up (§12).
 *
 * **Read-only, and there is nothing to write.** Core does not take backups
 * and does not run one: a PHP process cannot take a consistent snapshot, and
 * a button here would promise one. Running a job and restoring from it belong
 * behind a guarded workflow with an adapter that has actually been proven.
 *
 * The screen opens on the **unprotected** list rather than on the inventory,
 * because that is the one this family exists for. Everything a backup vendor
 * can already tell you is on their own console; what nothing else can say is
 * which of the things this installation sold has nobody looking after it.
 */
final class BackupCoverageController extends Controller
{
    public function __invoke(Request $request, CurrentActor $actor, BackupCoverage $coverage): Response
    {
        if (! $actor->can('infrastructure.backup.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        /*
         * How old a good copy may be before it is stale.
         *
         * A default rather than a stored setting: this is a view filter and
         * the real threshold is an alert rule's, which is where an operator
         * states it once and gets told. A settings column that only this
         * screen read would be a second answer to the same question.
         */
        $staleAfter = max(1, min(365, (int) $request->integer('stale_after', 2)));
        $tab = match ($request->string('tab')->toString()) {
            'stale' => 'stale',
            'protected' => 'protected',
            default => 'unprotected',
        };

        /*
         * One list is built, not three.
         *
         * The three figures come from counts, so the two lists nobody is
         * looking at cost nothing — and building all three would page three
         * paginators against one `page` parameter, which is one list turning
         * the page and two jumping.
         */
        $rows = match ($tab) {
            'unprotected' => $this->page($coverage->unprotected(), $this->service(...)),
            'stale' => $this->page($coverage->stale($staleAfter), $this->protection(...)),
            default => $this->page($coverage->healthy($staleAfter), $this->protection(...)),
        };

        return Inertia::render('Admin/Infrastructure/BackupCoverage', [
            'tab' => $tab,
            'staleAfter' => $staleAfter,
            'summary' => $coverage->summary($staleAfter),
            'rows' => $rows,
        ]);
    }

    /**
     * A page of rows, shaped by whichever mapper the tab wants.
     *
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  callable(TModel): array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function page(LengthAwarePaginator $page, callable $row): array
    {
        return [
            'data' => array_map($row, array_values($page->items())),
            'links' => $page->linkCollection()->toArray(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function service(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'domain' => $service->domain,
            'status' => $service->status->value,
            'statusLabel' => (string) __($service->status->labelKey()),
            'customer' => $service->customer?->displayName(),
            'customerId' => $service->customer_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function protection(BackupProtection $protection): array
    {
        return [
            'id' => $protection->id,
            'name' => $protection->resource_name,
            'source' => $protection->source,
            'repository' => $protection->repository,
            'outcome' => $protection->last_outcome->value,
            'outcomeLabel' => (string) __($protection->last_outcome->labelKey()),
            'outcomeTone' => $protection->last_outcome->tone(),
            'lastRunAt' => $protection->last_run_at?->toIso8601String(),
            'lastGoodAt' => $protection->last_good_at?->toIso8601String(),
            // Null means nothing has ever succeeded, which the screen says in
            // words. An age invented here would be a number somebody acts on.
            'lastGoodDays' => $protection->lastGoodAgeInDays(),
            'restorePoints' => $protection->restore_points,
            'serviceId' => $protection->service_id,
            'service' => $protection->service?->name,
            'customer' => $protection->customer?->displayName(),
            'customerId' => $protection->customer_id,
        ];
    }
}
