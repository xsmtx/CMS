<?php

declare(strict_types=1);

return [
    'title' => 'System health',
    'description' => 'What is working, what is about to stop working, and what has stopped.',

    'states' => [
        'ok' => 'Healthy',
        'degraded' => 'Worth a look',
        'failing' => 'Failing',
    ],

    'checks' => [
        'database' => 'Database',
        'cache' => 'Cache',
        'queue' => 'Queue',
        'failed_jobs' => 'Failed jobs',
        'scheduler' => 'Scheduler',
        'mail' => 'Mail',
        'providers' => 'Servers',
    ],

    'database' => [
        'slow' => 'The database is answering, but slowly.',
    ],

    'cache' => [
        'not_readable' => 'The cache accepted a write and did not return it.',
    ],

    'queue' => [
        'busy' => 'More work is waiting than usual.',
        'backed_up' => 'The queue is backed up. Check that workers are running.',
    ],

    'jobs' => [
        'some_failed' => 'Some background jobs used up every attempt.',
        'many_failed' => 'A lot of background jobs have failed.',
    ],

    'scheduler' => [
        'never_seen' => 'The scheduler has not reported in yet.',
        'stale' => 'The scheduler has stopped reporting in. Nothing is running on its own.',
    ],

    'mail' => [
        'not_configured' => 'Mail is not going anywhere real. Messages are being written to a log.',
        'some_failed' => 'Some messages could not be delivered.',
        'failing' => 'Messages are not being delivered.',
    ],

    'providers' => [
        'some_down' => 'Some servers did not answer the last time we asked.',
        'all_down' => 'No server answered the last time we asked.',
    ],

    'runtime' => [
        'title' => 'This installation',
        'version' => 'Version',
        'php' => 'PHP',
        'environment' => 'Environment',
        'checked_at' => 'Checked',
    ],

    'measurements' => [
        'latency_ms' => 'Round trip',
        'failed' => 'Failed',
        'sent' => 'Sent',
        'total' => 'Total',
        'unreachable' => 'Unreachable',
        'minutes_ago' => 'Minutes ago',
    ],
];
