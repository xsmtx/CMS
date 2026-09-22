<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Logging channel tap.
 *
 * Always installs secret redaction. Switches the handler to a single-line
 * JSON formatter when `LOG_STRUCTURED=true`, which is the expected setting in
 * every deployed environment where logs are shipped to a collector.
 */
final class ConfigureStructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $redactor = app(SecretRedactor::class);
        $structured = (bool) config('platform.logging.structured', false);

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new RedactSecretsProcessor($redactor));
            }

            if ($structured && $handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(
                    new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, appendNewline: true),
                );
            }
        }
    }
}
