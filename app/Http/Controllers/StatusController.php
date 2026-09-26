<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reliability\PublicStatus;
use App\Application\Reliability\StatusReport;
use App\Support\View\StorefrontRenderer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Renderable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public status page (§15).
 *
 * **It is deliberately outside `EnforceMaintenanceMode`.** Maintenance mode
 * closes the shop and the client area, and closing the status page with them
 * would take down the one page whose whole purpose is to be readable while
 * something is wrong. A customer who goes there to find out whether anything
 * is down, and is told the site is down for maintenance, has learned nothing
 * they could not already see.
 *
 * `PublicStatus` decides *what* may be published; this decides how it reads.
 * The split matters because the first question is a privacy decision with one
 * answer, and the second is wording and dates, which are a locale's business.
 *
 * It is the brand's, like every other public page: `StorefrontComposer` puts
 * the brand and the theme on it, so a reseller's status page carries the
 * reseller's name and their own incidents.
 *
 * @phpstan-import-type PublishedIncident from StatusReport
 */
final class StatusController extends Controller
{
    /**
     * Tone to the two classes that draw it.
     *
     * Here rather than in the template because a theme author may replace the
     * template and must not have to know the platform's token names — and
     * because a colour chosen in a `@php` block is a colour outside the
     * design system.
     */
    private const array Tones = [
        'healthy' => ['dot' => 'bg-success', 'text' => 'text-success'],
        'warning' => ['dot' => 'bg-warning', 'text' => 'text-warning'],
        'critical' => ['dot' => 'bg-danger', 'text' => 'text-danger'],
    ];

    public function __construct(
        private readonly StorefrontRenderer $renderer,
        private readonly PublicStatus $status,
    ) {}

    public function __invoke(): Renderable
    {
        // An installation that runs its status page elsewhere turns this off,
        // and then the route answers 404 and the header stops linking to it —
        // rather than serving a page nobody maintains.
        if (! $this->status->isPublished()) {
            throw new NotFoundHttpException;
        }

        $report = $this->status->report();
        $tone = self::Tones[$report->level->tone()] ?? self::Tones['healthy'];

        return $this->renderer->render('status', [
            'level' => $tone + ['label' => (string) __($report->level->labelKey())],
            'checkedAt' => (string) __('reliability.status_page.checked_at', [
                'at' => $this->whenWithZone(CarbonImmutable::now()),
            ]),
            'open' => array_map($this->incident(...), $report->open),
            'history' => array_map($this->incident(...), $report->history),
            'noHistory' => (string) __('reliability.status_page.no_history', [
                'days' => PublicStatus::HistoryDays,
            ]),
        ]);
    }

    /**
     * A moment, worded in the locale this page is being read in.
     *
     * `app()->setLocale()` does not move Carbon's, so a Turkish status page
     * would otherwise print English month names among Turkish sentences —
     * which is exactly the bug a Turkish invoice mail had.
     */
    private function when(CarbonInterface $at): string
    {
        return $at->locale(app()->getLocale())->isoFormat('LLL');
    }

    /**
     * The same moment, naming the clock it is on.
     *
     * A customer in another country reading "9:07 PM" does not know whose
     * nine o'clock it is, and on the page whose whole job is to answer "is it
     * back yet" an ambiguous time is a question rather than an answer. It is
     * said **once**, at the top: every other time on the page is on the same
     * clock, and repeating the zone on nine lines is noise that stops being
     * read by the third one.
     */
    private function whenWithZone(CarbonInterface $at): string
    {
        return $at->locale(app()->getLocale())->isoFormat('LLL z');
    }

    /**
     * @param  PublishedIncident  $incident
     * @return array<string, mixed>
     */
    private function incident(array $incident): array
    {
        $started = $incident['startedAt'];
        $resolved = $incident['resolvedAt'];

        return [
            'title' => $incident['title'],
            // One sentence rather than two dates in a row, because "started
            // 14:20, resolved 15:05" is arithmetic a customer should not have
            // to do while wondering whether their site is back.
            'when' => $resolved === null
                ? (string) __('reliability.status_page.started', [
                    'at' => $this->when($started),
                ])
                : (string) __('reliability.status_page.ran', [
                    'from' => $this->when($started),
                    'to' => $this->when($resolved),
                ]),
            'updates' => array_map(
                fn (array $update): array => [
                    'stateLabel' => (string) __($update['state']->labelKey()),
                    'body' => $update['body'],
                    'writtenAt' => $this->when($update['writtenAt']),
                ],
                $incident['updates'],
            ),
        ];
    }
}
