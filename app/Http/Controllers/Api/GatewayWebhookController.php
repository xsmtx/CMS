<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Billing\HandleGatewayEvent;
use App\Domain\Billing\WebhookRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where gateways call back.
 *
 * Unauthenticated by necessity and unauthenticated by design: the proof is
 * the signature on the body, not a session or a token. Nothing here reads
 * the payload — that happens inside the adapter, after it has verified the
 * bytes.
 *
 * The response is deliberately bland. A gateway retries on anything that is
 * not a 2xx, so an event this platform has already handled, cannot match,
 * or refuses to trust still answers 200: a retry would not change the
 * outcome, and a queue of undeliverable webhooks at the provider is noise
 * that hides the real failures.
 */
final class GatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, HandleGatewayEvent $handler): JsonResponse
    {
        $result = $handler->handle($gateway, new WebhookRequest(
            // The raw bytes. A signature is computed over exactly these,
            // and re-encoding the JSON would change them.
            body: $request->getContent(),
            headers: array_change_key_case(
                array_map(
                    static fn (array $values): string => $values[0] ?? '',
                    $request->headers->all(),
                ),
            ),
            ipAddress: $request->ip(),
        ));

        // An unknown gateway is a configuration problem at the far end and
        // the only case worth answering with an error, because retrying it
        // cannot help either.
        $status = $result['reason'] === 'unknown_gateway' ? 404 : 200;

        return response()->json(['received' => true], $status);
    }
}
