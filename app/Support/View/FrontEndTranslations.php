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

        'billing.credit_notes',
        'billing.details_saved',
        'billing.gateways',
        'billing.invoices',
        'billing.methods',
        'billing.payment_statuses',
        'billing.payments',
        'billing.portal',
        'billing.statuses',
        'billing.transaction_kinds',
        'billing.transactions',

        'catalog.cycles',

        'crm.fields',
        'crm.save',

        'identity.tokens',

        'ordering.cart',
        'ordering.orders',
        'ordering.portal',
        'ordering.statuses',

        'provisioning.portal',
        'provisioning.services',
        'provisioning.statuses',
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
