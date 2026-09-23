<?php

declare(strict_types=1);

namespace Example\StatusBoard;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use Throwable;

/**
 * Did the watched URL answer?
 *
 * Two rules taken straight from the contract, and both of them matter more
 * in a module than in core:
 *
 * - **It never throws.** A health page is opened when something is already
 *   broken. A check from a third-party package that died would have taken
 *   down the one screen that was going to explain why.
 * - **It never returns a configuration value.** The URL being watched is
 *   not in the report — not in the detail, not in the measurements. "Did
 *   not answer in 5s" proves what is wrong without publishing where an
 *   installation's upstream lives to everyone who can read a status page.
 *
 * The request is bounded and uses no framework HTTP client: a module is
 * given platform contracts, not the application's dependencies
 * (ADR 0039).
 */
final readonly class StatusBoardCheck implements HealthCheck
{
    public function __construct(
        private string $url,
        private int $timeoutSeconds,
    ) {}

    public function key(): string
    {
        return 'status-board';
    }

    public function run(): HealthReport
    {
        $started = microtime(true);

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => $this->timeoutSeconds,
                    'ignore_errors' => true,
                    // A redirect chain is somebody else's infrastructure
                    // deciding where this installation's health check
                    // goes. One hop, no further.
                    'follow_location' => 0,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $handle = @fopen($this->url, 'r', false, $context);

            if ($handle === false) {
                return HealthReport::failing($this->key(), 'The address did not answer.');
            }

            $status = $this->statusFrom($http_response_header ?? []);

            fclose($handle);

            $milliseconds = (int) round((microtime(true) - $started) * 1000);

            if ($status === null) {
                return HealthReport::degraded($this->key(), 'Answered, but not with a status line.');
            }

            if ($status >= 500) {
                return HealthReport::failing(
                    $this->key(),
                    'Answered '.$status.'.',
                    ['ms' => $milliseconds],
                );
            }

            if ($status >= 400) {
                return HealthReport::degraded(
                    $this->key(),
                    'Answered '.$status.'.',
                    ['ms' => $milliseconds],
                );
            }

            return HealthReport::ok($this->key(), ['ms' => $milliseconds]);
        } catch (Throwable) {
            // Deliberately without the exception message: a stream error
            // carries the host and the path, and this report is read on a
            // page that says nothing about how anything is configured.
            return HealthReport::failing($this->key(), 'The check could not run.');
        }
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function statusFrom(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
