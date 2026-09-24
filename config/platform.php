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
            // The licence key, and the token it buys. `token` already catches
            // the second; the first is named because `license_key` contains
            // neither `secret` nor `token` and would otherwise go to the log
            // in full.
            'license_key',
            'licence_key',
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
        /*
         * 'rules', 'flat' or 'none'. A module registers its own implementation.
         *
         * `rules` is the default and means the rows an operator maintains on
         * Setup → Tax (ADR 0045). With no rows it charges nothing, so a fresh
         * installation behaves exactly as it did when the only option was a
         * flat rate in this file. `flat` is kept for an installation that
         * already set it here.
         */
        'driver' => env('TAX_DRIVER', 'rules'),

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

    /*
    |--------------------------------------------------------------------------
    | Automation
    |--------------------------------------------------------------------------
    |
    | Every one of these is a question about state, so the numbers change
    | what is asked rather than when. Retentions are generous on purpose: a
    | cleanup that deleted something somebody wanted is not undone by a
    | shorter run next time.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    |
    | Rate limits are per token, with a per-IP fallback for requests that
    | never got as far as a token. Writes are limited harder than reads
    | because a runaway loop that only reads is a nuisance and one that
    | writes is an incident.
    |
    | Idempotency records are kept long enough to cover a client's retry
    | window and no longer: they hold a response body, which is customer
    | data, and keeping them for a year would be keeping a copy of the API's
    | output forever.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | What this installation calls itself to a theme. A theme declares the
    | range it was built for and is refused outside it — a theme written
    | against last year's template variables renders a broken page, and the
    | operator who upgraded yesterday will blame the upgrade.
    |
    | Never shown on a public page: an unauthenticated visitor learning the
    | version is reconnaissance.
    |
    */

    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | A brand is a row, not configuration (ADR 0036). What is left here is
    | the default an unbranded installation shows, and the one place that
    | still reads `app.name`.
    |
    */

    'branding' => [
        'vendor_mark' => env('PLATFORM_VENDOR_MARK', 'Powered by InfraCMS'),
        'vendor_url' => env('PLATFORM_VENDOR_URL', 'https://infracms.test'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Help
    |--------------------------------------------------------------------------
    |
    | Where this installation sends its operators for help. Every link is
    | configurable and any of them may be blank: a white-label installation
    | points its staff at its own documentation, not at ours, and a menu
    | offering a link nobody set is a menu that lies.
    |
    */

    'help' => [
        'documentation' => env('PLATFORM_HELP_DOCUMENTATION'),
        // Where an operator reports something broken, and where they write
        // to a human. Both sit in the footer of every screen, because the
        // moment somebody needs them is the moment they are looking at the
        // thing that went wrong.
        'bug' => env('PLATFORM_HELP_BUG'),
        'contact' => env('PLATFORM_HELP_CONTACT'),
        'support' => env('PLATFORM_HELP_SUPPORT'),
        'community' => env('PLATFORM_HELP_COMMUNITY'),
        'license' => env('PLATFORM_HELP_LICENSE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Where packages live and whether this installation will load them at
    | all. `enabled => false` is a real setting: an installation that has
    | decided no third-party code runs on it says so once, here, rather
    | than by an operator remembering not to press a button.
    |
    | Nothing on disk runs because it is on disk (ADR 0038). This path is
    | only where discovery looks.
    |
    */

    'modules' => [
        'enabled' => (bool) env('PLATFORM_MODULES_ENABLED', true),
        'path' => env('PLATFORM_MODULES_PATH', base_path('modules')),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM defaults
    |--------------------------------------------------------------------------
    |
    | What an operator in one country would otherwise retype all day. Only
    | defaults: nothing here restricts what can be entered.
    |
    */

    'crm' => [
        'default_country' => env('CRM_DEFAULT_COUNTRY', 'TR'),
        'default_currency' => env('CRM_DEFAULT_CURRENCY', 'TRY'),
        'phone_placeholder' => env('CRM_PHONE_PLACEHOLDER', '+90 501 234 56 78'),
    ],

    'api' => [
        'rate_limit' => [
            'per_minute' => (int) env('API_RATE_LIMIT', 120),
            'writes_per_minute' => (int) env('API_WRITE_RATE_LIMIT', 30),
            'anonymous_per_minute' => (int) env('API_ANONYMOUS_RATE_LIMIT', 20),
        ],

        'idempotency' => [
            'retain_hours' => (int) env('API_IDEMPOTENCY_RETAIN_HOURS', 24),
        ],

        'activity' => [
            'retain_days' => (int) env('API_ACTIVITY_RETAIN_DAYS', 30),
        ],

        'webhooks' => [
            'timeout' => (int) env('WEBHOOK_TIMEOUT', 10),
            'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 6),
            'retry_base_minutes' => (int) env('WEBHOOK_RETRY_BASE_MINUTES', 1),
            'retry_cap_minutes' => (int) env('WEBHOOK_RETRY_CAP_MINUTES', 360),
            // An endpoint that has failed this many times running is
            // switched off rather than posted to forever. A dead URL is a
            // slow denial of service against our own queue.
            'disable_after_failures' => (int) env('WEBHOOK_DISABLE_AFTER', 20),
            'retain_days' => (int) env('WEBHOOK_RETAIN_DAYS', 30),
        ],
    ],

    'automation' => [
        // How far ahead a renewal invoice is raised.
        'renewal_lead_days' => (int) env('RENEWAL_LEAD_DAYS', 14),

        // A domain is the one thing a customer can lose permanently by not
        // acting, so it is told more than once.
        'domain_expiry_windows' => [30, 7, 1],

        'sync_after_hours' => (int) env('PROVIDER_SYNC_AFTER_HOURS', 24),
        'sync_batch' => (int) env('PROVIDER_SYNC_BATCH', 50),

        'retention' => [
            'notifications' => (int) env('RETAIN_READ_NOTIFICATIONS_DAYS', 90),
            'tokens' => (int) env('RETAIN_EXPIRED_TOKENS_DAYS', 30),
            'run_items' => (int) env('RETAIN_RUN_DETAIL_DAYS', 90),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Background operations
    |--------------------------------------------------------------------------
    |
    | Retries are bounded and the backoff is exponential and capped. A
    | provider refusing a request for a reason that will not change is not
    | improved by asking it nine hundred more times.
    |
    */

    'operations' => [
        'max_attempts' => (int) env('OPERATION_MAX_ATTEMPTS', 3),
        'retry_base_minutes' => (int) env('OPERATION_RETRY_BASE_MINUTES', 5),
        'retry_cap_minutes' => (int) env('OPERATION_RETRY_CAP_MINUTES', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | A health page never returns a configuration value. These are the
    | thresholds at which a number stops being normal.
    |
    */

    'health' => [
        'queue_depth_warning' => (int) env('HEALTH_QUEUE_WARNING', 100),
        'queue_depth_critical' => (int) env('HEALTH_QUEUE_CRITICAL', 1000),
        'failed_jobs_warning' => (int) env('HEALTH_FAILED_JOBS_WARNING', 1),
        'failed_jobs_critical' => (int) env('HEALTH_FAILED_JOBS_CRITICAL', 25),
        // Twice the heartbeat interval, so one missed tick is not an alarm.
        'heartbeat_stale_minutes' => (int) env('HEALTH_HEARTBEAT_STALE_MINUTES', 15),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | `outbound_ports` is what an operator-supplied URL may connect to. Kept
    | short on purpose: a webhook endpoint on port 6379 is somebody asking this
    | platform to talk to their Redis, and an installation that genuinely needs
    | another port has to say so — which is the point.
    |
    | `csp_report_uri` is additive. Its absence never weakens the policy; the
    | policy is built first and the report directive appended.
    |
    */

    'security' => [
        'outbound_ports' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('OUTBOUND_PORTS', '80,443,8080,8443')),
        ))),

        'csp_report_uri' => env('CSP_REPORT_URI'),

        /*
         * How long a confirmed password counts for, in minutes.
         *
         * Fifteen. An operator confirming once per quarter-hour is following a
         * rule; one confirming per action is working around a rule, and the way
         * they work around it is a password in a text file — which is worse than
         * not having the check at all. Clamped to at least a minute, because
         * zero would mean the second thing.
         */
        'reauth_minutes' => env('REAUTH_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Import / migration
    |--------------------------------------------------------------------------
    |
    | Which Laravel database connection holds the legacy system. The importer
    | issues nothing but `select` against it, and the credentials live in the
    | operator's environment file next to their own database's — where such
    | things belong. This platform never stores them.
    |
    | A connection that is not configured means the source is not offered,
    | rather than offered and then refused.
    |
    */

    'import' => [
        'whmcs_connection' => env('IMPORT_WHMCS_CONNECTION', 'legacy'),
    ],

    'licensing' => [
        'api_url' => env('LICENSE_API_URL'),
        'key' => env('LICENSE_KEY'),
        'public_key_path' => env('LICENSE_PUBLIC_KEY_PATH'),

        /*
         * How long the last good answer keeps working when the licence server
         * cannot be reached, counted from the heartbeat deadline the vendor
         * set — not from now. A vendor who said "come back in seven days" and
         * a grace of thirty means a customer keeps working for thirty-seven.
         *
         * Generous on purpose. ADR 0013: a temporary outage of the licensing
         * service must never take a customer's production system down.
         */
        'grace_days' => env('LICENSE_GRACE_DAYS', 30),

        // Short. Nothing a customer is waiting for depends on this call.
        'timeout' => env('LICENSE_TIMEOUT', 10),
        'retries' => env('LICENSE_RETRIES', 2),
    ],

];
