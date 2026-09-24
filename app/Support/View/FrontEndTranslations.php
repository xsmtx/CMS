<?php

declare(strict_types=1);

namespace App\Support\View;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

/**
 * The strings the browser renders, and only those.
 *
 * Rendered once into the document rather than shared through Inertia on
 * every navigation: the locale does not change between two clicks, and a
 * few kilobytes repeated on every request is a tax paid forever for
 * nothing.
 *
 * It is an **allow-list of paths**, not a list of files, because a language
 * file is not written for one audience. `lang/en/ordering.php` holds the
 * customer's cart next to the wording of every fraud rule; shipping the
 * whole file to every browser would publish the shape of the risk engine to
 * anybody willing to read a page source. A group is only here when a page
 * actually draws it.
 *
 * Adding `t('billing.something')` to a component therefore means adding its
 * path here. That is the point: the decision to publish a string is made
 * once, visibly, in one file.
 */
final class FrontEndTranslations
{
    /**
     * @var list<string>
     */
    private const array PATHS = [
        'portal',

        /*
         * The Setup page's own words: its heading and its two section
         * headings. The tiles themselves carry their label and sentence in the
         * props, because which tiles exist is a permission question the server
         * answers — publishing the whole `areas` array would tell every browser
         * the names of screens its reader cannot open.
         */
        'apps.heading',
        'apps.description',
        'apps.sections',

        'billing.credit_notes',
        'billing.details_saved',
        'billing.gateways',
        'billing.invoices',
        'billing.methods',
        'billing.payment_statuses',
        'billing.payments',
        'billing.portal',
        // Setup -> Billing terms. Operator vocabulary only; the fee wording a
        // customer reads is rendered server side onto the document.
        'billing.settings',
        'marketplace',
        'billing.statuses',
        'billing.transaction_kinds',
        'billing.transactions',

        'catalog.cycles',

        'crm.fields',
        'crm.save',

        'domains.domains',
        'domains.portal',
        'domains.statuses',

        'identity.tokens',
        'api.tokens',
        'api.scopes',
        'api.webhooks',
        'api.deliveries',

        /*
         * The Resource Graph screens (Phase A). Operator vocabulary only: the
         * kinds, the relations and the three screens' own words. `capabilities`
         * is deliberately absent — the server labels those through
         * `CapabilityNames`, because a key with dots in it is not a path a
         * translator can walk.
         */
        'infrastructure.kinds',
        'infrastructure.relations',
        'infrastructure.areas',
        'infrastructure.units',
        'infrastructure.metrics',
        'infrastructure.explorer',
        'infrastructure.adapters',
        'infrastructure.telemetry',

        /*
         * The tax screen's own vocabulary. Operator words only, and it names no
         * jurisdiction — there is no list of countries in either language's
         * `tax.php` and there must not be one (ADR 0045).
         */
        'tax',

        'ordering.cart',
        'ordering.orders',
        'ordering.portal',
        'ordering.statuses',

        'notifications.portal',

        // The aging buckets, which are the one set of report labels a Vue
        // component draws. Everything else on the reports screen is a figure
        // the server already formatted.
        'reports.aging',

        'provisioning.portal',
        'provisioning.services',
        'provisioning.statuses',

        'support.portal',
        'support.statuses',
        'support.tickets',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forLocale(string $locale): array
    {
        $messages = [];

        foreach (self::PATHS as $path) {
            if (! Lang::has($path, $locale)) {
                continue;
            }

            Arr::set($messages, $path, Lang::get($path, [], $locale));
        }

        return $messages;
    }
}
