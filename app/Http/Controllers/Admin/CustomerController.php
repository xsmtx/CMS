<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Crm\AnonymizeCustomer;
use App\Application\Crm\CreateCustomer;
use App\Application\Crm\CustomerAttributes;
use App\Application\Crm\ExportCustomerData;
use App\Application\Crm\SearchCustomers;
use App\Application\Crm\UpdateCustomer;
use App\Domain\Crm\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\AnonymizeCustomerRequest;
use App\Http\Requests\Crm\CustomerRequest;
use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Crm\Models\Note;
use App\Infrastructure\Crm\Models\Tag;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer administration.
 *
 * Every list and lookup here is bounded by the organization scope, so a
 * reseller's staff see their own customers and nobody else's without this
 * controller filtering anything itself.
 */
final class CustomerController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    /**
     * The client list.
     *
     * **Closed accounts are hidden by default.** A closed customer is a
     * record the accounts department keeps, not somebody an operator is
     * working with, and after a few years they are most of the table. One
     * switch brings them back — a default, not a filter, so nothing has to
     * be un-set to see the normal view.
     *
     * The service counts are `withCount`, not a relation an operator's
     * scroll would load per row: a hundred customers on a page is a hundred
     * queries the moment somebody reads `$customer->services`.
     */
    public function index(Request $request, SearchCustomers $search): Response
    {
        $this->authorize('viewAny', Customer::class);

        /** @var array<string, mixed> $criteria */
        $criteria = $request->query();

        $includeInactive = $request->boolean('inactive');

        $customers = $search
            ->paginate($criteria, includeClosed: $includeInactive)
            ->through(fn (Customer $customer): array => [
                'id' => $customer->id,
                'firstName' => $customer->primaryContact?->first_name,
                'lastName' => $customer->primaryContact?->last_name,
                'company' => $customer->company_name,
                'name' => $customer->displayName(),
                'email' => $customer->primaryContact?->email,
                'activeServices' => (int) $customer->getAttribute('active_services_count'),
                'inactiveServices' => (int) $customer->getAttribute('inactive_services_count'),
                'status' => $customer->status->value,
                'statusLabel' => (string) __($customer->status->labelKey()),
                'tags' => $customer->tags->pluck('name')->all(),
                'createdAt' => $customer->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers,
            'filters' => $this->filters($request),
            'statuses' => $this->statuses(),
            'currencies' => $this->currencies(),
            'countries' => $this->countries(),
            'tags' => $this->tags(),
            'permissions' => $this->searchablePermissions(),
            'schema' => $search->schema(),
            // Labels come from the server because that is where `__()`
            // is. The admin Vue has no translation mechanism of its own,
            // and inventing one for a single screen would leave every
            // other admin page still in English.
            'labels' => [
                ...(array) __('crm.search'),
                'list' => (array) __('crm.list'),
            ],
            'can' => ['create' => $request->user('staff')?->can('create', Customer::class) ?? false],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('Admin/Customers/Form', [
            'customer' => null,
            'statuses' => $this->statuses(),
            'tags' => $this->tags(),
            'customFields' => $this->customFieldSchema(null),
        ]);
    }

    public function store(CustomerRequest $request, CreateCustomer $createCustomer): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        // The new customer organization hangs off the creator's own, which
        // is what makes a reseller's customers theirs.
        $customer = $createCustomer->handle(
            (string) $request->user('staff')?->organization_id,
            $this->attributes($request),
            $this->actor->model(),
        );

        return to_route('admin.customers.show', $customer)->with('status', __('crm.customer_created'));
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        // `displayName()` falls back to the primary contact when there is
        // no company or legal name, so the relation is part of rendering a
        // customer's name rather than an optional extra.
        $customer->loadMissing('primaryContact');

        return Inertia::render('Admin/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'companyName' => $customer->company_name,
                'legalName' => $customer->legal_name,
                'taxId' => $customer->tax_id,
                'taxIdType' => $customer->tax_id_type,
                'status' => $customer->status->value,
                'currencyCode' => $customer->currency_code,
                'marketingOptIn' => $customer->marketing_opt_in,
                'anonymized' => $customer->isAnonymized(),
                'createdAt' => $customer->created_at?->toIso8601String(),
                'tags' => $customer->tags->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])->all(),
            ],
            'contacts' => $customer->contacts()->get()->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'isPrimary' => $contact->is_primary,
                'portalAccess' => $contact->portal_access,
                'status' => $contact->status->value,
                'twoFactor' => $contact->hasTwoFactorEnabled(),
                'lastLoginAt' => $contact->last_login_at?->toIso8601String(),
            ])->all(),
            'addresses' => $customer->addresses()->get()->map(fn (Address $address): array => [
                'id' => $address->id,
                'type' => $address->type->value,
                'line' => $address->toSingleLine(),
                'isDefault' => $address->is_default,
            ])->all(),
            'notes' => $customer->notes()->get()->map(fn (Note $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author_label,
                'customerVisible' => $note->is_customer_visible,
                'pinned' => $note->is_pinned,
                'createdAt' => $note->created_at?->toIso8601String(),
            ])->all(),
            'customFields' => $this->customFieldSchema($customer),
            'can' => [
                'update' => $this->actor->can('update', $customer),
                'export' => $this->actor->can('export', $customer),
                'anonymize' => $this->actor->can('anonymize', $customer),
                'impersonate' => $this->actor->can('identity.contacts.impersonate'),
            ],
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Admin/Customers/Form', [
            'customer' => [
                'id' => $customer->id,
                'companyName' => $customer->company_name,
                'legalName' => $customer->legal_name,
                'taxId' => $customer->tax_id,
                'taxIdType' => $customer->tax_id_type,
                'status' => $customer->status->value,
                'currencyCode' => $customer->currency_code,
                'marketingOptIn' => $customer->marketing_opt_in,
                'sendOverdueNotices' => $customer->send_overdue_notices,
                'automaticSuspension' => $customer->automatic_suspension,
                'separateInvoices' => $customer->separate_invoices,
                'tagIds' => $customer->tags->pluck('id')->all(),
            ],
            'statuses' => $this->statuses(),
            'tags' => $this->tags(),
            'customFields' => $this->customFieldSchema($customer),
        ]);
    }

    public function update(
        CustomerRequest $request,
        Customer $customer,
        UpdateCustomer $updateCustomer,
    ): RedirectResponse {
        $this->authorize('update', $customer);

        $updateCustomer->handle($customer, $this->attributes($request), $this->actor->model());

        return to_route('admin.customers.show', $customer)->with('status', __('crm.customer_updated'));
    }

    /**
     * Subject access: everything held about this customer, as JSON.
     */
    public function export(Customer $customer, ExportCustomerData $exportCustomerData): JsonResponse
    {
        $this->authorize('export', $customer);

        return new JsonResponse(
            $exportCustomerData->handle($customer, $this->actor->model()),
            headers: ['Content-Disposition' => 'attachment; filename="customer-'.$customer->id.'.json"'],
        );
    }

    public function anonymize(
        AnonymizeCustomerRequest $request,
        Customer $customer,
        AnonymizeCustomer $anonymizeCustomer,
    ): RedirectResponse {
        $this->authorize('anonymize', $customer);

        $anonymizeCustomer->handle(
            $customer,
            $request->string('reason')->toString(),
            $this->actor->model(),
        );

        return to_route('admin.customers.show', $customer)->with('status', __('crm.customer_anonymized'));
    }

    /**
     * Everything the form put in the URL, echoed back so the panel reopens
     * showing what it searched for.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        /** @var array<string, mixed> $query */
        $query = $request->query();

        return [
            ...$query,
            'inactive' => $request->boolean('inactive'),
            'permissions' => is_array($query['permissions'] ?? null) ? $query['permissions'] : [],
            'custom' => is_array($query['custom'] ?? null) ? $query['custom'] : [],
        ];
    }

    /**
     * The currencies this installation actually sells in.
     *
     * @return list<array{value: string, label: string}>
     */
    private function currencies(): array
    {
        return array_values(CurrencyRecord::query()
            ->orderBy('code')
            ->get()
            ->map(static fn (CurrencyRecord $currency): array => [
                'value' => $currency->code,
                'label' => $currency->code,
            ])
            ->all());
    }

    /**
     * The countries customers are actually in, rather than every ISO code.
     *
     * A select of two hundred entries where four are in use is a select
     * nobody scrolls.
     *
     * @return list<array{value: string, label: string}>
     */
    private function countries(): array
    {
        return array_values(Address::query()
            ->select('country_code')
            ->distinct()
            ->orderBy('country_code')
            ->pluck('country_code')
            ->filter(static fn (?string $code): bool => is_string($code) && $code !== '')
            ->map(static fn (string $code): array => ['value' => $code, 'label' => $code])
            ->all());
    }

    /**
     * What a contact can be allowed to do, as things to search on.
     *
     * Permissions rather than a column per capability, because that is how
     * this platform models it — there is no `can_open_tickets` column and
     * there should not be one.
     *
     * @return list<array{value: string, label: string}>
     */
    private function searchablePermissions(): array
    {
        $searchable = [
            'portal.tickets.create',
            'portal.tickets.view',
            'portal.orders.view',
            'portal.payment_methods.manage',
            'portal.billing.pay',
        ];

        return array_values(array_map(
            // Labelled here rather than from the permission registry:
            // Laravel splits a translation key on dots, so
            // `portal.tickets.create` cannot be one.
            static fn (string $slug): array => [
                'value' => $slug,
                'label' => (string) __('crm.search.permissions.'.str_replace('.', '_', $slug)),
            ],
            $searchable,
        ));
    }

    private function attributes(CustomerRequest $request): CustomerAttributes
    {
        /** @var list<string> $tagIds */
        $tagIds = $request->input('tag_ids', []);

        /** @var array<string, mixed> $customFields */
        $customFields = $request->input('custom_fields', []);

        return new CustomerAttributes(
            companyName: $request->input('company_name'),
            legalName: $request->input('legal_name'),
            taxId: $request->input('tax_id'),
            taxIdType: $request->input('tax_id_type'),
            status: CustomerStatus::from($request->string('status')->toString()),
            currencyCode: $request->string('currency_code')->toString(),
            marketingOptIn: $request->boolean('marketing_opt_in'),
            tagIds: $tagIds,
            customFields: $customFields,
            // Defaulted to what the platform does when nobody has said
            // otherwise, so a caller that never heard of these — the API,
            // an import — does not quietly turn a customer's arrangement
            // off by omitting a checkbox.
            sendOverdueNotices: $request->boolean('send_overdue_notices', true),
            automaticSuspension: $request->boolean('automatic_suspension', true),
            separateInvoices: $request->boolean('separate_invoices'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customFieldSchema(?Customer $customer): array
    {
        if ($customer !== null) {
            return $customer->customFieldSchema()
                ->map(fn (array $row): array => $this->definitionPayload($row['definition'], $row['value']))
                ->values()
                ->all();
        }

        return CustomFieldDefinition::query()
            ->where('entity_type', 'customer')
            ->orderBy('position')
            ->get()
            ->map(fn (CustomFieldDefinition $definition): array => $this->definitionPayload($definition, null))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionPayload(CustomFieldDefinition $definition, mixed $value): array
    {
        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'type' => $definition->type->value,
            'options' => $definition->options,
            'required' => $definition->is_required,
            'helpText' => $definition->help_text,
            'value' => $value,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tags(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function statuses(): array
    {
        return array_map(
            fn (CustomerStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            CustomerStatus::cases(),
        );
    }
}
