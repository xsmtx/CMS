<?php

declare(strict_types=1);

return [
    'title' => 'Automation',
    'description' => 'What this platform does on its own, and what it did last time.',

    'tasks' => [
        'adapter_health' => [
            'label' => 'Adapter health',
            'description' => 'Asks every enabled adapter whether the system on the other end is still answering, at the pace that adapter declares.',
        ],
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
        'zone_health' => [
            'label' => 'Zone health',
            'description' => 'Asks every DNS source about the zones this installation holds, and raises what is wrong with them.',
        ],
        'reputation' => [
            'label' => 'Sending reputation',
            'description' => 'Asks every reputation source whether this installation’s own addresses are on a blocklist, and clears the ones that have been lifted.',
        ],
        'backups' => [
            'label' => 'Backup coverage',
            'description' => 'Asks every backup source what it is currently protecting, and retires what has left the job.',
        ],
        'reconcile' => [
            'label' => 'Reconciliation',
            'description' => 'Asks every provider what it thinks is true about each service, and records where it disagrees with us. It reports and never repairs.',
        ],
        'leakage' => [
            'label' => 'Revenue leakage',
            'description' => 'Asks four questions about this installation’s own rows: which active services, domains and addons nothing has invoiced, and which payments are attached to no invoice.',
        ],
        'sites' => [
            'label' => 'Site inventory',
            'description' => 'Asks every panel which web applications it is hosting, how far behind each one is, and whether anything has been published against a plugin or a theme.',
        ],
        'power' => [
            'label' => 'Power and environment',
            'description' => 'Asks every PDU which socket feeds which device, every UPS whether the mains is still there, and every rack sensor how warm the room is.',
        ],
        'usage' => [
            'label' => 'Usage metering',
            'description' => 'Asks every metering source what each service used last month, and writes a snapshot the next invoice quotes.',
        ],
        'machines' => [
            'label' => 'Machine inventory',
            'description' => 'Asks every hypervisor which hosts it has and which machines are on each of them.',
        ],
        'load_balancers' => [
            'label' => 'Load balancer inventory',
            'description' => 'Asks every load balancer what it is listening on and which machines are behind each listener.',
        ],
        'storage' => [
            'label' => 'Storage inventory',
            'description' => 'Asks every storage system what pools and volumes it is serving, and writes them into the resource graph.',
        ],
        'certificates' => [
            'label' => 'Certificates',
            'description' => 'Asks every certificate source what it currently has deployed, and retires what has stopped being served.',
        ],
        'abuse_retention' => [
            'label' => 'Abuse retention',
            'description' => 'Deletes abuse evidence that is past its retention deadline. The one task here whose job is to forget.',
        ],
        'alerts' => [
            'label' => 'Alerts',
            'description' => 'Asks every enabled rule whether it is true, raises what is wrong and clears what has stopped being wrong.',
        ],
        'ddos' => [
            'label' => 'Attacks',
            'description' => 'Asks every DDoS source what it has seen, and works out whose service was behind the address.',
        ],
        'access_grants' => [
            'label' => 'Temporary access',
            'description' => 'Writes down that a just-in-time grant has run out. Nothing depends on it running: the gate asks the grant itself.',
        ],
        'topology' => [
            'label' => 'Topology',
            'description' => 'Asks every network device what it is, and records its ports and VLANs on the graph.',
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
        'task' => 'Task',
        'cadence' => 'Runs',
        'result' => 'Result',
        'dunning_link' => 'Unpaid invoice sequence',
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
        'remove' => 'Remove',
        'remove_title' => 'Remove this step?',
        'remove_detail' => ':step will no longer happen. Invoices already past that point are not chased again for it.',
        'choose_event' => 'Choose a message',
        'add_step' => 'Add',
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
    'operations' => [
        'title' => 'Operations',
        'subtitle' => 'Long-running work, while it is running and after it has stopped.',
        'needs_attention' => 'Needs attention',
        'subject' => 'Client / service',
        'type' => 'Module / action',
        'error' => 'Failure reason',
        'attempt' => 'Attempt',
        'started' => 'Started',
        'next_attempt' => 'next :when',
        'retry' => 'Retry',
        'resolve' => 'Mark solved',
        'empty' => 'Nothing needs attention',
        'empty_description' => 'Provisioning, registrations, transfers and renewals appear here as they happen.',
    ],
];
