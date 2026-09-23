<?php

declare(strict_types=1);

namespace App\Infrastructure\Licensing;

use App\Domain\Licensing\Contracts\LicenceClient;
use App\Domain\Licensing\Exceptions\LicenceUnreachable;
use App\Support\Correlation\CorrelationContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The vendor's licence API, over HTTPS.
 *
 * The only remote system in this product whose availability must never matter
 * to a running installation, which shapes everything here:
 *
 * - **A timeout that is short.** Ten seconds, not thirty. Nothing a customer
 *   is waiting for depends on this call; the heartbeat runs in a queue worker
 *   and an operator pressing Activate would rather be told it failed than
 *   watch a spinner.
 * - **Two retries with backoff**, because a licence server behind a load
 *   balancer drops the occasional connection and re-asking is free.
 * - **A correlation ID on every request**, so a vendor debugging an
 *   activation failure and an operator reading their own log are looking at
 *   the same string.
 * - **Failure is one exception type**, `LicenceUnreachable`, and it never
 *   carries the body. A vendor's error page in a customer's flash message is
 *   a vendor's stack trace in a customer's screenshot.
 *
 * **It returns the token, never a verdict.** A client that returned
 * "valid: true" would be a client somebody could replace with one that always
 * says so; the installation proves the token itself with the embedded public
 * key. This class is a transport and nothing else.
 *
 * It has never talked to a real licence server. Its request shapes, retries
 * and error handling are tested against faked HTTP, which proves the code and
 * not the integration.
 */
final readonly class HttpLicenceClient implements LicenceClient
{
    public function __construct(
        private string $baseUrl,
        private CorrelationContext $correlation,
        private int $timeout = 10,
        private int $retries = 2,
    ) {}

    public function activate(string $licenceKey, string $installationId, array $claims): string
    {
        return $this->token('activate', [
            'license_key' => $licenceKey,
            'installation_id' => $installationId,
            'claims' => $claims,
        ]);
    }

    public function heartbeat(string $licenceKey, string $installationId, array $claims): string
    {
        return $this->token('heartbeat', [
            'license_key' => $licenceKey,
            'installation_id' => $installationId,
            'claims' => $claims,
        ]);
    }

    public function deactivate(string $licenceKey, string $installationId): void
    {
        $this->post('deactivate', [
            'license_key' => $licenceKey,
            'installation_id' => $installationId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function token(string $endpoint, array $payload): string
    {
        $response = $this->post($endpoint, $payload);

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            // A 200 with no token is a licence server that answered without
            // answering. Treated as unreachable rather than as a refusal:
            // "the vendor is broken" and "your licence is revoked" must never
            // be the same outcome.
            throw LicenceUnreachable::noToken($endpoint);
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $endpoint, array $payload): Response
    {
        $url = rtrim($this->baseUrl, '/').'/v1/licenses/'.$endpoint;

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout($this->timeout)
                // Exponential-ish: 200ms then 400ms. A licence server that is
                // down stays down, and a client that retried for a minute
                // would hold a queue worker for a minute.
                ->retry($this->retries, 200, throw: false)
                ->withHeaders(['X-Correlation-Id' => $this->correlation->idOrGenerate()])
                ->post($url, $payload);
        } catch (Throwable $exception) {
            // Connection refused, DNS, TLS. The message is the vendor's or the
            // network's and neither belongs in front of an operator.
            throw LicenceUnreachable::transport($endpoint, $exception::class);
        }

        if ($response->failed()) {
            throw LicenceUnreachable::status($endpoint, $response->status());
        }

        return $response;
    }
}
