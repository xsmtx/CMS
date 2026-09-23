<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\JsonResponse;

/**
 * Who this token belongs to.
 *
 * The first call any integration makes, and the one that answers "did my
 * token work and what can it see". It returns the contact and their
 * customer, never the other contacts on the account: a token is a person's,
 * and listing their colleagues is a different scope that does not exist
 * yet.
 */
final class ProfileController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        $contact = $this->customer->contact();
        $customer = $this->customer->model();

        $customer->loadMissing('primaryContact');

        return new JsonResponse(ApiResource::item([
            'id' => $contact->id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'locale' => $contact->locale,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'status' => $customer->status->value,
                'currency' => $customer->currency_code,
            ],
        ]));
    }
}
