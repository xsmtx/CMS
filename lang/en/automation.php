<?php

declare(strict_types=1);

return [
    'title' => 'Automation',
    'description' => 'What this platform does on its own, and what it did last time.',

    'tasks' => [
        'renewals' => [
            'label' => 'Renewal invoices',
            'description' => 'Raises the invoice for every service and domain about to renew.',
        ],
        'dunning' => [
            'label' => 'Unpaid invoices',
            'description' => 'Works through the reminder sequence for invoices nobody has paid.',
        ],
        'overdue' => [
            'label' => 'Overdue',
            'description' => 'Marks issued invoices past their due date as overdue.',
        ],
        'domain_expiry' => [
            'label' => 'Domain expiry',
            'description' => 'Warns owners before a domain lapses.',
        ],
        'retries' => [
            'label' => 'Retries',
            'description' => 'Puts operations that are waiting to be retried back in the queue.',
        ],
        'sync' => [
            'label' => 'Provider sync',
            'description' => 'Asks control panels and registrars what they think is true.',
        ],
        'resources' => [
            'label' => 'Resource graph',
            'description' => 'Keeps the graph in step with organizations, servers and services.',
        ],
        'telemetry' => [
            'label' => 'Telemetry',
            'description' => 'Asks every enabled monitoring adapter what it currently knows.',
        ],
        'webhooks' => [
            'label' => 'Webhook deliveries',
            'description' => 'Retries the deliveries an endpoint has not accepted yet.',
        ],
        'licence' => [
            'label' => 'Licence heartbeat',
            'description' => 'Tells the vendor this installation is alive, and reads back what it may do.',
        ],
        'cleanup' => [
            'label' => 'Cleanup',
            'description' => 'Deletes expired carts, read notifications and old run detail.',
        ],
    ],

    'status' => [
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Could not finish',
    ],

    'outcome' => [
        'changed' => 'Changed',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
    ],

    'runs' => [
        'title' => 'Run history',
        'none' => 'Nothing has run yet',
        'none_description' => 'Tasks run on a schedule. You can also run one now and watch what it does.',
        'examined' => 'Examined',
        'changed' => 'Changed',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
        'started' => 'Started',
        'duration' => 'Took',
        'run_now' => 'Run now',
        'queued' => 'The task has been run. Its result is in the history below.',
        'never' => 'Never run',
        'last_run' => 'Last run',
        'every' => 'Every :minutes minutes',
        'daily' => 'Once a day',
        'nothing_to_do' => 'Nothing to do',
        'detail' => 'What it touched',
    ],

    'dunning' => [
        'title' => 'Unpaid invoice sequence',
        'description' => 'What happens, and when, to an invoice nobody has paid. A sequence with no suspend step is a valid choice.',
        'none' => 'No sequence configured',
        'none_description' => 'Until a step exists, an unpaid invoice is chased by nobody.',
        'add' => 'Add a step',
        'offset' => 'Days',
        'offset_hint' => 'Negative is before the due date, positive is after.',
        'action' => 'Action',
        'event' => 'Message',
        'before_due' => ':days days before due',
        'after_due' => ':days days after due',
        'on_due' => 'On the due date',
        'saved' => 'The sequence has been saved.',
        'removed' => 'The step has been removed.',
        'actions' => [
            'late_fee' => 'Charge a late fee',
            'notify' => 'Send a message',
            'suspend' => 'Suspend the services',
            'terminate' => 'Terminate the services',
        ],
        'suspended_reason' => 'Suspended for non-payment',
    ],

    'maintenance' => [
        'title' => 'Maintenance mode',
        'description' => 'Closes the storefront and the client area. Staff can still sign in.',
        'message' => 'Message shown to visitors',
        'until' => 'Ends at',
        'until_hint' => 'Leave empty to leave it on until you turn it off.',
        'enable' => 'Turn on',
        'disable' => 'Turn off',
        'enabled' => 'Maintenance mode is on.',
        'disabled' => 'Maintenance mode is off.',
        'active_since' => 'On since :time',
        'default_message' => 'We are carrying out maintenance and will be back shortly.',
    ],

    'errors' => [
        'not_permitted' => 'You do not have permission to do that.',
        'unknown_task' => 'There is no such task.',
    ],
];
