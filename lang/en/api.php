<?php

declare(strict_types=1);

return [
    'title' => 'Developer',
    'description' => 'Tokens, webhooks and what this installation has been asked for.',

    'tokens' => [
        'title' => 'API tokens',
        'description' => 'A token is a password that never gets typed. Give each integration its own, and only the scopes it needs.',
        'name' => 'What is it for',
        'expires' => 'Expires',
        'expires_hint' => 'A token with no end date outlives the reason it was issued.',
        'scopes' => 'What it may do',
        'scopes_hint' => 'A scope narrows; it never grants. A token can never do more than the person who issued it.',
        'create' => 'Issue a token',
        'created' => 'Copy this token now. It is not shown again.',
        'revoke' => 'Revoke',
        'revoked' => 'The token has been revoked.',
        'last_used' => 'Last used',
        'never_used' => 'Never used',
        'none' => 'No tokens',
        'none_description' => 'Nothing is using the API on this account.',
        'no_scopes' => 'No scopes chosen — this token can reach nothing.',
    ],

    'scopes' => [
        'profile_read' => [
            'label' => 'Read the account',
            'description' => 'The contact and customer this token belongs to.',
        ],
        'profile_write' => [
            'label' => 'Change the account',
            'description' => 'Update contact details.',
        ],
        'services_read' => [
            'label' => 'Read services',
            'description' => 'List hosting accounts and their status.',
        ],
        'services_write' => [
            'label' => 'Suspend and unsuspend services',
            'description' => 'Not terminate. Destroying an account is never something a token does unattended.',
        ],
        'domains_read' => [
            'label' => 'Read domains',
            'description' => 'List domains, their expiry and their nameservers.',
        ],
        'domains_write' => [
            'label' => 'Change domains',
            'description' => 'Set nameservers and renew.',
        ],
        'invoices_read' => [
            'label' => 'Read invoices',
            'description' => 'Invoices, their lines and what is outstanding. Paying is not possible with a token.',
        ],
        'orders_read' => [
            'label' => 'Read orders',
            'description' => 'Orders and what was on them.',
        ],
        'tickets_read' => [
            'label' => 'Read tickets',
            'description' => 'Support conversations, excluding internal notes.',
        ],
        'tickets_write' => [
            'label' => 'Open and answer tickets',
            'description' => 'For a monitoring system that reports a problem before a person notices.',
        ],
        'webhooks_read' => [
            'label' => 'Read webhooks',
            'description' => 'Endpoints and what was delivered to them.',
        ],
        'webhooks_write' => [
            'label' => 'Manage webhooks',
            'description' => 'Add and remove endpoints, and redeliver an event.',
        ],
    ],

    'webhooks' => [
        'title' => 'Webhooks',
        'description' => 'Where this platform posts events as they happen.',
        'url' => 'Endpoint URL',
        'url_hint' => 'HTTPS only. We post signed JSON and never follow a redirect.',
        'what_for' => 'What is it for',
        'events' => 'Events',
        'events_hint' => 'Choose nothing to receive everything.',
        'create' => 'Add an endpoint',
        'created' => 'Copy this signing secret now. It is not shown again.',
        'secret' => 'Signing secret',
        'delete' => 'Remove',
        'deleted' => 'The endpoint has been removed.',
        'none' => 'No endpoints',
        'none_description' => 'Nothing is being told when something happens on this account.',
        'last_delivered' => 'Last delivered',
        'failures' => 'Consecutive failures',
        'disabled' => 'Switched off after too many failures',
        'deliveries' => 'Deliveries',
        'redeliver' => 'Send again',
        'redelivered' => 'Queued to be sent again.',
        'verify_title' => 'Verifying a delivery',
        'verify_body' => 'Each request carries X-InfraCMS-Timestamp and X-InfraCMS-Signature. The signature is HMAC-SHA256 of "<timestamp>.<raw body>" using your signing secret. Compare it against the exact bytes you received, before parsing, and reject anything older than five minutes.',
    ],

    'deliveries' => [
        'states' => [
            'pending' => 'Waiting',
            'delivered' => 'Delivered',
            'failed' => 'Failed',
            'retrying' => 'Retrying',
        ],
    ],

    'activity' => [
        'title' => 'API activity',
        'description' => 'Every request this installation was asked for. Never the body of one.',
        'none' => 'Nothing has called the API',
        'none_description' => 'Requests appear here as soon as an integration starts.',
        'token' => 'Token',
        'route' => 'Route',
        'status' => 'Status',
        'duration' => 'Took',
        'when' => 'When',
        'refused_only' => 'Refused only',
        'all' => 'All',
    ],

    'errors' => [
        'unauthenticated' => 'No valid API token was presented.',
        'forbidden' => 'This token may not do that.',
        'scope_missing' => 'This token does not carry the :scope scope, or its owner is not permitted to use it.',
        'rate_limited' => 'Too many requests. Wait and try again.',
        'unknown_filter' => 'There is no filter called :field.',
        'filterable' => 'Filters available here: :fields.',
        'unknown_sort' => 'There is no sortable field called :field.',
        'sortable' => 'Fields you can sort by: :fields.',
        'unknown_action' => 'There is no action called :action.',
        'allowed_actions' => 'Actions available here: :actions.',
        'unknown_department' => 'There is no such department.',
        'idempotency_conflict' => 'This idempotency key was already used for a different request.',
        'idempotency_in_flight' => 'A request with this idempotency key is still being processed.',
        'https_required' => 'A webhook endpoint must be an HTTPS URL.',
        'endpoint_gone' => 'The endpoint no longer exists.',
    ],
];
