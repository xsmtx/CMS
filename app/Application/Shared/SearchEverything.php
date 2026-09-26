<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Infrastructure\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * The box at the top of the panel: one term, every record that could match.
 *
 * An operator on the telephone has one fact — a domain, an invoice number, a
 * surname, the IP of a server somebody is complaining about — and no idea
 * which screen it belongs to. Making them choose a screen first is making
 * them guess.
 *
 * Deliberately **narrow per kind and shallow overall**. Eight of each, no
 * paging, no scoring: this is a way to reach a record, not a report. Each
 * group links to the list that does support paging, carrying the same term,
 * so "there are more" has somewhere to go.
 *
 * The group headings are the nav map's own nouns, read through `__()`: the
 * box answers with the same words the rail uses, in the language the operator
 * reads the panel in.
 *
 * Every query runs inside the organization boundary, like everything else.
 * A reseller searching finds their own customers and nobody else's, without
 * this class knowing that is the rule.
 */
final readonly class SearchEverything
{
    private const int PER_GROUP = 8;

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = SearchPattern::like($term);

        return array_values(array_filter([
            $this->group('clients', (string) __('ui.nav.clients'), '/admin/customers?search='.rawurlencode($term), $this->clients($like)),
            $this->group('services', (string) __('ui.nav.products_services'), '/admin/services?domain='.rawurlencode($term), $this->services($like)),
            $this->group('domains', (string) __('ui.nav.domain_registrations'), '/admin/domains', $this->domains($like)),
            $this->group('invoices', (string) __('ui.nav.invoices'), '/admin/invoices', $this->invoices($like)),
            $this->group('orders', (string) __('ui.nav.orders'), '/admin/orders', $this->orders($like)),
            $this->group('tickets', (string) __('ui.nav.support_tickets'), '/admin/support', $this->tickets($like)),
            /*
             * And whatever the graph knows about (Phase B). An operator with
             * an IP address or a hostname from somebody else's ticket has a
             * fact that belongs to no screen in the panel — the resource it
             * names may be a server this platform provisioned, a switch port
             * a module discovered, or a machine nobody here has ever touched.
             * The Explorer is the one screen that can answer either way.
             */
            $this->group(
                'resources',
                (string) __('ui.nav.infrastructure'),
                '/admin/resources?q='.rawurlencode($term),
                $this->resources($like),
            ),
        ], static fn (?array $group): bool => $group !== null));
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, mixed>|null
     */
    private function group(string $key, string $label, string $more, array $rows): ?array
    {
        return $rows === [] ? null : [
            'key' => $key,
            'label' => $label,
            'more' => $more,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function clients(string $like): array
    {
        return array_values(Customer::query()
            ->with(Customer::displayNameWith())
            ->where(function (Builder $query) use ($like): void {
                $query->where('company_name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhere('tax_id', 'like', $like)
                    ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                        $contacts->where('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);

                        SearchPattern::name($contacts, $like);
                    });
            })
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (Customer $customer): array => [
                'title' => $customer->displayName(),
                'subtitle' => $customer->primaryContact instanceof Contact ? $customer->primaryContact->email : '',
                'href' => '/admin/customers/'.$customer->id,
            ])
            ->values()
            ->all());
    }

    /**
     * The hostname and the username too, because "the server at this
     * address" and "the account called this" are the two facts a support
     * call actually carries.
     *
     * @return list<array<string, string>>
     */
    private function services(string $like): array
    {
        return array_values(Service::query()
            ->with(Customer::displayNameWith('customer'))
            ->where(function (Builder $query) use ($like): void {
                $query->where('domain', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('external_id', 'like', $like);
            })
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (Service $service): array => [
                'title' => $service->name.($service->domain === null ? '' : ' — '.$service->domain),
                'subtitle' => $service->customer?->displayName() ?? '',
                'href' => '/admin/services/'.$service->id,
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, string>>
     */
    private function domains(string $like): array
    {
        return array_values(Domain::query()
            ->with(Customer::displayNameWith('customer'))
            ->where('name', 'like', $like)
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (Domain $domain): array => [
                'title' => $domain->name,
                'subtitle' => $domain->customer?->displayName() ?? '',
                'href' => '/admin/domains/'.$domain->id,
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, string>>
     */
    private function invoices(string $like): array
    {
        return array_values(Invoice::query()
            ->with(Customer::displayNameWith('customer'))
            ->where('number', 'like', $like)
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (Invoice $invoice): array => [
                'title' => $invoice->number,
                'subtitle' => $invoice->customer?->displayName() ?? '',
                'href' => '/admin/invoices/'.$invoice->id,
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, string>>
     */
    private function orders(string $like): array
    {
        return array_values(Order::query()
            ->with(Customer::displayNameWith('customer'))
            ->where('number', 'like', $like)
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (Order $order): array => [
                'title' => $order->number,
                'subtitle' => $order->customer?->displayName() ?? '',
                'href' => '/admin/orders/'.$order->id,
            ])
            ->values()
            ->all());
    }

    /**
     * Resources in the graph, by the key the source calls them or by the
     * label this platform cached.
     *
     * The key matters more than the label: an operator pasting `10.0.0.9` or
     * `web-07.dc2` has what the *source* calls the thing, and the label is
     * this platform's own name for it.
     *
     * @return list<array<string, string>>
     */
    private function resources(string $like): array
    {
        return array_values(ResourceNode::query()
            ->where(function (Builder $query) use ($like): void {
                $query->where('node_key', 'like', $like)->orWhere('label', 'like', $like);
            })
            ->whereNull('retired_at')
            ->orderBy('label')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (ResourceNode $node): array => [
                'title' => $node->label,
                'subtitle' => $node->node_key,
                'href' => '/admin/resources?q='.rawurlencode($node->node_key),
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, string>>
     */
    private function tickets(string $like): array
    {
        return array_values(Ticket::query()
            ->with(Customer::displayNameWith('customer'))
            ->where(function (Builder $query) use ($like): void {
                $query->where('number', 'like', $like)->orWhere('subject', 'like', $like);
            })
            ->limit(self::PER_GROUP)
            ->get()
            ->map(static fn (Ticket $ticket): array => [
                'title' => $ticket->number.' — '.$ticket->subject,
                'subtitle' => $ticket->customer?->displayName() ?? '',
                'href' => '/admin/support/'.$ticket->id,
            ])
            ->values()
            ->all());
    }
}
