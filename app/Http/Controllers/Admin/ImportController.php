<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Import\ImportRefused;
use App\Application\Import\ImportSourceRegistry;
use App\Application\Import\StartImport;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportOutcome;
use App\Http\Controllers\Controller;
use App\Infrastructure\Import\Models\ImportItem;
use App\Infrastructure\Import\Models\ImportRun;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bringing a legacy system across.
 *
 * **Owner only.** An import writes customers, invoices and ledger rows straight
 * into the database, bypassing every use case (deliberately — see `RunImport`),
 * and it reads a second database over a connection somebody configured. That is
 * not a permission; it is the same "who you are" question Apps and the Licence
 * screen ask.
 *
 * The screen is the pipeline in order: what is connected, what is there to
 * import, a dry run, then the real thing — and then the report, which is the
 * part that matters. **A report that only showed totals would be useless**: an
 * operator needs the four hundred rows that did not come across, by name, and
 * that is what the failures list is.
 */
final class ImportController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(ImportSourceRegistry $registry): Response
    {
        $this->authorizeOwner();

        $sources = $registry->available();

        return Inertia::render('Admin/Import/Index', [
            'sources' => array_map(
                function (array $source) use ($registry): array {
                    $adapter = $registry->for($source['key']);

                    return [
                        ...$source,
                        // Everything that would stop this import, all at once.
                        // Finding them one deploy at a time is how a migration
                        // takes a week.
                        'problems' => $adapter?->check() ?? [
                            'This installation has no connection called ['.$source['connection'].'].',
                        ],
                        // Only asked when the source is ready: counting rows on
                        // a connection that cannot be opened is a screen that
                        // hangs.
                        'counts' => $adapter !== null && $adapter->check() === []
                            ? $adapter->counts()
                            : null,
                    ];
                },
                $sources,
            ),
            'domains' => array_map(
                static fn (ImportDomain $domain): array => [
                    'value' => $domain->value,
                    'label' => (string) __($domain->labelKey()),
                    'requires' => array_map(
                        static fn (ImportDomain $required): string => $required->value,
                        $domain->requires(),
                    ),
                ],
                ImportDomain::ordered(),
            ),
            'runs' => $this->runs(),
        ]);
    }

    public function store(Request $request, StartImport $start): RedirectResponse
    {
        $this->authorizeOwner();

        $data = $request->validate([
            'source' => ['required', 'string', 'max:32'],
            'mode' => ['required', Rule::enum(ImportMode::class)],
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['required', Rule::enum(ImportDomain::class)],
        ]);

        // `array_values` so the analyser sees a list: `validate()` hands back
        // whatever keys the form posted, and a form that posted `domains[3]`
        // alone would otherwise arrive as a keyed array.
        $domains = array_values(array_map(
            ImportDomain::from(...),
            $data['domains'],
        ));

        try {
            $run = $start->handle(
                $data['source'],
                ImportMode::from($data['mode']),
                $domains,
                $this->actor->model(),
            );
        } catch (ImportRefused $refused) {
            // On the form: every refusal here is something an operator can fix
            // — a missing column, a domain whose parent has not come across, a
            // connection that will not open.
            throw ValidationException::withMessages(['domains' => $refused->getMessage()]);
        }

        return to_route('admin.import.show', $run->id)
            ->with('status', __('import.started'));
    }

    /**
     * One run, with its failures.
     *
     * The failures are the report. Successes are a number; the rows that did not
     * come across are work, and an operator needs each of them by name.
     */
    public function show(string $run): Response
    {
        $this->authorizeOwner();

        $record = ImportRun::query()->with('startedBy')->where('id', $run)->first();

        if ($record === null) {
            abort(404);
        }

        return Inertia::render('Admin/Import/Show', [
            'run' => [
                ...$this->row($record),
                'expected' => $record->expected ?? [],
                'totals' => $record->totals ?? [],
                'error' => $record->error,
            ],
            'failures' => array_values(ImportItem::query()
                ->where('run_id', $record->id)
                ->where('outcome', ImportOutcome::Failed->value)
                ->orderBy('domain')
                ->limit(500)
                ->get()
                ->map(static fn (ImportItem $item): array => [
                    'id' => $item->id,
                    'domain' => $item->domain->value,
                    'externalId' => $item->external_id,
                    'label' => $item->label,
                    'message' => $item->message,
                ])
                ->all()),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runs(): array
    {
        return array_values(ImportRun::query()
            ->with('startedBy')
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map($this->row(...))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ImportRun $run): array
    {
        return [
            'id' => $run->id,
            'source' => $run->source,
            'mode' => $run->mode->value,
            'modeLabel' => (string) __($run->mode->labelKey()),
            'status' => $run->status->value,
            'statusLabel' => (string) __($run->status->labelKey()),
            'domains' => $run->domains,
            'created' => $run->countOf(ImportOutcome::Created->value),
            'skipped' => $run->countOf(ImportOutcome::Skipped->value),
            'failed' => $run->countOf(ImportOutcome::Failed->value),
            'startedBy' => $run->startedBy?->name,
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'createdAt' => $run->created_at->toIso8601String(),
        ];
    }

    /**
     * Who you have to be.
     *
     * An import bypasses every use case by design and reads a second database.
     * It is the same question Apps and the Licence screen ask, and it cannot be
     * a permission: an Administrator holds every staff permission there is.
     */
    private function authorizeOwner(): void
    {
        if (! $this->actor->isSuperAdmin()) {
            throw new ForbiddenException(__('import.errors.not_permitted'));
        }
    }
}
