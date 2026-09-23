<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Domain\Shared\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * How this API renders things, in one place.
 *
 * **Money is minor units and a code, never a float and never a formatted
 * string.** A float cannot hold 0.1 and a formatted string is a decision
 * about somebody else's locale; a client that wants "€14.99" has the
 * currency and the integer and can do it correctly for its own user. This
 * is the same rule the rest of the platform obeys, carried to its edge —
 * and a test asserts no response contains a monetary float.
 *
 * Dates are ISO 8601 with an offset, always. A date without a zone is a
 * date that means something different to everybody who reads it.
 */
final readonly class ApiResource
{
    /**
     * @return array{amount: int, currency: string}
     */
    public static function money(Money $money): array
    {
        return [
            'amount' => $money->minorUnits,
            'currency' => $money->currency->code,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function page(LengthAwarePaginator $paginator, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>}
     */
    public static function item(array $data): array
    {
        return ['data' => $data];
    }
}
