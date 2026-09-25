<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Access\PermissionNames;
use App\Application\Crm\ClientAddressAttributes;
use App\Application\Crm\ClientContactAttributes;
use App\Application\Crm\CreateClient;
use App\Application\Crm\CustomerAttributes;
use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Notifications\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ClientRequest;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Crm\Models\Tag;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adding a client the way an operator does it: on the telephone, once.
 *
 * Separate from `CustomerController::create`, which edits the commercial
 * record alone. This screen creates the company, the person and the
 * address together, because a customer with no contact cannot sign in and
 * one with no address cannot be invoiced in most of Europe — and an
 * operator who has to visit three screens will finish one of them.
 *
 * **Anything local is a custom field**, and every customer custom field
 * this installation has defined appears on this form automatically. A
 * national identity number, a tax office, a second mobile: hard-coding one
 * country's vocabulary into an international product makes every other
 * country's a second-class citizen forever.
 */
final class ClientController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly PermissionNames $names,
    ) {}

    public function create(): Response
    {
        $this->authorizeFor('crm.customers.manage');

        return Inertia::render('Admin/Customers/Create', [
            'statuses' => array_map(
                static fn (CustomerStatus $status): array => [
                    'value' => $status->value,
                    'label' => (string) __($status->labelKey()),
                ],
                CustomerStatus::cases(),
            ),
            'currencies' => $this->currencies(),
            'tags' => $this->tags(),
            'roles' => $this->roles($this->names),
            'customFields' => $this->customFields(),
            'defaults' => [
                // The installation's own, so an operator in one country is
                // not retyping the same two answers all day.
                'country' => (string) config('platform.crm.default_country', 'TR'),
                'currency' => (string) config('platform.crm.default_currency', 'TRY'),
                'locale' => (string) config('app.locale'),
                'phonePlaceholder' => (string) config('platform.crm.phone_placeholder', '+90 501 234 56 78'),
            ],
        ]);
    }

    public function store(
        ClientRequest $request,
        CreateClient $clients,
        Notifier $notifier,
        ResolveRecipients $recipients,
    ): RedirectResponse {
        $this->authorizeFor('crm.customers.manage');

        /** @var list<string> $tagIds */
        $tagIds = $request->input('tag_ids', []);

        /** @var array<string, mixed> $customFields */
        $customFields = $request->input('custom_fields', []);

        $customer = $clients->handle(
            (string) $this->actor->organizationId(),
            new CustomerAttributes(
                companyName: $request->input('company_name'),
                legalName: $request->input('legal_name'),
                taxId: $request->input('tax_id'),
                taxIdType: $request->input('tax_id_type'),
                status: CustomerStatus::from($request->string('status')->toString()),
                currencyCode: $request->string('currency_code')->toString(),
                marketingOptIn: $request->boolean('marketing_opt_in'),
                tagIds: $tagIds,
                customFields: $customFields,
                sendOverdueNotices: $request->boolean('send_overdue_notices', true),
                automaticSuspension: $request->boolean('automatic_suspension', true),
                separateInvoices: $request->boolean('separate_invoices'),
            ),
            new ClientContactAttributes(
                firstName: $request->string('first_name')->toString(),
                lastName: $request->string('last_name')->toString(),
                email: $request->string('email')->toString(),
                phone: $request->input('phone'),
                locale: $request->input('locale'),
                password: $request->input('password'),
                role: SystemRole::tryFrom((string) $request->input('role')) ?? SystemRole::AccountOwner,
                notifyInvoices: $request->boolean('notify_invoices', true),
                notifySupport: $request->boolean('notify_support', true),
                notifyProduct: $request->boolean('notify_product', true),
                notifyMarketing: $request->boolean('notify_marketing'),
            ),
            $this->address($request),
            $this->actor->model(),
        );

        $this->attachNote($customer, $request->input('notes'));

        if ($request->boolean('send_welcome')) {
            $this->welcome($customer, $notifier, $recipients);
        }

        return to_route('admin.customers.show', $customer)
            ->with('status', __('crm.customer_created'));
    }

    private function address(ClientRequest $request): ?ClientAddressAttributes
    {
        $line = $request->input('address_line_one');

        if (! is_string($line) || trim($line) === '') {
            // No street, no address. A country on its own is not one, and
            // storing it would produce an invoice addressed to a country.
            return null;
        }

        return new ClientAddressAttributes(
            lineOne: $line,
            city: (string) $request->input('city'),
            countryCode: (string) $request->input('country_code'),
            lineTwo: $request->input('address_line_two'),
            region: $request->input('region'),
            postalCode: $request->input('postal_code'),
        );
    }

    private function attachNote(Customer $customer, mixed $note): void
    {
        if (! is_string($note) || trim($note) === '') {
            return;
        }

        $author = $this->actor->model();

        $customer->notes()->create([
            'organization_id' => $customer->organization_id,
            // Who wrote it, twice: the morph so the record can be followed
            // back to a live account, and a label so it still reads as
            // somebody's note after that account is gone.
            'author_type' => $author === null ? null : $author->getMorphClass(),
            'author_id' => $author?->getKey(),
            'author_label' => $this->actorLabel($author),
            'body' => trim($note),
            // An operator's note about a customer, never a message to them.
            'is_customer_visible' => false,
        ]);
    }

    private function actorLabel(?Model $author): ?string
    {
        if (! $author instanceof Model) {
            return null;
        }

        $name = $author->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The welcome message, through the one path that sends anything.
     *
     * Not a bespoke mail: the wording is a template an operator can edit
     * and the send is recorded in the delivery log like every other
     * (ADR 0029).
     */
    private function welcome(Customer $customer, Notifier $notifier, ResolveRecipients $recipients): void
    {
        $customer->loadMissing('primaryContact');

        $notifier->send(
            NotificationEvent::OrderPlaced,
            $recipients->forCustomer($customer, NotificationEvent::OrderPlaced),
            ['customer' => $customer->displayName()],
            url('/client'),
            organizationId: $customer->organization_id,
        );
    }

    /**
     * The two roles a first contact can be given, and what each one can do.
     *
     * The capabilities are read from the roles themselves rather than
     * written out here. An operator choosing between them deserves to see
     * what they are choosing, and a hard-coded list would start lying the
     * first time a phase gives a role a new permission — which Phase 8
     * already did once, to `support`.
     *
     * @return list<array<string, mixed>>
     */
    private function roles(PermissionNames $names): array
    {
        $roles = Role::query()
            ->whereIn('slug', [SystemRole::AccountOwner->value, SystemRole::PortalMember->value])
            ->with('permissions:id,slug,orphaned_at')
            ->get()
            ->keyBy(static fn (Role $role): string => $role->slug);

        return array_values(array_map(
            static function (SystemRole $role) use ($roles, $names): array {
                $record = $roles->get($role->value);

                return [
                    'value' => $role->value,
                    'label' => (string) __('access.roles.'.$role->value),
                    // Named, not slugged, and named by the same class the
                    // roles screen uses — two vocabularies for one thing is
                    // how a product ends up calling one capability two
                    // names.
                    'can' => $record instanceof Role
                        ? array_values($record->permissions
                            ->whereNull('orphaned_at')
                            ->map(static fn (object $permission): string => $names->label(
                                (string) $permission->getAttribute('slug'),
                            ))
                            ->sort()
                            ->all())
                        : [],
                ];
            },
            [SystemRole::AccountOwner, SystemRole::PortalMember],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customFields(): array
    {
        return array_values(CustomFieldDefinition::query()
            ->where('entity_type', CustomFieldEntity::Customer->value)
            ->orderBy('position')
            ->get()
            ->map(static fn (CustomFieldDefinition $field): array => [
                'key' => $field->key,
                'label' => $field->label,
                'type' => $field->type->value,
                'options' => $field->options ?? [],
                'required' => $field->is_required,
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, string>>
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
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(): array
    {
        return array_values(Tag::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (Tag $tag): array => ['value' => $tag->id, 'label' => $tag->name])
            ->values()
            ->all());
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('crm.errors.not_permitted'));
        }
    }
}
