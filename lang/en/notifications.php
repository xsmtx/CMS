<?php

declare(strict_types=1);

return [
    'events' => [
        'order_placed' => 'Order placed',
        'order_paid' => 'Order paid',
        'invoice_issued' => 'Invoice issued',
        'payment_received' => 'Payment received',
        'payment_failed' => 'Payment failed',
        'service_provisioned' => 'Service set up',
        'service_suspended' => 'Service suspended',
        'service_terminated' => 'Service terminated',
        'domain_registered' => 'Domain registered',
        'domain_expiring' => 'Domain expiring',
        'ticket_opened' => 'Ticket opened',
        'ticket_replied' => 'Ticket replied',
    ],

    'categories' => [
        'invoices' => 'Billing',
        'support' => 'Support',
        'product' => 'Service updates',
        'marketing' => 'Offers and news',
    ],

    'channels' => [
        'mail' => 'Email',
        'database' => 'In-app',
        'webhook' => 'Webhook',
    ],

    'delivery_statuses' => [
        'pending' => 'Pending',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'suppressed' => 'Not sent',
    ],

    'mail' => [
        'signature' => 'Thanks, :brand',
    ],

    'admin' => [
        'templates_title' => 'Notification templates',
        'templates_subtitle' => 'What each message says. Editing one here changes it for every customer.',
        'log_title' => 'Delivery log',
        'log_subtitle' => 'Everything this platform has sent, and what became of it.',
        'event' => 'Message',
        'locale' => 'Language',
        'subject' => 'Subject',
        'body' => 'Body',
        'action_label' => 'Button label',
        'channel' => 'Channel',
        'status' => 'Status',
        'recipient' => 'Recipient',
        'sent_at' => 'Sent',
        'error' => 'Reason',
        'placeholders' => 'Placeholders',
        'placeholders_hint' => 'Write them as :name. One with no value is left as itself rather than blanked, so a mistake is visible.',
        'preview' => 'Preview',
        'send_test' => 'Send a test to myself',
        'test_sent' => 'Test message sent.',
        'saved' => 'Template saved.',
        'reset' => 'Reset to the shipped wording',
        'reset_done' => 'Template reset.',
        'customised' => 'Edited',
        'shipped' => 'As shipped',
        'empty_log' => 'Nothing has been sent yet.',
        'not_permitted' => 'You do not have access to notifications.',
    ],

    'portal' => [
        'title' => 'Notifications',
        'none' => 'Nothing new.',
        'none_description' => 'Updates about your services, invoices and tickets appear here.',
        'mark_read' => 'Mark all as read',
        'preferences' => 'What we email you about',
        'preferences_hint' => 'Some messages are part of the service — a failed payment, a suspension — and are always sent.',
        'always_sent' => 'Always sent',
    ],

    'messages' => [
        'order_placed' => [
            'subject' => 'We have your order :order_number',
            'body' => "Thanks for your order.\n\nOrder :order_number for :total is with us. We will let you know as soon as it is set up.",
            'action' => 'View your order',
        ],
        'order_paid' => [
            'subject' => 'Payment received for order :order_number',
            'body' => "Thanks — we have received :total for order :order_number.\n\nWe are setting things up now and will write again when they are ready.",
            'action' => 'View your order',
        ],
        'invoice_issued' => [
            'subject' => 'Invoice :invoice_number',
            'body' => "Invoice :invoice_number for :total is ready.\n\nIt is due on :due_date. You can pay it from your account whenever you are ready.",
            'action' => 'Pay this invoice',
        ],
        'payment_received' => [
            'subject' => 'Payment received',
            'body' => 'We have received :amount towards invoice :invoice_number. Thank you.',
            'action' => 'View the invoice',
        ],
        'payment_failed' => [
            'subject' => 'Your payment did not go through',
            'body' => "We could not take :amount for invoice :invoice_number.\n\n:reason\n\nNothing has been charged. You can try again from your account.",
            'action' => 'Try again',
        ],
        'service_provisioned' => [
            'subject' => ':service_name is ready',
            'body' => ":service_name is set up and working.\n\nYour sign-in details are in your account — we do not send passwords by email.",
            'action' => 'Open your service',
        ],
        'service_suspended' => [
            'subject' => ':service_name has been suspended',
            'body' => ":service_name is suspended.\n\n:reason\n\nGet in touch and we will sort it out.",
            'action' => 'View your service',
        ],
        'service_terminated' => [
            'subject' => ':service_name has been terminated',
            'body' => ':service_name has been terminated and its data removed.',
        ],
        'domain_registered' => [
            'subject' => ':domain is registered',
            'body' => ":domain is registered to you until :expires_on.\n\nYou can point it wherever you like from your account.",
            'action' => 'Manage the domain',
        ],
        'domain_expiring' => [
            'subject' => ':domain expires on :expires_on',
            'body' => ":domain expires on :expires_on.\n\nRenew it before then to keep it — once a domain lapses, anybody can register it.",
            'action' => 'Renew the domain',
        ],
        'ticket_opened' => [
            'subject' => '[:ticket_number] :subject',
            'body' => "We have your message and somebody will reply shortly.\n\nTicket :ticket_number — :subject",
            'action' => 'View the ticket',
        ],
        'ticket_replied' => [
            'subject' => 'Re: [:ticket_number] :subject',
            'body' => 'There is a new reply on ticket :ticket_number.',
            'action' => 'Read the reply',
        ],
    ],

    'errors' => [
        'opted_out' => 'The recipient has switched this kind of message off.',
        'no_address' => 'The recipient has no email address.',
        'no_subject' => 'This channel needs somebody to address the message to.',
        'endpoint_refused' => 'The endpoint answered with HTTP :status.',
    ],
];
