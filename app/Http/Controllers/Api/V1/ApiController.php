<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Identity\CurrentCustomer;

/**
 * What every v1 controller shares.
 *
 * Deliberately almost nothing. The API's controllers validate, authorize,
 * call a use case and render — the same job as a portal controller — and a
 * fat base class here would become the second place rules live.
 *
 * `CurrentCustomer` is the one piece worth sharing: it is what turns "the
 * signed-in actor" into "the records they own", and it answers 404 rather
 * than 403 for somebody else's row so that an id cannot be used to discover
 * what exists.
 */
abstract class ApiController extends Controller
{
    public function __construct(protected readonly CurrentCustomer $customer) {}
}
