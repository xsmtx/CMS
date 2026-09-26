<?php

declare(strict_types=1);

return [
    'alerts' => [
        'title' => 'Alerts',
        'intro' => 'What is currently wrong, and what stopped being wrong.',
        'empty' => 'Nothing is wrong',
        'empty_detail' => 'Nothing any rule asks about is currently true. An empty list here is the good outcome.',
        'no_rules' => 'No rules have been written',
        'no_rules_detail' => 'This installation ships none. A threshold somebody else chose is a threshold that wakes you at three in the morning for a fortnight and then gets turned off, so the first rule is yours.',
        'show_all' => 'Show everything',
        'show_open' => 'Show what is open',
        'columns' => [
            'subject' => 'What',
            'rule' => 'Rule',
            'severity' => 'Severity',
            'observed' => 'Reading',
            'state' => 'State',
            'since' => 'Since',
            'seen' => 'Seen',
        ],
    ],

    'rules' => [
        'title' => 'Alert rules',
        'intro' => 'What this installation considers worth saying something about.',
        'add' => 'Write a rule',
        'add_intro' => 'A question asked every minute. Nothing here reaches anybody unless it stays true.',
        'name' => 'Name',
        'subject' => 'About',
        'target' => 'Which one',
        'target_hint' => 'Leave empty to ask about every one of them.',
        'comparison' => 'When it is',
        'threshold' => 'Threshold',
        'threshold_hint' => 'A percentage for a ratio, a number of days for a capacity forecast.',
        'for_minutes' => 'For at least, in minutes',
        'for_minutes_hint' => 'Zero raises the moment it is true. A spike that lasts nine seconds is not an alert.',
        'severity' => 'Severity',
        'notify' => 'Tell somebody when it raises',
        'notify_hint' => 'Off keeps it on this screen and out of anybody evening.',
        'enabled' => 'Enabled',
        'note' => 'Note',
        'delete' => 'Delete rule…',
        'delete_title' => 'Delete this rule?',
        'delete_body' => 'The rule stops being asked. The alerts it already raised are kept, because what was true stays true.',
        'saved' => 'The rule is saved.',
        'deleted' => 'The rule is deleted.',
        'columns' => [
            'name' => 'Rule',
            'subject' => 'About',
            'threshold' => 'When',
            'severity' => 'Severity',
            'open' => 'Open now',
            'state' => 'State',
        ],
    ],

    /*
     * The three are distinguished by **when somebody deals with it**, which
     * is a question with an answer — unlike low/medium/high, where the
     * difference is a conversation nobody wants to have twice.
     *
     * The wording says so out loud, and it has to: `health.states.degraded`
     * is already "Worth a look", and a severity column reading the same words
     * as the reading column beside it makes two different scales look like
     * one.
     */
    'severities' => [
        'warning' => 'During the day',
        'critical' => 'Now',
        'emergency' => 'Customers affected',
    ],

    'alert_states' => [
        'raised' => 'Raised',
        'suppressed' => 'Held',
        'cleared' => 'Cleared',
    ],

    'subjects' => [
        'metric' => 'A measurement',
        'health_check' => 'A health check',
        'adapter_health' => 'An adapter',
        'automation_run' => 'An automation task',
        'failed_operation' => 'A failed operation',
        'capacity' => 'Something running out',
    ],

    'comparisons' => [
        'above' => 'above',
        'below' => 'below',
    ],

    'observed' => [
        'late' => 'Last run :at',
        'failed_items' => ':count failed',
        'days_left' => ':days days left',
    ],
];
