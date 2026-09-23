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
    | Ordering
    |--------------------------------------------------------------------------
    |
    | Tax and risk are contracts, not rules. Core never implements a
    | country's tax law or names a fraud vendor; this is where an
    | installation says which implementation it wants and on what terms.
    |
    */

    'ordering' => [
        'terms_version' => env('ORDER_TERMS_VERSION', '1'),
        'cart_lifetime_days' => (int) env('CART_LIFETIME_DAYS', 30),

        'numbering' => [
            'prefix' => env('ORDER_NUMBER_PREFIX', 'ORD-'),
            'padding' => (int) env('ORDER_NUMBER_PADDING', 6),
        ],
    ],

    'tax' => [
        // 'none' or 'flat'. A module registers its own implementation.
        'driver' => env('TAX_DRIVER', 'none'),

        'flat' => [
            // A decimal string, never a float.
            'rate' => env('TAX_RATE', '0'),
            'name' => env('TAX_NAME', 'VAT'),
            'country' => env('TAX_COUNTRY'),
            'exempt_businesses_abroad' => (bool) env('TAX_EXEMPT_BUSINESSES_ABROAD', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provisioning
    |--------------------------------------------------------------------------
    |
    | A module that is not configured is never registered, so an operator
    | picking one on a product form is picking from the set that works.
    | `manual` is always available: "an operator sets it up" is always a
    | real answer.
    |
    */

    'provisioning' => [
        // Seconds. A control panel that hangs must not hold a worker.
        'timeout' => (int) env('PROVISIONING_TIMEOUT', 30),

        // Retries inside one attempt, on top of the queue's own tries.
        'retries' => (int) env('PROVISIONING_RETRIES', 2),

        // How many times a provisioning job is attempted before the
        // service is left in `failed` for a human.
        'job_tries' => (int) env('PROVISIONING_JOB_TRIES', 3),

        'modules' => [
            'cpanel' => [
                'enabled' => (bool) env('PROVISIONING_CPANEL_ENABLED', true),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | A registrar without credentials is never registered, so an operator
    | picking one on a TLD form is picking from the set that works.
    | `manual` is always available.
    |
    */

    'domains' => [
        // Seconds. A registry that hangs must not hold a worker.
        'timeout' => (int) env('DOMAINS_TIMEOUT', 30),

        // How many times a registration is attempted before the domain is
        // left in `failed` for a human.
        'job_tries' => (int) env('DOMAINS_JOB_TRIES', 3),

        // Seconds an availability answer is worth. Short: a search box asks
        // the same question five times in a minute, and a name that was
        // free an hour ago is not evidence of anything.
        'availability_ttl' => (int) env('DOMAINS_AVAILABILITY_TTL', 60),

        // What a new registration points at until the customer changes it.
        'default_nameservers' => array_values(array_filter(
            explode(',', (string) env('DOMAINS_DEFAULT_NAMESERVERS', '')),
        )),

        'registrars' => [
            'namecheap' => [
                'username' => env('NAMECHEAP_USERNAME'),
                'api_key' => env('NAMECHEAP_API_KEY'),
                // Namecheap allow-lists the calling address and rejects a
                // request without it in a way that reads like bad
                // credentials.
                'client_ip' => env('NAMECHEAP_CLIENT_IP'),
                'sandbox' => (bool) env('NAMECHEAP_SANDBOX', false),
                'api_base' => env('NAMECHEAP_API_BASE'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Which channels a message goes out on by default, and the webhook
    | endpoint an operator's own systems can listen on. A channel that is
    | not configured is never registered, so nothing tries to post to an
    | endpoint nobody has set.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | The languages this installation ships strings for. A notification
    | template exists per locale, so this is the list an operator is
    | offered when editing one.
    |
    */

    'locales' => array_values(array_filter(
        explode(',', (string) env('PLATFORM_LOCALES', 'en,tr')),
    )),

    'notifications' => [
        'channels' => array_values(array_filter(
            explode(',', (string) env('NOTIFICATION_CHANNELS', 'mail,database')),
        )),

        'webhook' => [
            'endpoint' => env('NOTIFICATION_WEBHOOK_URL'),
            // Signed the way this platform verifies incoming webhooks, so a
            // receiver can check before parsing and reject a replay.
            'secret' => env('NOTIFICATION_WEBHOOK_SECRET'),
            'timeout' => (int) env('NOTIFICATION_WEBHOOK_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Support
    |--------------------------------------------------------------------------
    */

    'support' => [
        'numbering' => [
            'prefix' => env('TICKET_NUMBER_PREFIX', 'TKT-'),
            'padding' => (int) env('TICKET_NUMBER_PADDING', 6),
        ],

        'attachments' => [
            // Never the public disk. Files are served by a controller that
            // checks who is asking.
            'disk' => env('TICKET_ATTACHMENT_DISK', 'local'),
            'max_kilobytes' => (int) env('TICKET_ATTACHMENT_MAX_KB', 5120),
            // Checked on extension *and* MIME. An attachment is the one
            // place a customer hands this platform bytes that staff open.
            'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'zip'],
            'allowed_mime_types' => [
                'image/png', 'image/jpeg', 'image/gif', 'image/webp',
                'application/pdf', 'text/plain', 'text/csv',
                'application/zip', 'application/x-zip-compressed',
            ],
        ],
    ],

    'risk' => [
        'enabled' => (bool) env('RISK_ENABLED', true),

        'rules' => [
            // Zero disables a rule. Nothing denies outright unless an
            // operator sets deny_score, because an untuned installation
            // should hold an order rather than turn a customer away.
            'high_value_minor' => (int) env('RISK_HIGH_VALUE_MINOR', 0),
            'high_value_weight' => 2,
            'new_account_days' => (int) env('RISK_NEW_ACCOUNT_DAYS', 0),
            'new_account_weight' => 1,
            'flag_first_order' => (bool) env('RISK_FLAG_FIRST_ORDER', false),
            'first_order_weight' => 1,
            'velocity_orders' => (int) env('RISK_VELOCITY_ORDERS', 0),
            'velocity_hours' => (int) env('RISK_VELOCITY_HOURS', 24),
            'velocity_weight' => 2,
            'failed_payments' => (int) env('RISK_FAILED_PAYMENTS', 0),
            'failed_payments_weight' => 2,
            'flag_country_mismatch' => (bool) env('RISK_FLAG_COUNTRY_MISMATCH', true),
            'country_mismatch_weight' => 1,
            'flag_unverified_email' => (bool) env('RISK_FLAG_UNVERIFIED_EMAIL', false),
            'unverified_email_weight' => 1,

            'review_score' => (int) env('RISK_REVIEW_SCORE', 2),
            'deny_score' => (int) env('RISK_DENY_SCORE', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Gateways are adapters behind a contract. A gateway with no credentials
    | is never registered, so an operator is offered only what actually
    | works rather than a method that fails at the till.
    |
    */

    'billing' => [
        'due_days' => (int) env('INVOICE_DUE_DAYS', 14),

        'numbering' => [
            'invoice_prefix' => env('INVOICE_NUMBER_PREFIX', 'INV-'),
            'proforma_prefix' => env('PROFORMA_NUMBER_PREFIX', 'PRO-'),
            'credit_note_prefix' => env('CREDIT_NOTE_NUMBER_PREFIX', 'CN-'),
            'padding' => (int) env('INVOICE_NUMBER_PADDING', 6),
        ],

        'gateways' => [
            'manual' => [
                'enabled' => (bool) env('GATEWAY_MANUAL_ENABLED', true),
                'instructions' => env('GATEWAY_MANUAL_INSTRUCTIONS', ''),
            ],

            'stripe' => [
                // Registered only when both keys are present.
                'secret' => env('STRIPE_SECRET'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
                'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
            ],
        ],
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
