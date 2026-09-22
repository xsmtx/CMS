<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer the signed-in contact belongs to.
 *
 * The organization boundary answers "is this record inside the acting
 * organization's subtree", which for a customer portal is not the whole
 * question: a contact is inside their own customer's organization and holds
 * `portal.billing.view`, and neither fact says anything about *which*
 * invoice they just asked for.
 *
 * So client screens resolve their records through here instead of filtering
 * by hand. `owned()` narrows a query to this customer, and `find()` either
 * returns the record or throws — meaning a controller that forgets to scope
 * has to forget to use this class at all, which is visible in review in a
 * way a missing `where` is not.
 *
 * A record that fails ownership answers **404, not 403**. A 403 confirms
 * that the invoice exists, and invoice numbers are sequential.
 */
final readonly class CurrentCustomer
{
    public function __construct(private CurrentActor $actor) {}

    public function contact(): Contact
    {
        $contact = $this->actor->model();

        if (! $contact instanceof Contact) {
            throw new NotFoundHttpException;
        }

        return $contact;
    }

    public function id(): string
    {
        return $this->contact()->customer_id;
    }

    /**
     * `contacts.customer_id` is not nullable, so this always resolves.
     */
    public function model(): Customer
    {
        return $this->contact()->owningCustomer();
    }

    /**
     * Narrow a query to this customer.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function owned(Builder $query, string $column = 'customer_id'): Builder
    {
        return $query->where($column, $this->id());
    }

    /**
     * The one record, or 404.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    public function find(Builder $query, string $column = 'customer_id'): Model
    {
        $model = $this->owned($query, $column)->first();

        if (! $model instanceof Model) {
            throw new NotFoundHttpException;
        }

        return $model;
    }
}
