<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Applies secret redaction to every log record's context and extra payload.
 *
 * Registered on the handlers rather than the logger so that it also covers
 * records forwarded by framework and package loggers.
 */
final readonly class RedactSecretsProcessor implements ProcessorInterface
{
    public function __construct(private SecretRedactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redact($record->context),
            extra: $this->redactor->redact($record->extra),
        );
    }
}
