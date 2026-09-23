<?php

declare(strict_types=1);

return [
    'title' => 'Operations',
    'description' => 'Long-running work, while it is running and after it has stopped.',

    'none' => 'Nothing running',
    'none_description' => 'Provisioning, registrations, transfers and renewals appear here as they happen.',

    'states' => [
        'pending' => 'Waiting',
        'running' => 'Running',
        'retrying' => 'Retrying',
        'failed' => 'Failed',
        'manual_intervention' => 'Needs a person',
        'completed' => 'Done',
    ],

    'types' => [
        'service_provision' => 'Set up service',
        'service_suspend' => 'Suspend service',
        'service_unsuspend' => 'Unsuspend service',
        'service_terminate' => 'Terminate service',
        'service_change_package' => 'Change package',
        'service_sync' => 'Sync service',
        'domain_register' => 'Register domain',
        'domain_transfer' => 'Transfer domain',
        'domain_renew' => 'Renew domain',
        'domain_sync' => 'Sync domain',
    ],

    'attempt' => 'Attempt :attempt of :max',
    'next_attempt' => 'Next attempt :time',
    'needs_attention' => 'Needs attention',
    'retry' => 'Try again',
    'resolve' => 'Mark as handled',
    'retried' => 'The operation has been queued again.',
    'resolved' => 'Marked as handled.',
    'resolved_at' => 'Handled :time',
    'cannot_retry' => 'This operation cannot be retried from here.',
    'started' => 'Started',
    'finished' => 'Finished',
    'error' => 'What went wrong',

    'errors' => [
        'not_permitted' => 'You do not have permission to do that.',
    ],
];
