<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Installation identity
    |--------------------------------------------------------------------------
    |
    | A persistent identifier for this installation. It is generated once on
    | first boot and never changes; the licensing control plane (Phase 11)
    | uses it to bind activations to a single installation.
    |
    */

    'installation_id' => env('PLATFORM_INSTALLATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Correlation identifiers
    |--------------------------------------------------------------------------
    |
    | Every inbound request, queued job and scheduled task carries a
    | correlation identifier so that logs, audit records and provider calls
    | can be joined together. Inbound identifiers are only trusted when the
    | application sits behind a proxy that is known to set the header.
    |
    */

    'correlation' => [
        'header' => env('CORRELATION_ID_HEADER', 'X-Correlation-Id'),
        'trust_inbound' => (bool) env('CORRELATION_ID_TRUST_INBOUND', false),
        'context_key' => 'correlation_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'structured' => (bool) env('LOG_STRUCTURED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret redaction
    |--------------------------------------------------------------------------
    |
    | Any array key containing one of these fragments (case-insensitive) has
    | its value replaced before the payload reaches a log line, an audit row
    | or an error response. Never remove an entry without an ADR.
    |
    */

    'redaction' => [
        'placeholder' => '[redacted]',
        'max_depth' => 16,
        'keys' => [
            'password',
            'passwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'private_key',
            'privatekey',
            'authorization',
            'auth',
            'credential',
            'session',
            'cookie',
            'signature',
            'card',
            'cvv',
            'cvc',
            'pan',
            'iban',
            'remember_token',
            'two_factor',
            'recovery_code',
        ],
        'redact_card_like_values' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Audit rows are append-only. Retention of zero keeps records forever,
    | which is the correct default for financial and provisioning history.
    |
    */

    'audit' => [
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    'access' => [
        'cache_ttl' => (int) env('ACCESS_CACHE_TTL', 900),
        'cache_prefix' => 'access:permissions:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Licensing client
    |--------------------------------------------------------------------------
    |
    | Declared here so deployments are forward compatible. The client itself
    | is implemented in Phase 11; nothing reads these values before then.
    |
    */

    'licensing' => [
        'api_url' => env('LICENSE_API_URL'),
        'key' => env('LICENSE_KEY'),
        'public_key_path' => env('LICENSE_PUBLIC_KEY_PATH'),
    ],

];
