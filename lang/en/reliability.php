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
        'certificate_expiry' => 'How long a certificate has left',
        'reputation_listing' => 'How long one of our addresses has been blocklisted',
        'backup_age' => 'How old the last good backup is',
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
        'days_listed' => 'listed :days days',
        'days_since_backup' => 'last good copy :days days old',
    ],

    /*
     * Incidents (§15).
     *
     * The four states are statuspage.io's, deliberately: an operator who has
     * run anything knows what "identified" implies about the next update, and
     * a customer has seen these words on every status page they have looked
     * at. Inventing a private language during an outage helps nobody.
     */
    'incident_states' => [
        'investigating' => 'Investigating',
        'identified' => 'Identified',
        'monitoring' => 'Monitoring',
        'resolved' => 'Resolved',
    ],

    'incidents' => [
        'title' => 'Incidents',
        'intro' => 'What went wrong, what was said about it, and what was underneath.',
        'empty' => 'Nothing is going wrong',
        'empty_detail' => 'No incident is open. An empty list here is the good outcome.',
        'open' => 'Open an incident',
        // The submit button, not the heading above it: with the toggle, the
        // heading and the button all reading "Open an incident", the page says
        // one thing three times and none of them says what pressing does.
        'open_it' => 'Open it',
        'open_intro' => 'Declaring that something is wrong. An alert is a machine noticing; this is a person saying so.',
        'incident_title' => 'What is wrong',
        'first_update' => 'What is known so far',
        'first_update_hint' => 'The timeline starts here, and a postmortem is written from the timeline.',
        'severity' => 'How urgent',
        'started_at' => 'When it started',
        'started_at_hint' => "When the customer's world broke, which is usually earlier than anybody noticed. Leave empty for now.",
        'is_public' => 'Show it on the status page',
        'is_public_hint' => 'Off by default. An incident is public because somebody said so.',
        'opened' => 'The incident is open.',
        'noted' => 'The update is posted.',
        'attached' => 'The alert is attached.',
        'detached' => 'The alert is detached.',
        'about' => 'About this incident',
        'detected' => 'Noticed',
        'opened_by' => 'Opened by',
        'timeline' => 'Timeline',
        'timeline_intro' => 'Append-only. What was believed at half past two is what a postmortem is written from.',
        'post_update' => 'Post an update',
        // The button, not the heading: a button labelled with the section
        // above it says nothing, and one reading "Post an update" that opens
        // a Resolve dialog is a surprise.
        'post' => 'Post it',
        'resolve' => 'Resolve the incident',
        'resolve_title' => 'Resolve this incident?',
        'resolve_body' => 'The incident ends and what was underneath it is worked out and frozen — that figure is what somebody quotes weeks later, so it is taken now rather than recomputed from a graph that has moved. Nothing reopens a resolved incident; a new one is opened instead.',
        'update_body' => 'What is happening',
        'update_state' => 'Where it is now',
        'update_public' => 'Publish this update',
        'internal' => 'Internal',
        'published' => 'Published',
        'alerts' => 'Alerts',
        'alerts_intro' => 'The machine observations this incident is about. The impact is computed from these.',
        'attach' => 'Attach',
        'detach' => 'Detach',
        'no_alerts' => 'No alerts are attached. Half of all incidents start with a phone call, so this is a normal thing to see.',
        'unattached' => 'Open alerts nobody has attached',
        'impact' => 'What was underneath',
        'impact_intro' => 'Frozen when the incident was resolved. The graph moves, and an impact recomputed in March is not the one anybody acted on.',
        'impact_pending' => 'Computed when the incident is resolved.',

        'postmortem' => 'Postmortem',
        'postmortem_intro' => 'Written from the timeline, once it has ended. The one thing here that may be rewritten — the timeline is evidence and this is a conclusion.',
        'postmortem_body' => 'What happened, and what changes because of it',
        'postmortem_hint' => 'Internal. A customer-facing version is a final public update on the timeline.',
        'postmortem_save' => 'Save it',
        'postmortem_saved' => 'The postmortem is saved.',
        'postmortem_pending' => 'Written once the incident has ended.',
        'postmortem_written' => 'Last written :at.',

        'credits' => 'Who was affected',
        'credits_intro' => 'Worked out from the alerts attached to this incident. Every monitoring system can say a server was down; this is the part only this platform knows.',
        'credits_none' => 'No services could be traced from the attached alerts, so there is nobody to credit from here. Attach an alert about the machine, or raise the credit note on the invoice itself.',
        'credits_pending' => 'Shown once the incident has ended.',
        'credit_customer' => 'Customer',
        'credit_affected' => 'Services affected',
        'credit_invoice' => 'Which invoice',
        'credit_amount' => 'How much to credit',
        'credit_amount_hint' => 'The amount is yours. This platform has never read your SLA and will not invent a percentage on your behalf.',
        'credit_reason' => 'What the customer reads',
        'credit_reason_hint' => 'Copied onto the credit note, which is a document they receive. An incident reference means nothing to them.',
        'credit' => 'Credit them',
        'credited' => 'The credit note is raised.',
        'credited_already' => 'Credited :amount on :at.',
        'credit_no_invoice' => 'No issued invoice to credit.',
        'services' => 'Services',
        'customers' => 'Customers',
        'recurring' => 'Recurring value',
        'duration' => 'Lasted',
        'still_going' => 'Still going',
        'detected_after' => 'Noticed :duration after it started',
        'show_all' => 'Show everything',
        'show_open' => 'Show what is open',
        'columns' => [
            'reference' => 'Incident',
            'state' => 'State',
            'severity' => 'Urgency',
            'started' => 'Started',
            'duration' => 'Lasted',
            'alerts' => 'Alerts',
            'customers' => 'Customers',
            'recurring' => 'Recurring',
        ],
    ],

    /*
     * The public status page (§15).
     *
     * Customer vocabulary, not operator vocabulary: the levels are about the
     * *service* — what somebody standing outside can expect — rather than
     * about how urgently an engineer deals with it. Printing "Customers
     * affected" as the headline of a public page would be the storefront's
     * old mistake through a new door.
     */
    'status_page' => [
        'title' => 'Service status',
        'levels' => [
            'operational' => 'All systems operational',
            'disrupted' => 'Some systems are affected',
            'outage' => 'A major outage is in progress',
        ],
        'checked_at' => 'As of :at.',
        'happening_now' => 'Happening now',
        'history' => 'Past incidents',
        'no_history' => 'Nothing has gone wrong in the last :days days.',
        'maintenance' => 'Planned maintenance',
        'maintenance_running' => 'Happening now',
        'maintenance_planned' => 'Planned',
        'started' => 'Started :at.',
        'ran' => 'From :from to :to.',
    ],

    /*
     * Planned work (§16).
     *
     * The states are worded from where the window is in its own life, not
     * from a column: there is no stored state, and a window that started ten
     * seconds ago says so.
     */
    'maintenance' => [
        'title' => 'Maintenance',
        'intro' => 'Work somebody planned. While a window is running, the alerts it causes are still raised and recorded — nobody is woken by them.',
        'states' => [
            'scheduled' => 'Scheduled',
            'active' => 'Running now',
            'ended' => 'Over',
            'cancelled' => 'Called off',
        ],
        'plan' => 'Plan a window',
        // The submit button, not the heading above it and not the toggle
        // that revealed the form — all three said the same three words.
        'plan_it' => 'Plan it',
        'plan_intro' => 'What is being done, when, and whether customers are told.',
        'window_title' => 'What is being done',
        'body' => 'What customers should know',
        'body_hint' => 'Shown on the status page when the window is published. Left empty, only the title and the times are.',
        'starts_at' => 'Starts',
        'ends_at' => 'Ends',
        'nodes' => 'Which machines',
        'nodes_hint' => 'One node key per line. Leave it empty for the whole installation, which is what a network or power maintenance is.',
        'is_public' => 'Show it on the status page',
        'is_public_hint' => 'Off by default. Announcing planned work is a decision somebody makes.',
        'scheduled_flash' => 'The window is planned.',
        'cancelled_flash' => 'The window is called off.',
        'cancel' => 'Call it off',
        'cancel_title' => 'Call off this window?',
        'cancel_body' => 'The record stays and says it was planned and then called off — which is a different thing from never having warned anybody. Alerts stop being held the moment it is cancelled.',
        'empty' => 'Nothing is planned',
        'empty_detail' => 'No maintenance window has been recorded. One planned here holds back the alerts the work itself causes, without hiding them.',
        'everywhere' => 'Everywhere',
        'suppressed' => ':count held',
        'columns' => [
            'window' => 'Window',
            'state' => 'State',
            'when' => 'When',
            'scope' => 'Machines',
            'held' => 'Alerts held',
        ],
    ],

    'calendar' => [
        'title' => 'Operations calendar',
        'intro' => 'What happened and what is coming: incidents and planned work on the same month.',
        'previous' => 'Earlier month',
        'next' => 'Later month',
        'maintenance' => 'Maintenance',
        'ongoing' => 'already running',
        'empty' => 'Nothing this month',
        'empty_detail' => 'No incident and no planned work. An empty month here is the good outcome.',
    ],
];
