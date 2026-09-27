<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Security\Models\Certificate;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The certificate fleet (§8).
 *
 * **Read-only, because core never issues one.** Obtaining a certificate is a
 * provisioning module's job with an account key behind it, and deploying one
 * changes what every visitor is served. What this screen answers is the
 * question an operator actually has on a Monday: what expires soon, whose it
 * is, and what nobody has looked at.
 *
 * Soonest first, always. A fleet is read in the order things will break.
 */
final class CertificateController extends Controller
{
    /**
     * What counts as "soon" on the screen.
     *
     * Only a heading — the **alert** threshold is the operator's, written as
     * a rule, because thirty days is right for a business renewing by hand
     * and absurd for one on ACME with a fortnight's lifetime. This number
     * groups a list; it never decides whether anybody is told.
     */
    private const int SoonDays = 30;

    public function __invoke(Request $request, CurrentActor $actor): Response
    {
        if (! $actor->can('security.certificates.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $showAll = $request->boolean('all');
        $now = CarbonImmutable::now();

        $certificates = Certificate::query()
            // `displayNameWith` rather than `with('customer')`: a customer
            // with no company name falls back through its primary contact.
            ->with(Customer::displayNameWith('customer'))
            ->unless($showAll, static fn ($query) => $query->live())
            ->orderBy('not_after')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Security/Certificates', [
            'certificates' => [
                'data' => array_map(
                    fn (Certificate $certificate): array => $this->row($certificate, $now),
                    array_values($certificates->items()),
                ),
                'links' => $certificates->linkCollection()->toArray(),
                'currentPage' => $certificates->currentPage(),
                'lastPage' => $certificates->lastPage(),
                'total' => $certificates->total(),
            ],
            'filters' => ['all' => $showAll],
            'counts' => $this->counts($now),
        ]);
    }

    /**
     * The three figures a fleet is read for.
     *
     * Computed over what is **live**: a retired certificate that expired last
     * March is not something anybody needs to act on, and counting it would
     * make the figure grow for ever.
     *
     * @return array<string, int>
     */
    private function counts(CarbonImmutable $now): array
    {
        $live = Certificate::query()->live();

        return [
            'expired' => (clone $live)->where('not_after', '<=', $now)->count(),
            'soon' => (clone $live)
                ->where('not_after', '>', $now)
                ->where('not_after', '<=', $now->addDays(self::SoonDays))
                ->count(),
            'healthy' => (clone $live)->where('not_after', '>', $now->addDays(self::SoonDays))->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Certificate $certificate, CarbonImmutable $now): array
    {
        $days = $certificate->daysRemaining($now);
        $expired = $days < 0;

        return [
            'id' => $certificate->id,
            'commonName' => $certificate->common_name,
            'names' => $certificate->subject_alternative_names,
            'issuer' => $certificate->issuer,
            'source' => $certificate->source,
            'notAfter' => $certificate->not_after->toIso8601String(),
            'days' => $days,
            // The value, the word and the tone. The state here is derived
            // from a date rather than stored, so a certificate that lapses
            // while the tab is open says so on the next load rather than
            // repeating what it said this morning.
            'state' => $expired ? 'expired' : ($days <= self::SoonDays ? 'expiring' : 'healthy'),
            'stateLabel' => (string) __($expired
                ? 'security.certificates.days_ago'
                : 'security.certificates.days_left', ['days' => abs($days)]),
            'stateTone' => $expired ? 'critical' : ($days <= self::SoonDays ? 'warning' : 'healthy'),
            // Nullable on purpose, and the null means "nobody looked" rather
            // than "it is fine" — an adapter reading a file off disk cannot
            // say what a client would be served.
            'chainOk' => $certificate->chain_ok,
            'customer' => $certificate->customer?->displayName(),
            'customerId' => $certificate->customer_id,
            'retired' => $certificate->retired_at !== null,
        ];
    }
}
