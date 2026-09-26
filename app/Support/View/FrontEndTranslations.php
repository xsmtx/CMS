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
        'billing.gateway_log',
        'billing.invoices',
        'billing.methods',
        'billing.payment_statuses',
        'billing.payments',
        'billing.portal',
        // Setup -> Billing terms. Operator vocabulary only; the fee wording a
        // customer reads is rendered server side onto the document.
        'billing.settings',
        'marketplace',
        'modules.settings',
        'billing.statuses',
        'billing.transaction_kinds',
        'billing.transactions',

        /*
         * The operations screen's own words. `automation.tasks` stays on the
         * server: a task's name is printed onto a run record, and the browser
         * has no use for the list of everything this installation can run.
         */
        'automation.operations',
        'automation.dunning',
        'automation.runs',
        'automation.title',
        'automation.description',

        'catalog.addons',
        'catalog.products',
        'catalog.pricing',
        'catalog.cycles',
        'catalog.groups',
        'catalog.currencies',
        'catalog.promotions',

        'access.screen',
        'organizations.tree',
        'organizations.resellers',

        'crm.fields',
        'crm.save',
        // Customer status and address-type names, drawn by the client detail
        // page. The same words the server already prints on the list.
        'crm.statuses',
        'crm.address_types',

        // The cancellation queue's own words, including the two sentences
        // that say what completing a request actually does to a service.
        'crm.cancellations',

        /*
         * The design-system primitives and the enterprise reference screens
         * (ADR 0048). Chrome and screen vocabulary only; nothing operator-
         * private lives in `ui`.
         */
        'ui',

        'domains.domains',
        'domains.portal',
        'domains.statuses',

        /*
         * Two sentences, not the group. `identity.auth` holds the refusals the
         * server prints — "those credentials do not match our records", the
         * throttle wording — and publishing the lot would hand a browser the
         * vocabulary of every way sign-in can fail. These two are the
         * confirm-password screen's own heading and explanation, and it drew
         * them as `identity.auth.confirm_title` at an operator until a test
         * started asking.
         */
        'identity.auth.confirm_title',
        'identity.auth.confirm_body',

        /*
         * The impersonation banner. It was hard-coded English above a portal
         * that was otherwise fully Turkish — and the wording had existed in
         * both language files the whole time, read by nobody.
         */
        'identity.impersonation.active',
        'identity.impersonation.stop',

        'identity.staff',
        'identity.users',
        'identity.statuses',
        'identity.tokens',
        'api.tokens',
        'api.scopes',
        'api.webhooks',
        'api.deliveries',
        'api.activity',

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
        'infrastructure.capacity',
        'infrastructure.explorer',
        'infrastructure.adapters',
        'infrastructure.telemetry',

        // Addressing is operator vocabulary end to end - no customer sees a
        // prefix - so the whole group is published rather than leaf paths.
        'network',

        // Alerting is operator vocabulary end to end — no customer sees a
        // threshold — so the whole group is published rather than leaf paths.
        'reliability',

        /*
         * The tax screen's own vocabulary. Operator words only, and it names no
         * jurisdiction — there is no list of countries in either language's
         * `tax.php` and there must not be one (ADR 0045).
         */
        'tax',

        'operations.todo',

        'ordering.cart',
        'ordering.orders',
        'ordering.portal',
        'ordering.statuses',

        'notifications.portal',
        'notifications.admin',

        // The aging buckets, which are the one set of report labels a Vue
        // component draws. Everything else on the reports screen is a figure
        // the server already formatted.
        'reports.aging',

        'provisioning.addons',
        'provisioning.connect',
        'provisioning.placement',
        'provisioning.portal',
        'provisioning.services',
        'provisioning.statuses',

        'support.announcements',
        'support.portal',
        'support.statuses',
        'support.tickets',
        'support.replies',
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
